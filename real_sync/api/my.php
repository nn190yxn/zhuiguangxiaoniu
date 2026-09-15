<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/reminder/_common.php';

handleCORS();

function todoNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
}

function todoTimePhase(DateTimeImmutable $now): string {
    return 'before_deadline';
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
        'window_text' => '当天 24:00 前',
        'phase' => $phase,
    ];

    if ($status === 'submitted') {
        return;
    }

    $priority = 'high';
    $title = '今日工作量日报';
    $description = '请在当天 24:00 前完成今日工作量日报';

    if ($status !== 'missing') {
        $title = '工作量日报待提交';
        $description = '草稿已保存，请在当天 24:00 前完成提交';
    }

    $gapCount = 0;
    if ($report) {
        $gapCount = todoWorkloadEvidenceGapCount($pdo, (int)$report['id'], $role);
        if ($gapCount > 0) {
            $title = '工作量凭证待补齐';
            $description = '还有 ' . $gapCount . ' 个已填写指标缺少图片凭证';
            $priority = 'urgent';
        }
    }

    $todos[] = [
        'id' => 'workload:' . $today,
        'type' => 'workload',
        'priority' => $priority,
        'title' => $title,
        'description' => $description,
        'status' => $status,
        'due_at' => $today . ' 24:00',
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
    return workloadReportEvidenceGapCount($pdo, $reportId, $role);
}

function todoAddPolicyNotifications(array &$todos, array &$summary, PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("SELECT id, source_type, type, title, content, is_read, is_confirmed, created_at FROM (
            SELECT CONCAT('policy:', n.id) AS id, 'policy' AS source_type, n.type, n.title, n.content, n.is_read, n.is_confirmed, n.created_at
            FROM policy_notifications n
            WHERE n.user_id = ? AND (n.is_read = 0 OR (n.type = 'confirm' AND n.is_confirmed = 0))
            UNION ALL
            SELECT CONCAT('reminder:', n.id) AS id, 'reminder' AS source_type, n.type, n.title, n.content, n.is_read, n.is_confirmed, n.created_at
            FROM mini_user_notifications n
            WHERE n.user_id = ? AND (n.is_read = 0 OR (n.type = 'confirm' AND n.is_confirmed = 0))
        ) pending_notifications
        ORDER BY created_at DESC
        LIMIT 5");
    $stmt->execute([$userId, $userId]);
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $count++;
        $type = (string)($row['type'] ?? 'update');
        $todos[] = [
            'id' => (string)$row['id'],
            'type' => (string)($row['source_type'] ?? 'policy'),
            'priority' => $type === 'confirm' ? 'high' : 'normal',
            'title' => (string)($row['title'] ?? '制度通知'),
            'description' => $type === 'confirm' ? '请阅读并确认制度内容' : (string)($row['content'] ?? ''),
            'status' => $type === 'confirm' ? 'need_confirm' : 'unread',
            'due_at' => '',
            'route' => '/pages/notifications/detail?id=' . rawurlencode((string)$row['id']),
            'action_text' => $type === 'confirm' ? '去确认' : '去查看',
            'meta' => [
                'notification_id' => (string)$row['id'],
                'created_at' => date('Y-m-d H:i', strtotime((string)$row['created_at'])),
            ],
        ];
    }
    $summary['policy_pending_count'] = $count;
}

try {
    $context = appRequireStaffContext();
    $pdo = workloadDb();
    reminderEnsureSchema($pdo);

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
            'start' => '00:00',
            'end' => '24:00',
            'description' => '销售和教练每天 24:00 前完成工作量日报',
        ],
        'generated_at' => $now->format('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    appLogEvent('todos.my_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取我的待办失败');
}
