<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/workload/_common.php';

handleCORS();

function todoNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
}

function todoTimePhase(DateTimeImmutable $now): string {
    $minutes = ((int)$now->format('H')) * 60 + (int)$now->format('i');
    if ($minutes < 21 * 60) return 'before_window';
    if ($minutes <= 23 * 60) return 'active_window';
    return 'overdue_window';
}

function todoAddWorkload(array &$todos, array &$summary, PDO $pdo, array $context, DateTimeImmutable $now): void {
    $role = appRoleCode((string)($context['role'] ?? ''));
    if (!in_array($role, ['sales', 'coach'], true)) {
        return;
    }

    $staffId = (int)($context['staff_id'] ?? 0);
    $storeId = (int)($context['store_id'] ?? 0);
    if ($staffId <= 0 || $storeId <= 0) {
        return;
    }

    $today = $now->format('Y-m-d');
    $phase = todoTimePhase($now);
    $stmt = $pdo->prepare("SELECT * FROM workload_daily_reports WHERE report_date=? AND store_id=? AND staff_id=? AND role_code=? LIMIT 1");
    $stmt->execute([$today, $storeId, $staffId, $role]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $status = $report ? (string)($report['submit_status'] ?? 'draft') : 'missing';

    $summary['workload'] = [
        'status' => $status,
        'report_id' => $report ? (int)$report['id'] : 0,
        'window_text' => '21:00-23:00',
        'phase' => $phase,
    ];

    if ($status === 'submitted') {
        return;
    }

    $priority = $phase === 'overdue_window' ? 'urgent' : ($phase === 'active_window' ? 'high' : 'normal');
    $title = '今晚工作量日报';
    $description = '请在 21:00-23:00 完成今日工作量日报';

    if ($phase === 'before_window') {
        $description = '今晚 21:00 开始填写，23:00 前完成提交';
    } elseif ($phase === 'active_window') {
        $description = '当前处于填写时间，请在 23:00 前提交';
    } elseif ($status === 'missing') {
        $title = '工作量日报已逾期';
        $description = '今日工作量日报未提交，请尽快补交';
    } else {
        $title = '工作量日报待提交';
        $description = '草稿已保存，请尽快完成提交';
    }

    $gapCount = 0;
    if ($report) {
        $gapCount = todoWorkloadEvidenceGapCount($pdo, (int)$report['id'], $role);
        if ($gapCount > 0) {
            $title = '工作量凭证待补齐';
            $description = '还有 ' . $gapCount . ' 个已填写指标缺少图片凭证';
            $priority = $phase === 'before_window' ? 'high' : 'urgent';
        }
    }

    $todos[] = [
        'id' => 'workload:' . $today,
        'type' => 'workload',
        'priority' => $priority,
        'title' => $title,
        'description' => $description,
        'status' => $status,
        'due_at' => $today . ' 23:00',
        'route' => '/pages/workload/index',
        'action_text' => $status === 'missing' ? '去填写' : '去处理',
        'meta' => [
            'report_date' => $today,
            'role' => $role,
            'evidence_gap_count' => $gapCount,
        ],
    ];
}

function todoWorkloadEvidenceGapCount(PDO $pdo, int $reportId, string $role): int {
    $valueStmt = $pdo->prepare("SELECT m.metric_code, m.metric_name, v.numeric_value, COALESCE(r.need_evidence, 0) AS need_evidence, COALESCE(r.min_evidence_count, 1) AS min_evidence_count
        FROM workload_daily_report_values v
        JOIN metric_definitions m ON m.id = v.metric_id
        LEFT JOIN workload_metric_rules r ON r.role_code = m.role_code AND r.metric_code = m.metric_code AND r.enabled = 1
        WHERE v.report_id = ? AND m.role_code = ?");
    $valueStmt->execute([$reportId, $role]);
    $rows = $valueStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    $evidenceStmt = $pdo->prepare("SELECT metric_code, COUNT(*) AS evidence_count FROM workload_evidences WHERE report_id = ? GROUP BY metric_code");
    $evidenceStmt->execute([$reportId]);
    $evidenceCounts = [];
    foreach ($evidenceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $evidenceCounts[(string)$row['metric_code']] = (int)$row['evidence_count'];
    }

    $gapCount = 0;
    foreach ($rows as $row) {
        if ((int)($row['need_evidence'] ?? 0) !== 1) continue;
        if ((float)($row['numeric_value'] ?? 0) <= 0) continue;
        $metricCode = (string)$row['metric_code'];
        $required = max(1, (int)($row['min_evidence_count'] ?? 1));
        if (($evidenceCounts[$metricCode] ?? 0) < $required) {
            $gapCount++;
        }
    }
    return $gapCount;
}

function todoAddPolicyNotifications(array &$todos, array &$summary, PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("SELECT id, type, title, content, is_read, is_confirmed, created_at
        FROM policy_notifications
        WHERE user_id = ? AND (is_read = 0 OR (type = 'confirm' AND is_confirmed = 0))
        ORDER BY created_at DESC
        LIMIT 5");
    $stmt->execute([$userId]);
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $count++;
        $type = (string)($row['type'] ?? 'update');
        $todos[] = [
            'id' => 'policy:' . (int)$row['id'],
            'type' => 'policy',
            'priority' => $type === 'confirm' ? 'high' : 'normal',
            'title' => (string)($row['title'] ?? '制度通知'),
            'description' => $type === 'confirm' ? '请阅读并确认制度内容' : (string)($row['content'] ?? ''),
            'status' => $type === 'confirm' ? 'need_confirm' : 'unread',
            'due_at' => '',
            'route' => '/pages/notifications/detail?id=' . (int)$row['id'],
            'action_text' => $type === 'confirm' ? '去确认' : '去查看',
            'meta' => [
                'notification_id' => (int)$row['id'],
                'created_at' => date('Y-m-d H:i', strtotime((string)$row['created_at'])),
            ],
        ];
    }
    $summary['policy_pending_count'] = $count;
}

try {
    $context = appRequireStaffContext();
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);

    $now = todoNow();
    $todos = [];
    $summary = [
        'pending_count' => 0,
        'urgent_count' => 0,
        'workload' => null,
        'policy_pending_count' => 0,
    ];

    todoAddWorkload($todos, $summary, $pdo, $context, $now);
    todoAddPolicyNotifications($todos, $summary, $pdo, (int)($context['user_id'] ?? 0));

    $weight = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
    usort($todos, static function (array $a, array $b) use ($weight): int {
        $pa = $weight[(string)($a['priority'] ?? 'normal')] ?? 2;
        $pb = $weight[(string)($b['priority'] ?? 'normal')] ?? 2;
        if ($pa === $pb) return strcmp((string)($a['due_at'] ?? ''), (string)($b['due_at'] ?? ''));
        return $pa <=> $pb;
    });

    $summary['pending_count'] = count($todos);
    $summary['urgent_count'] = count(array_filter($todos, static fn(array $todo): bool => ($todo['priority'] ?? '') === 'urgent'));

    appJsonSuccess([
        'todos' => array_slice($todos, 0, 8),
        'summary' => $summary,
        'workload_window' => [
            'start' => '21:00',
            'end' => '23:00',
            'description' => '销售和教练每天晚上 21:00-23:00 完成工作量日报',
        ],
        'generated_at' => $now->format('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    appLogEvent('todos.my_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取我的待办失败');
}
