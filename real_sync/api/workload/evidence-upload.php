<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        appJsonError(405, '不支持的请求方法');
    }

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0) {
        appJsonError(400, '未收到上传内容，请重新选择图片');
    }
    
    $context = appRequireStaffContext();
    $input = appInputArray();
    
    $reportId = appRequireInt($input, 'report_id', '日报 ID');
    $metricCode = appRequireString($input, 'metric_code', '指标代码');

    $decoded = '';
    if (isset($_FILES['image_file']) && is_array($_FILES['image_file'])) {
        $file = $_FILES['image_file'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadMessages = [
                UPLOAD_ERR_INI_SIZE => '图片超过服务器上传限制，请压缩后重试',
                UPLOAD_ERR_FORM_SIZE => '图片超过页面上传限制，请压缩后重试',
                UPLOAD_ERR_PARTIAL => '图片只上传了一部分，请检查网络后重试',
                UPLOAD_ERR_NO_FILE => '未收到图片文件，请重新选择图片',
                UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不可用，请联系管理员',
                UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件，请联系管理员',
                UPLOAD_ERR_EXTENSION => '图片上传被服务器扩展阻止，请联系管理员',
            ];
            appJsonError(400, $uploadMessages[$uploadError] ?? ('图片上传失败，错误码: ' . $uploadError));
        }
        if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
            appJsonError(400, '图片不能超过 5MB');
        }
        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            appJsonError(400, '图片文件无效');
        }
        $decoded = (string)file_get_contents($tmpName);
    } else {
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
    }
    
    if (strlen($decoded) > 5 * 1024 * 1024) {
        appJsonError(400, '图片不能超过 5MB');
    }
    if (strlen($decoded) < 512) {
        appJsonError(400, '图片文件过小，请重新拍照或选择清晰截图');
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
    $width = (int)($imageInfo[0] ?? 0);
    $height = (int)($imageInfo[1] ?? 0);
    if ($width < 80 || $height < 80) {
        appJsonError(400, '图片尺寸过小，请重新拍照或选择清晰截图');
    }

    $pdo = workloadDb();
    workloadEnsureAuditSchema($pdo);
    
    $stmt = $pdo->prepare("SELECT staff_id, store_id, role_code FROM workload_daily_reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report || (int)$report['staff_id'] !== (int)$context['staff_id']) {
        appJsonError(403, '无权操作该日报');
    }
    if ((int)($report['store_id'] ?? 0) !== (int)($context['store_id'] ?? 0)) {
        appJsonError(403, '无权操作该门店日报');
    }
    if (appRoleCode((string)($report['role_code'] ?? '')) !== appRoleCode((string)($context['role'] ?? ''))) {
        appJsonError(403, '无权操作该岗位日报');
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

    $uploadDir = dirname(__DIR__, 2) . '/uploads/workload/evidence/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $ext = $allowedMimes[$detectedType][1];
    $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadDir . $fileName;
    
    if (file_put_contents($targetPath, $decoded, LOCK_EX) === false) {
        appJsonError(500, '文件保存失败');
    }
    
    $fileUrl = '/uploads/workload/evidence/' . $fileName;
    
    try {
        $pdo->beginTransaction();
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
        $evidenceId = $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_file($targetPath)) {
            @unlink($targetPath);
        }
        throw $e;
    }

    appLogEvent('workload.evidence_upload_success', [
        'staff_id' => (int)$context['staff_id'],
        'store_id' => (int)$context['store_id'],
        'report_id' => $reportId,
        'metric_code' => $metricCode,
        'file_url' => $fileUrl,
        'file_size' => strlen($decoded),
    ]);
    
    appJsonSuccess(['file_url' => $fileUrl, 'id' => $evidenceId, 'request_id' => appRequestId()], '上传成功');
    
} catch (Throwable $e) {
    appLogEvent('workload.evidence_upload_error', [
        'error' => $e->getMessage(),
        'files_keys' => array_keys($_FILES ?? []),
        'post_keys' => array_keys($_POST ?? []),
        'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
        'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
    ]);
    appJsonError(500, '上传失败，请稍后重试（' . appRequestId() . '）');
}
