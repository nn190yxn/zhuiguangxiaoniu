<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    $reportId = appRequireInt($_GET, 'report_id', '日报 ID');

    $pdo = workloadDb();
    workloadEnsureAuditSchema($pdo);

    $reportStmt = $pdo->prepare("SELECT id, staff_id, store_id FROM workload_daily_reports WHERE id = ? LIMIT 1");
    $reportStmt->execute([$reportId]);
    $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        appJsonError(404, '日报不存在');
    }

    $isOwner = (int)($report['staff_id'] ?? 0) === (int)($context['staff_id'] ?? 0);
    $canManageStore = (string)($context['role'] ?? '') === 'manager' && appCanViewStore($context, (int)($report['store_id'] ?? 0));
    if (!appCanEditAll($context) && !$isOwner && !$canManageStore) {
        appJsonError(403, '无权限查看该日报凭证');
    }

    $stmt = $pdo->prepare("SELECT * FROM workload_evidences WHERE report_id = ? AND deleted_at IS NULL ORDER BY created_at ASC");
    $stmt->execute([$reportId]);
    $list = workloadNormalizeEvidenceRows($stmt->fetchAll(PDO::FETCH_ASSOC));

    appJsonSuccess(['list' => $list]);

} catch (Throwable $e) {
    appLogEvent('workload.evidence_list_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取列表失败');
}
