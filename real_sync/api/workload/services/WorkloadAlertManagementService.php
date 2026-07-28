<?php

declare(strict_types=1);

require_once __DIR__ . '/WorkloadPermissionScopeService.php';
require_once __DIR__ . '/WorkloadAnalyticsCacheService.php';
require_once dirname(__DIR__, 2) . '/admin/common.php';

final class WorkloadAlertManagementException extends RuntimeException
{
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

final class WorkloadAlertManagementService
{
    private PDO $pdo;
    private WorkloadPermissionScopeService $permissions;
    private WorkloadAnalyticsCacheService $cache;

    public function __construct(PDO $pdo, ?WorkloadAnalyticsCacheService $cache = null)
    {
        $this->pdo = $pdo;
        $this->permissions = new WorkloadPermissionScopeService($pdo);
        $this->cache = $cache ?? new WorkloadAnalyticsCacheService();
    }

    public function list(array $input, array $context): array
    {
        $scope = $this->permissions->resolve($context);
        $dateFrom = $this->date((string) ($input['date_from'] ?? $input['date'] ?? date('Y-m-d', strtotime('-6 days'))));
        $dateTo = $this->date((string) ($input['date_to'] ?? $input['date'] ?? date('Y-m-d')));
        if ($dateFrom > $dateTo || (new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->days > 365) {
            throw new WorkloadAlertManagementException('预警查询日期范围无效');
        }
        $page = max(1, (int) ($input['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($input['page_size'] ?? 30)));
        $where = ['event.business_date BETWEEN ? AND ?'];
        $params = [$dateFrom, $dateTo];
        $filters = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        foreach ([
            'store_id' => 'event.store_id',
            'staff_id' => 'event.staff_id',
        ] as $key => $column) {
            $value = (int) ($input[$key] ?? 0);
            if ($value > 0) {
                $where[] = "$column = ?";
                $params[] = $value;
                $filters[$key] = $value;
            }
        }
        foreach ([
            'role_code' => 'event.role_code',
            'metric_code' => 'event.metric_code',
            'status' => 'event.status',
            'severity' => 'event.severity',
            'rule_code' => 'event.rule_code',
        ] as $key => $column) {
            $value = strtolower(trim((string) ($input[$key] ?? '')));
            if ($value !== '') {
                $where[] = "$column = ?";
                $params[] = $value;
                $filters[$key] = $value;
            }
        }
        $source = strtolower(trim((string) ($input['source'] ?? '')));
        if ($source !== '') $filters['source'] = $source;
        $this->applyScope($scope, $where, $params);
        $from = ' FROM workload_alert_events event '
            . 'LEFT JOIN workload_alert_rules rule ON rule.id = (SELECT latest.id FROM workload_alert_rules latest '
            . 'WHERE latest.rule_code = event.rule_code AND latest.enabled = 1 ORDER BY latest.version_no DESC, latest.id DESC LIMIT 1) '
            . 'LEFT JOIN stores store ON store.id = event.store_id '
            . 'LEFT JOIN staffs staff ON staff.id = event.staff_id ';
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from . 'WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT event.*, store.name AS store_name, staff.name AS staff_name, '
            . 'rule.metric_type, rule.comparison_operator, rule.minimum_report_sample, rule.minimum_staff_sample, rule.version_no AS rule_version '
            . $from . 'WHERE ' . $whereSql . ' ORDER BY FIELD(event.status, \'open\', \'resolved\', \'inactive\'), '
            . 'FIELD(event.severity, \'critical\', \'warning\', \'info\'), event.business_date DESC, event.id DESC LIMIT ?, ?'
        );
        $stmt->execute([...$params, ($page - 1) * $pageSize, $pageSize]);
        $rows = array_map([$this, 'formatEvent'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        return [
            'list' => $rows,
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => (int) ceil($total / $pageSize)],
            'filters' => $filters,
            'permission_scope' => $scope,
            'source_scope' => $source !== '' ? [$source] : ['default_operating_sources'],
            'generated_at' => gmdate('c'),
        ];
    }

    public function resolve(int $eventId, string $comment, array $context): array
    {
        if ($eventId <= 0) throw new WorkloadAlertManagementException('预警事件 ID 无效');
        $comment = trim($comment);
        if ($comment === '' || mb_strlen($comment, 'UTF-8') > 500) {
            throw new WorkloadAlertManagementException('处理意见不能为空且不能超过 500 个字符');
        }
        $scope = $this->permissions->resolve($context);
        if ($scope['scope_type'] === 'staff') throw new WorkloadAlertManagementException('当前数据范围无权处理预警', 403);
        ensureAdminOperationLogsTable($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM workload_alert_events WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$eventId]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new WorkloadAlertManagementException('预警事件不存在', 404);
            $this->assertEventScope($scope, $before);
            if ((string) $before['status'] === 'resolved') {
                $this->pdo->commit();
                return $this->formatEvent($before) + ['idempotent' => true];
            }
            if ((string) $before['status'] !== 'open') throw new WorkloadAlertManagementException('仅开放中的预警可以处理', 409);
            $operatorStaffId = (int) ($context['staff_id'] ?? 0);
            $update = $this->pdo->prepare(
                "UPDATE workload_alert_events SET status = 'resolved', handled_by_staff_id = ?, handler_comment = ?, handled_at = NOW() WHERE id = ? AND status = 'open'"
            );
            $update->execute([$operatorStaffId, $comment, $eventId]);
            if ($update->rowCount() !== 1) throw new WorkloadAlertManagementException('预警状态已变化，请刷新后重试', 409);
            $afterStmt = $this->pdo->prepare('SELECT * FROM workload_alert_events WHERE id = ?');
            $afterStmt->execute([$eventId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            adminRecordOperation($this->pdo, ['user_id' => $context['user_id'] ?? null], ['id' => $operatorStaffId], [
                'module' => 'workload_alert',
                'action' => 'alert.resolve',
                'target_type' => 'workload_alert_event',
                'target_id' => (string) $eventId,
                'before' => $before,
                'after' => $after,
            ]);
            $this->pdo->commit();
            $cacheScope = [
                'date' => (string) $after['business_date'],
                'store_id' => (int) $after['store_id'],
                'staff_id' => (int) $after['staff_id'],
                'role_code' => (string) $after['role_code'],
                'metric_code' => (string) $after['metric_code'],
            ];
            $invalidated = $this->cache->invalidate($cacheScope);
            return $this->formatEvent($after) + [
                'idempotent' => false,
                'cache_invalidated' => $invalidated,
                'cache_invalidation_scope' => $cacheScope,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function applyScope(array $scope, array &$where, array &$params): void
    {
        if ($scope['scope_type'] === 'stores') {
            $ids = array_values(array_map('intval', $scope['store_ids']));
            $where[] = 'event.store_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        } elseif ($scope['scope_type'] === 'staff') {
            $where[] = 'event.staff_id = ?';
            $params[] = (int) $scope['staff_id'];
        }
    }

    private function assertEventScope(array $scope, array $event): void
    {
        if ($scope['scope_type'] === 'stores' && !in_array((int) $event['store_id'], array_map('intval', $scope['store_ids']), true)) {
            throw new WorkloadAlertManagementException('预警事件超出当前门店权限', 403);
        }
    }

    private function formatEvent(array $row): array
    {
        foreach (['id', 'store_id', 'staff_id', 'handled_by_staff_id', 'minimum_report_sample', 'minimum_staff_sample', 'rule_version'] as $field) {
            $row[$field] = isset($row[$field]) ? (int) $row[$field] : null;
        }
        foreach (['numerator', 'denominator', 'current_value', 'threshold_value'] as $field) $row[$field] = (float) ($row[$field] ?? 0);
        $row['evidence'] = json_decode((string) ($row['evidence_json'] ?? ''), true) ?: [];
        unset($row['evidence_json']);
        return $row;
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new WorkloadAlertManagementException('日期格式必须为 YYYY-MM-DD');
        return $value;
    }
}
