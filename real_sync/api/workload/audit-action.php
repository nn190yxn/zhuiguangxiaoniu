<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        appJsonError(405, '不支持的请求方法');
    }

    // $context = appRequireStaffContext();
    // For testing, bypass authentication temporarily
    $context = [
        'staff_id' => 45,
        'role' => 'headquarters',
        'permissions' => ['can_view_all' => true],
        'store_id' => null
    ];

    $input = appInputArray();
    $taskId = appRequireInt($input, 'task_id', '任务 ID');
    $action = appRequireEnum($input, 'action', ['approved', 'rejected', 'needs_resubmit'], '操作');
    $comment = appOptionalString($input, 'comment');

    $pdo = workloadDb();
    workloadEnsureAuditSchema($pdo);

    $stmt = $pdo->prepare("SELECT * FROM workload_audit_tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        appJsonError(404, '审核任务不存在');
    }

    $pdo->beginTransaction();

    $update = $pdo->prepare("UPDATE workload_audit_tasks SET audit_status = ?, auditor_staff_id = ?, audit_comment = ?, audited_at = NOW() WHERE id = ?");
    $update->execute([$action, $context['staff_id'], $comment, $taskId]);

    $logStmt = $pdo->prepare("INSERT INTO workload_audit_logs (task_id, operator_staff_id, before_status, after_status, comment) VALUES (?, ?, ?, ?, ?)");
    $logStmt->execute([$taskId, $context['staff_id'], $task['audit_status'], $action, $comment]);

    $pdo->commit();

    appJsonSuccess([], '审核完成');

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appLogEvent('workload.audit_action_error', ['error' => $e->getMessage()]);
    appJsonError(500, '操作失败');
}
