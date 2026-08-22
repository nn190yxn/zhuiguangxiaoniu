<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/GatewaySignature.php';
handleCORS();
header('Content-Type: application/json');

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        jsonResponse(405, '不支持的请求方法');
    }
    if (!GatewaySignature::verifyCurrentRequest()) {
        jsonResponse(403, '云网关签名无效');
    }
    $userId = getCurrentUserId();
    if (!$userId) {
        jsonResponse(401, '请先登录');
    }
    $staff = getStaffByUserId((int)$userId);
    if (!$staff) {
        jsonResponse(403, '员工身份不存在');
    }

    $input = getRequestInput();
    $purpose = mediaToken($input['purpose'] ?? '', 64);
    $businessType = mediaToken($input['business_type'] ?? '', 64);
    $businessId = mediaToken($input['business_id'] ?? '', 128);
    $idempotencyKey = mediaToken($input['idempotency_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''), 160);
    $file = is_array($input['file'] ?? null) ? $input['file'] : [];
    $cloudFileId = mediaToken($file['fileID'] ?? $file['file_id'] ?? '', 255, '/^[a-zA-Z0-9_:\/\.@-]+$/');
    $sourceFingerprint = trim((string)($input['source_fingerprint'] ?? ''));
    if ($sourceFingerprint !== '' && !preg_match('/^[a-f0-9]{64}$/', $sourceFingerprint)) {
        jsonResponse(400, '历史媒体来源指纹无效');
    }
    $mimeType = strtolower(mediaToken($file['mime_type'] ?? '', 80, '/^[a-z0-9+\-.\/]+$/'));
    $byteSize = (int)($file['byte_size'] ?? 0);
    $sha256 = strtolower(mediaToken($file['sha256'] ?? '', 64, '/^[a-f0-9]{64}$/'));
    if ($byteSize < 512) {
        jsonResponse(400, '媒体文件过小');
    }

    $db = getDB();
    mediaEnsureMappingTable($db);
    $idempotencyHash = hash('sha256', $idempotencyKey);
    $existing = mediaFindByIdempotency($db, $idempotencyHash);
    if ($existing) {
        jsonResponse(0, 'success', mediaPublicRow($existing));
    }

    $assetKey = 'cloud-media-' . substr(hash('sha256', implode('|', [$purpose, $businessType, $businessId, $cloudFileId, $sha256])), 0, 32);
    $stmt = $db->prepare('INSERT INTO platform_cloud_media_mappings (asset_key, purpose, business_type, business_id, staff_id, source_fingerprint, cloud_file_id, mime_type, byte_size, sha256, status, idempotency_key_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$assetKey, $purpose, $businessType, $businessId, (int)$staff['id'], $sourceFingerprint !== '' ? $sourceFingerprint : null, $cloudFileId, $mimeType, $byteSize, $sha256, 'pending', $idempotencyHash]);
    $row = mediaFindByIdempotency($db, $idempotencyHash);
    jsonResponse(0, 'success', mediaPublicRow($row ?: ['asset_key' => $assetKey, 'status' => 'pending']));
} catch (Throwable $e) {
    jsonResponse(500, '媒体登记失败');
}

function mediaToken($value, int $maxLength, string $pattern = '/^[a-zA-Z0-9_:\/.@-]+$/'): string {
    $token = trim((string)$value);
    if ($token === '' || strlen($token) > $maxLength || !preg_match($pattern, $token)) {
        jsonResponse(400, '媒体参数无效');
    }
    return $token;
}

function mediaEnsureMappingTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS platform_cloud_media_mappings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        asset_key VARCHAR(128) NOT NULL,
        purpose VARCHAR(64) NOT NULL,
        business_type VARCHAR(64) NOT NULL,
        business_id VARCHAR(128) NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        source_fingerprint CHAR(64) NULL DEFAULT NULL,
        cloud_file_id VARCHAR(255) NOT NULL,
        mime_type VARCHAR(80) NOT NULL,
        byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sha256 CHAR(64) NOT NULL,
        status ENUM('pending','ready','failed','expired') NOT NULL DEFAULT 'pending',
        retry_count INT UNSIGNED NOT NULL DEFAULT 0,
        error_code VARCHAR(64) NOT NULL DEFAULT '',
        idempotency_key_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_cloud_media_asset_key (asset_key),
        UNIQUE KEY uniq_cloud_media_idempotency (idempotency_key_hash),
        UNIQUE KEY uniq_cloud_media_source_fingerprint (source_fingerprint),
        KEY idx_cloud_media_business (business_type, business_id, purpose),
        KEY idx_cloud_media_status (status, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function mediaFindByIdempotency(PDO $db, string $hash): ?array {
    $stmt = $db->prepare('SELECT asset_key, purpose, business_type, business_id, source_fingerprint, cloud_file_id, mime_type, byte_size, sha256, status, retry_count, error_code, created_at, updated_at FROM platform_cloud_media_mappings WHERE idempotency_key_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mediaPublicRow(array $row): array {
    return [
        'asset_key' => (string)($row['asset_key'] ?? ''),
        'purpose' => (string)($row['purpose'] ?? ''),
        'business_type' => (string)($row['business_type'] ?? ''),
        'business_id' => (string)($row['business_id'] ?? ''),
        'source_fingerprint' => (string)($row['source_fingerprint'] ?? ''),
        'fileID' => (string)($row['cloud_file_id'] ?? ''),
        'mime_type' => (string)($row['mime_type'] ?? ''),
        'byte_size' => (int)($row['byte_size'] ?? 0),
        'sha256' => (string)($row['sha256'] ?? ''),
        'status' => (string)($row['status'] ?? 'pending'),
        'retry_count' => (int)($row['retry_count'] ?? 0),
        'error_code' => (string)($row['error_code'] ?? ''),
    ];
}
