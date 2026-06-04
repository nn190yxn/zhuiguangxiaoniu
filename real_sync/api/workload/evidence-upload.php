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
    
    $reportId = appRequireInt($input, 'report_id', '日报 ID');
    $metricCode = appRequireString($input, 'metric_code', '指标代码');
    $imageData = appRequireString($input, 'image_data', '图片数据');

    if (strlen($imageData) > 7 * 1024 * 1024) {
        appJsonError(400, '图片不能超过 5MB');
    }
    
    if (strpos($imageData, 'data:image/') === 0) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
    }
    
    $decoded = base64_decode($imageData, true);
    if ($decoded === false) {
        appJsonError(400, '图片数据无效');
    }
    
    if (strlen($decoded) > 5 * 1024 * 1024) {
        appJsonError(400, '图片不能超过 5MB');
    }
    
    $imageInfo = @getimagesizefromstring($decoded);
    if ($imageInfo === false || empty($imageInfo['mime'])) {
        appJsonError(400, 'invalid image file');
    }

    $allowedMimes = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG  => ['image/png', 'png'],
        IMAGETYPE_GIF  => ['image/gif', 'gif'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];
    $detectedType = (int)($imageInfo[2] ?? 0);
    if (!isset($allowedMimes[$detectedType])) {
        appJsonError(400, 'unsupported image format');
    }

    if (preg_match('/<\?php|<script|<\?|eval\s*\(|base64_decode\s*\(/i', $decoded)) {
        appJsonError(400, 'invalid image content');
    }

    $pdo = workloadDb();
    workloadEnsureAuditSchema($pdo);
    
    $stmt = $pdo->prepare("SELECT staff_id FROM workload_daily_reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report || (int)$report['staff_id'] !== (int)$context['staff_id']) {
        appJsonError(403, '无权操作该日报');
    }
    
    $rules = workloadGetMetricRules($pdo, $context['role'] ?? '');
    if (!isset($rules[$metricCode]) || !(int)$rules[$metricCode]['need_evidence']) {
        appJsonError(400, '该指标无需上传凭证');
    }
    $maxEvidenceCount = workloadEvidenceMaxLimit($rules[$metricCode]);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM workload_evidences WHERE report_id = ? AND metric_code = ?");
    $countStmt->execute([$reportId, $metricCode]);
    $currentCount = (int)$countStmt->fetchColumn();
    if ($currentCount >= $maxEvidenceCount) {
        appJsonError(400, '该指标最多只能上传 ' . $maxEvidenceCount . ' 张凭证图片');
    }

    $uploadDir = '/www/wwwroot/122.51.223.46/uploads/workload/evidence/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $ext = $allowedMimes[$detectedType][1];
    $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadDir . $fileName;
    
    if (file_put_contents($targetPath, $decoded) === false) {
        appJsonError(500, '文件保存失败');
    }
    
    $fileUrl = '/uploads/workload/evidence/' . $fileName;
    
    $ins = $pdo->prepare("INSERT INTO workload_evidences (report_id, staff_id, store_id, role_code, metric_code, file_url, file_name, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([
        $reportId,
        (int)$context['staff_id'],
        (int)$context['store_id'],
        $context['role'] ?? '',
        $metricCode,
        $fileUrl,
        $fileName,
        strlen($decoded),
        $allowedMimes[$detectedType][0]
    ]);
    
    appJsonSuccess(['file_url' => $fileUrl, 'id' => $pdo->lastInsertId()], '上传成功');
    
} catch (Throwable $e) {
    appLogEvent('workload.evidence_upload_error', ['error' => $e->getMessage()]);
    appJsonError(500, '上传失败');
}
