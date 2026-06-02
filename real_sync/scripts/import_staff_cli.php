<?php

require_once __DIR__ . '/../api/config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

$jsonPath = $argv[1] ?? '';
if ($jsonPath === '' || !is_file($jsonPath)) {
    fwrite(STDERR, "Usage: php import_staff_cli.php <json-file>\n");
    exit(1);
}

$records = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($records)) {
    fwrite(STDERR, "Invalid JSON file.\n");
    exit(1);
}

$db = getDB();
ensureStaffImportSchema($db);

$result = [
    'created' => 0,
    'updated' => 0,
    'linked' => 0,
    'skipped' => 0,
    'errors' => [],
];

foreach ($records as $index => $record) {
    try {
        $rowResult = upsertStaffAccount($db, $record);
        foreach (['created', 'updated', 'linked'] as $key) {
            if (!empty($rowResult[$key])) {
                $result[$key]++;
            }
        }
    } catch (Throwable $e) {
        $result['skipped']++;
        $result['errors'][] = [
            'line' => $index + 1,
            'employee_no' => $record['employee_no'] ?? '',
            'message' => $e->getMessage(),
        ];
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

function upsertStaffAccount(PDO $db, array $record): array {
    $employeeNo = trim((string)($record['employee_no'] ?? ''));
    $name = trim((string)($record['name'] ?? ''));
    $storeId = (int)($record['store_id'] ?? 0);
    $role = normalizeStaffRoleCode($record['role'] ?? 'sales');
    $username = trim((string)($record['username'] ?? $employeeNo));
    $email = trim((string)($record['email'] ?? '')) ?: strtolower($username) . '@staff.local';
    $password = (string)($record['password'] ?? '');

    if ($employeeNo === '' || $name === '' || $storeId <= 0) {
        throw new RuntimeException('工号、姓名、门店ID为必填项');
    }
    if ($password === '') {
        throw new RuntimeException('初始密码为必填项');
    }
    if (!preg_match('/^[a-zA-Z0-9_\-.@]+$/', $username)) {
        throw new RuntimeException('账号格式不合法');
    }

    $storeStmt = $db->prepare('SELECT id FROM stores WHERE id = ? LIMIT 1');
    $storeStmt->execute([$storeId]);
    if (!$storeStmt->fetchColumn()) {
        throw new RuntimeException('门店ID不存在');
    }

    $db->beginTransaction();
    try {
        $userId = ensureWpUser($db, $username, $email, $password, $name, $role);

        $stmt = $db->prepare('SELECT * FROM staffs WHERE employee_no = ? LIMIT 1');
        $stmt->execute([$employeeNo]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = [
            $storeId,
            $userId,
            $name,
            $role,
            ($record['job_title'] ?? '') ?: null,
            ($record['phone'] ?? '') ?: null,
            ($record['entry_date'] ?? '') ?: null,
            ($record['stage'] ?? '') ?: 'intern',
            isset($record['status']) ? (int)$record['status'] : 1,
            ($record['openid'] ?? '') ?: null,
        ];

        if ($existing) {
            $sql = 'UPDATE staffs SET store_id = ?, user_id = ?, name = ?, role = ?, job_title = ?, phone = ?, entry_date = ?, stage = ?, status = ?, openid = COALESCE(?, openid), updated_at = NOW() WHERE employee_no = ?';
            $db->prepare($sql)->execute([...$data, $employeeNo]);
            $db->commit();
            return ['updated' => true, 'linked' => empty($existing['user_id'])];
        }

        $sql = 'INSERT INTO staffs (store_id, user_id, employee_no, name, role, job_title, phone, entry_date, stage, status, openid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $db->prepare($sql)->execute([
            $storeId,
            $userId,
            $employeeNo,
            $name,
            $role,
            ($record['job_title'] ?? '') ?: null,
            ($record['phone'] ?? '') ?: null,
            ($record['entry_date'] ?? '') ?: null,
            ($record['stage'] ?? '') ?: 'intern',
            isset($record['status']) ? (int)$record['status'] : 1,
            ($record['openid'] ?? '') ?: null,
        ]);
        $db->commit();
        return ['created' => true, 'linked' => true];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function ensureWpUser(PDO $db, string $username, string $email, string $password, string $displayName, string $role): int {
    $stmt = $db->prepare('SELECT ID FROM wp_users WHERE user_login = ? OR user_email = ? LIMIT 1');
    $stmt->execute([$username, $email]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);

    $hash = wpHashPassword($password);
    if ($existingId) {
        $db->prepare('UPDATE wp_users SET user_pass = ?, user_email = ?, display_name = ?, user_status = 0 WHERE ID = ?')->execute([$hash, $email, $displayName, $existingId]);
        ensureWpRole($db, $existingId, $role);
        return $existingId;
    }

    $now = date('Y-m-d H:i:s');
    $nicename = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($username));
    $sql = 'INSERT INTO wp_users (user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)';
    $db->prepare($sql)->execute([$username, $hash, $nicename, $email, '', $now, '', $displayName]);
    $userId = (int)$db->lastInsertId();
    ensureWpRole($db, $userId, $role);
    return $userId;
}

function ensureWpRole(PDO $db, int $userId, string $role): void {
    $wpRole = $role === 'manager' ? 'editor' : 'subscriber';
    $capabilities = serialize([$wpRole => true]);
    upsertUserMeta($db, $userId, 'wp_capabilities', $capabilities);
    upsertUserMeta($db, $userId, 'wp_user_level', $wpRole === 'editor' ? '7' : '0');
}

function upsertUserMeta(PDO $db, int $userId, string $key, string $value): void {
    $stmt = $db->prepare('SELECT umeta_id FROM wp_usermeta WHERE user_id = ? AND meta_key = ? LIMIT 1');
    $stmt->execute([$userId, $key]);
    $metaId = (int)($stmt->fetchColumn() ?: 0);
    if ($metaId) {
        $db->prepare('UPDATE wp_usermeta SET meta_value = ? WHERE umeta_id = ?')->execute([$value, $metaId]);
        return;
    }
    $db->prepare('INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)')->execute([$userId, $key, $value]);
}

function wpHashPassword(string $password): string {
    $passwordToHash = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
    return '$wp' . password_hash($passwordToHash, PASSWORD_BCRYPT);
}

function ensureStaffImportSchema(PDO $db): void {
    $columns = [];
    foreach ($db->query('DESCRIBE staffs') as $column) {
        $columns[$column['Field']] = true;
    }
    if (!isset($columns['openid'])) {
        $db->exec('ALTER TABLE staffs ADD COLUMN openid VARCHAR(128) NULL AFTER status');
    }
    if (!isset($columns['openid_bound_at'])) {
        $db->exec('ALTER TABLE staffs ADD COLUMN openid_bound_at DATETIME NULL AFTER openid');
    }
}
