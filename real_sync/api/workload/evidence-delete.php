<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        appJsonError(405, '不支持的请求方法');
    }

    $context = appRequireStaffContext();
    $input = appInputArray();
    $evidenceId = appRequireInt($input, 'id', '凭证 ID');

    $pdo = workloadDb();
    workloadEnsureAuditSchema($pdo);

    $stmt = $pdo->prepare("SELECT e.id, e.file_url, e.staff_id, e.store_id, r.submit_status FROM workload_evidences e LEFT JOIN workload_daily_reports r ON r.id = e.report_id WHERE e.id = ? LIMIT 1");
    $stmt->execute([$evidenceId]);
    $evidence = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$evidence) {
        appJsonError(404, '凭证不存在');
    }

    $isOwner = (int)($evidence['staff_id'] ?? 0) === (int)($context['staff_id'] ?? 0);
    $canManageStore = (string)($context['role'] ?? '') === 'manager' && appCanViewStore($context, (int)($evidence['store_id'] ?? 0));
    if (!appCanEditAll($context) && !$isOwner && !$canManageStore) {
        appJsonError(403, '无权限删除该凭证');
    }

    if (($evidence['submit_status'] ?? '') === 'submitted' && !appCanEditAll($context)) {
        appJsonError(400, '日报已提交，当前不允许删除凭证');
    }

    $deleteStmt = $pdo->prepare("DELETE FROM workload_evidences WHERE id = ?");
    $deleteStmt->execute([$evidenceId]);

    $fileUrl = (string)($evidence['file_url'] ?? '');
    if ($fileUrl !== '' && strncmp($fileUrl, '/uploads/workload/evidence/', 27) === 0) {
        $filePath = '/www/wwwroot/122.51.223.46' . $fileUrl;
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    appJsonSuccess([], '删除成功');
} catch (Throwable $e) {
    appLogEvent('workload.evidence_delete_error', ['error' => $e->getMessage()]);
    appJsonError(500, '删除失败');
}
