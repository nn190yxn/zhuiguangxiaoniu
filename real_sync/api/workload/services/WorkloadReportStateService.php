<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/common/context.php';
require_once __DIR__ . '/WorkloadAuditTaskService.php';
require_once __DIR__ . '/WorkloadDailySettlementService.php';
require_once __DIR__ . '/WorkloadConversionResultService.php';
require_once __DIR__ . '/WorkloadMakeupService.php';

final class WorkloadReportStateException extends RuntimeException {
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int {
        return $this->statusCode;
    }
}

final class WorkloadReportStateService {
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';

    private PDO $pdo;
    private WorkloadMakeupService $makeupService;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->makeupService = new WorkloadMakeupService($pdo);
    }

    public function assertEmployeeWritable(string $businessDate): array {
        $date = $this->normalizeBusinessDate($businessDate);
        $deadline = $date->modify('+1 day');
        $now = $this->databaseNow();
        if ($date->format('Y-m-d') > $now->format('Y-m-d')) {
            throw new WorkloadReportStateException('不能保存未来日期日报');
        }
        if ($date->format('N') === '1') {
            throw new WorkloadReportStateException('周一为统一公休日，日报填写入口已关闭', 409);
        }
        if ($now >= $deadline) {
            throw new WorkloadReportStateException('日报已于次日 00:00 锁定，请联系管理人员更正', 409);
        }
        return [
            'business_date' => $date->format('Y-m-d'),
            'deadline_at' => $deadline->format('Y-m-d 00:00:00'),
            'is_writable' => true,
        ];
    }

    public function synchronizeReport(int $reportId, bool $corrected = false): array {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('日报与义务同步必须在同一事务内执行');
        }
        $report = $this->lockReport($reportId);
        $status = (string) ($report['submit_status'] ?? '');
        if (!in_array($status, ['draft', 'submitted'], true)) {
            throw new WorkloadReportStateException('日报提交状态无效');
        }

        $date = (string) $report['report_date'];
        $storeId = (int) $report['store_id'];
        $staffId = (int) $report['staff_id'];
        $roleCode = (string) $report['role_code'];
        $normalizedRole = appRoleCode($roleCode);
        $obligation = $this->lockObligation($date, $storeId, $staffId, $normalizedRole);
        $isWeeklyRestDay = $this->normalizeBusinessDate($date)->format('N') === '1';
        $completionStatus = $corrected ? 'corrected' : $status;
        $completedAt = in_array($completionStatus, ['submitted', 'corrected'], true)
            ? ((string) ($report['submitted_at'] ?? '') ?: (string) ($report['updated_at'] ?? ''))
            : null;
        $deadlineAt = $this->normalizeBusinessDate($date)
            ->modify('+1 day')
            ->format('Y-m-d 00:00:00');

        if ($obligation) {
            $stmt = $this->pdo->prepare(
                'UPDATE workload_submission_obligations SET required_status = ?, report_id = ?, '
                . 'completion_status = ?, deadline_at = ?, completed_at = ?, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = ?'
            );
            $stmt->execute([
                $isWeeklyRestDay ? 'exempt' : 'required',
                $reportId,
                $completionStatus,
                $deadlineAt,
                $completedAt !== '' ? $completedAt : null,
                (int) $obligation['id'],
            ]);
            $obligationId = (int) $obligation['id'];
            $storedRoleCode = (string) $obligation['role_code'];
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO workload_submission_obligations '
                . '(obligation_date, store_id, staff_id, role_code, required_status, reason_code, report_id, '
                . 'completion_status, deadline_at, completed_at, source) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $date,
                $storeId,
                $staffId,
                $roleCode,
                $isWeeklyRestDay ? 'exempt' : 'required',
                $isWeeklyRestDay ? 'weekly_rest_day' : 'scheduled',
                $reportId,
                $completionStatus,
                $deadlineAt,
                $completedAt !== '' ? $completedAt : null,
                $corrected ? 'manual' : 'generated',
            ]);
            $obligationId = (int) $this->pdo->lastInsertId();
            $storedRoleCode = $roleCode;
        }

        return [
            'obligation_id' => $obligationId,
            'completion_status' => $completionStatus,
            'deadline_at' => $deadlineAt,
            'role_code' => $storedRoleCode,
        ];
    }

    public function lockExpired(?DateTimeImmutable $now = null): array {
        $now = $now
            ? $now->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE))
            : $this->databaseNow();
        $timestamp = $now->format('Y-m-d H:i:s');
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $lockMissing = $this->pdo->prepare(
                "UPDATE workload_submission_obligations SET completion_status = 'locked_missing', "
                . 'updated_at = CURRENT_TIMESTAMP WHERE required_status = ? AND completion_status = ? '
                . 'AND deadline_at <= ?'
            );
            $lockMissing->execute(['required', 'missing', $timestamp]);
            $missingCount = $lockMissing->rowCount();
            $lockMissing->execute(['required', 'draft', $timestamp]);
            $draftCount = $lockMissing->rowCount();

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return [
                'locked_at' => $timestamp,
                'locked_missing_count' => $missingCount,
                'locked_draft_count' => $draftCount,
                'locked_count' => $missingCount + $draftCount,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function stateForScope(string $date, int $storeId, int $staffId, string $roleCode): array {
        $businessDate = $this->normalizeBusinessDate($date);
        $deadline = $businessDate->modify('+1 day');
        $obligation = $this->findObligation(
            $businessDate->format('Y-m-d'),
            $storeId,
            $staffId,
            appRoleCode($roleCode),
            false
        );
        $isWeeklyRestDay = $businessDate->format('N') === '1';
        $now = $this->databaseNow();
        $isMakeupOpen = $this->makeupService->isMakeupDateAt($businessDate, $now);
        if ($isMakeupOpen) {
            $deadline = $this->makeupDeadline($businessDate);
        }
        $completionStatus = $obligation
            ? (string) $obligation['completion_status']
            : ($isWeeklyRestDay ? 'exempt' : 'missing');
        if (
            !$isWeeklyRestDay
            && in_array($completionStatus, ['missing', 'draft'], true)
            && $now >= $deadline
        ) {
            $completionStatus = 'locked_missing';
            if ($obligation) {
                $obligation['completion_status'] = $completionStatus;
            }
        }
        $isWritable = !$isWeeklyRestDay
            && ($isMakeupOpen || $now->format('Y-m-d') === $businessDate->format('Y-m-d'))
            && $now < $deadline
            && in_array($completionStatus, ['missing', 'draft', 'locked_missing'], true);

        $pendingItems = [];
        if ($completionStatus === 'missing' && $isWritable) {
            $pendingItems[] = '填写并提交日报';
        } elseif ($completionStatus === 'draft' && $isWritable) {
            $pendingItems[] = '提交当前草稿';
        } elseif ($completionStatus === 'locked_missing' && !$isMakeupOpen) {
            $pendingItems[] = '联系管理人员处理锁定日报';
        } elseif ($completionStatus === 'locked_missing' && $isMakeupOpen) {
            $pendingItems[] = '补交最近一个工作日的日报';
        }

        return [
            'obligation' => $obligation ?: null,
            'completion_status' => $completionStatus,
            'deadline_at' => $deadline->format('Y-m-d 00:00:00'),
            'is_writable' => $isWritable,
            'pending_items' => $pendingItems,
            'is_weekly_rest_day' => $isWeeklyRestDay,
        ];
    }

    public function correctReport(
        int $reportId,
        array $values,
        string $remarks,
        string $reason,
        int $operatorStaffId
    ): array {
        $reason = trim($reason);
        if ($reportId <= 0 || $operatorStaffId <= 0 || $reason === '') {
            throw new WorkloadReportStateException('日报、更正原因和操作人不能为空');
        }
        if ($values === []) {
            throw new WorkloadReportStateException('更正后的指标值不能为空');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $report = $this->lockReport($reportId);
            $deadline = $this->normalizeBusinessDate((string) $report['report_date'])->modify('+1 day');
            if ($this->databaseNow() < $deadline) {
                throw new WorkloadReportStateException('日报尚未锁定，请使用员工正常提交入口', 409);
            }
            $normalizedValues = $this->normalizeCorrectionValues((string) $report['role_code'], $values);
            $beforeValues = $this->reportValues($reportId);
            $beforeSnapshot = ['report' => $report, 'values' => $beforeValues];

            $valueStmt = $this->pdo->prepare(
                'INSERT INTO workload_daily_report_values (report_id, metric_id, numeric_value) '
                . 'VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE numeric_value = VALUES(numeric_value), '
                . 'text_value = NULL, json_value = NULL, updated_at = CURRENT_TIMESTAMP'
            );
            foreach ($normalizedValues as $value) {
                $valueStmt->execute([$reportId, $value['metric_id'], $value['numeric_value']]);
            }
            $auditValues = [];
            foreach ($normalizedValues as $metricCode => $value) {
                $auditValues[$metricCode] = $value['numeric_value'];
            }
            $ruleVersion = (new WorkloadRoleRuleVersionService($this->pdo))->forReport($reportId);
            (new WorkloadAuditTaskService($this->pdo))->replaceForSubmission(
                $reportId,
                (int) $report['staff_id'],
                (int) $report['store_id'],
                (string) $report['role_code'],
                $auditValues,
                $ruleVersion['metric_rules'],
                $operatorStaffId,
                '管理更正后，审核任务已由新版本替代'
            );
            $updateReport = $this->pdo->prepare(
                "UPDATE workload_daily_reports SET submit_status = 'submitted', remarks = ?, "
                . 'submitted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $updateReport->execute([mb_substr($remarks, 0, 255), $reportId]);

            $obligation = $this->synchronizeReport($reportId, true);
            (new WorkloadConversionResultService($this->pdo))->refreshReport($reportId);
            $settlement = (new WorkloadDailySettlementService($this->pdo))->refreshReport($reportId);
            $afterReport = $this->lockReport($reportId);
            $afterSnapshot = ['report' => $afterReport, 'values' => $this->reportValues($reportId)];
            $correctionKey = $this->generateUuid();
            $insertCorrection = $this->pdo->prepare(
                'INSERT INTO workload_report_corrections '
                . '(correction_key, report_id, obligation_id, before_snapshot_json, after_snapshot_json, '
                . 'correction_reason, requested_by_staff_id, operated_by_staff_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
            );
            $insertCorrection->execute([
                $correctionKey,
                $reportId,
                $obligation['obligation_id'],
                json_encode($beforeSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode($afterSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                mb_substr($reason, 0, 500),
                $operatorStaffId,
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return [
                'correction_key' => $correctionKey,
                'report_id' => $reportId,
                'obligation_id' => $obligation['obligation_id'],
                'completion_status' => 'corrected',
                'business_date' => (string) $report['report_date'],
                'store_id' => (int) $report['store_id'],
                'staff_id' => (int) $report['staff_id'],
                'role_code' => (string) $report['role_code'],
                'metric_codes' => array_keys($normalizedValues),
                'settlement' => $settlement,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function normalizeCorrectionValues(string $roleCode, array $values): array {
        $stmt = $this->pdo->prepare(
            "SELECT id, metric_code, min_value, max_value, is_system_calculated FROM metric_definitions "
            . "WHERE role_code = ? AND metric_group = 'daily_input' AND is_active = 1"
        );
        $stmt->execute([appRoleCode($roleCode)]);
        $metrics = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $metric) {
            $metrics[(string) $metric['metric_code']] = $metric;
        }

        $normalized = [];
        foreach ($values as $row) {
            if (!is_array($row)) {
                throw new WorkloadReportStateException('更正指标格式无效');
            }
            $code = trim((string) ($row['metric_code'] ?? ''));
            if ($code === '' || !isset($metrics[$code])) {
                throw new WorkloadReportStateException('存在不支持的更正指标：' . $code);
            }
            if ((int) $metrics[$code]['is_system_calculated'] === 1 || !is_numeric($row['value'] ?? null)) {
                throw new WorkloadReportStateException('更正指标值无效：' . $code);
            }
            $numeric = (float) $row['value'];
            $min = $metrics[$code]['min_value'];
            $max = $metrics[$code]['max_value'];
            if ($min !== null && $numeric < (float) $min) {
                throw new WorkloadReportStateException('指标值不能小于最小值：' . $code);
            }
            if ($max !== null && $numeric > (float) $max) {
                throw new WorkloadReportStateException('指标值超过最大值：' . $code);
            }
            $normalized[$code] = [
                'metric_id' => (int) $metrics[$code]['id'],
                'numeric_value' => $numeric,
            ];
        }
        return $normalized;
    }

    private function reportValues(int $reportId): array {
        $stmt = $this->pdo->prepare(
            'SELECT metric.metric_code, value.numeric_value, value.text_value, value.json_value '
            . 'FROM workload_daily_report_values value '
            . 'INNER JOIN metric_definitions metric ON metric.id = value.metric_id '
            . 'WHERE value.report_id = ? ORDER BY metric.sort_order ASC, metric.id ASC'
        );
        $stmt->execute([$reportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function lockReport(int $reportId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM workload_daily_reports WHERE id = ? FOR UPDATE');
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            throw new WorkloadReportStateException('日报不存在', 404);
        }
        return $report;
    }

    private function lockObligation(
        string $date,
        int $storeId,
        int $staffId,
        string $normalizedRole
    ): ?array {
        return $this->findObligation($date, $storeId, $staffId, $normalizedRole, true);
    }

    private function findObligation(
        string $date,
        int $storeId,
        int $staffId,
        string $normalizedRole,
        bool $forUpdate
    ): ?array {
        $sql = 'SELECT * FROM workload_submission_obligations '
            . 'WHERE obligation_date = ? AND store_id = ? AND staff_id = ? ORDER BY id ASC';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date, $storeId, $staffId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (appRoleCode((string) $row['role_code']) === $normalizedRole) {
                return $row;
            }
        }
        return null;
    }

    private function databaseNow(): DateTimeImmutable {
        $value = (string) $this->pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $utc->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
    }

    private function normalizeBusinessDate(string $businessDate): DateTimeImmutable {
        $businessDate = trim($businessDate);
        $timezone = new DateTimeZone(self::BUSINESS_TIMEZONE);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$date
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $businessDate
        ) {
            throw new WorkloadReportStateException('业务日期格式必须为 YYYY-MM-DD');
        }
        return $date;
    }

    private function makeupDeadline(DateTimeImmutable $businessDate): DateTimeImmutable {
        $next = $businessDate;
        do {
            $next = $next->modify('+1 day');
        } while ($next->format('N') === '1');
        return $next->modify('+1 day');
    }

    private function generateUuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
