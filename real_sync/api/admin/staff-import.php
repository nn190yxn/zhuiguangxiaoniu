<?php
/**
 * 员工账号批量开通/更新API
 * POST /api/admin/staff-import.php
 *
 * JSON: {"records":[{"employee_no":"CD00101","name":"张三","store_id":1,"role":"sales","password":"..."}]}
 * multipart: type=staff file=staff.csv
 */

require_once __DIR__ . '/common.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '仅支持 POST 请求');
}

[, $user, $operatorStaff] = adminRequireAuth(static fn($u, $s) => isSuperAdminUser($u, $s));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '只支持POST请求');
}

$currentUser = getJwtCurrentUser();
if (!$currentUser) {
    jsonResponse(403, '仅管理员可批量开通员工账号');
}
$operatorStaff = getStaffByUserId((int)($currentUser['user_id'] ?? 0));
$operatorRole = normalizeStaffRoleCode((string)($operatorStaff['role'] ?? ''));
if (!in_array($operatorRole, ['admin', 'ceo'], true)) {
    jsonResponse(403, '仅管理员可批量开通员工账号');
}

try {
    $db = getDB();
    ensureStaffImportSchema($db);
    $records = readStaffImportRecords();
    if (!$records) {
        jsonResponse(1, '没有可导入的员工记录');
    }

    $result = [
        'created' => 0,
        'updated' => 0,
        'linked' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    foreach ($records as $index => $record) {
        $line = $index + 1;
        try {
            $rowResult = upsertStaffAccount($db, $record);
            foreach (['created', 'updated', 'linked'] as $key) {
                if (!empty($rowResult[$key])) {
                    $result[$key]++;
                }
            }
        } catch (Exception $e) {
            $result['skipped']++;
            $result['errors'][] = [
                'line' => $line,
                'employee_no' => $record['employee_no'] ?? '',
                'message' => $e->getMessage(),
            ];
        }
    }

    jsonResponse(0, 'success', $result);
} catch (Exception $e) {
    error_log('staff import error');
    jsonResponse(1, '员工导入失败');
}

function readStaffImportRecords() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $input = adminJsonInput();
        $records = $input['records'] ?? $input;
        return is_array($records) ? normalizeRecords($records) : [];
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        return [];
    }

    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($ext === 'json') {
        $data = json_decode((string)file_get_contents($_FILES['file']['tmp_name']), true);
        $records = $data['records'] ?? $data;
        return is_array($records) ? normalizeRecords($records) : [];
    }

    if ($ext !== 'csv') {
        throw new Exception('员工导入仅支持JSON或CSV');
    }

    $handle = fopen($_FILES['file']['tmp_name'], 'r');
    if (!$handle) {
        throw new Exception('无法读取CSV文件');
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return [];
    }
    $headers = array_map(fn($v) => trim((string)$v), $headers);

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $item = [];
        foreach ($headers as $i => $key) {
            if ($key !== '') {
                $item[$key] = trim((string)($row[$i] ?? ''));
            }
        }
        $rows[] = $item;
    }
    fclose($handle);
    return normalizeRecords($rows);
}

function normalizeRecords($records) {
    $normalized = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $normalized[] = [
            'employee_no' => trim((string)($record['employee_no'] ?? $record['工号'] ?? '')),
            'name' => trim((string)($record['name'] ?? $record['姓名'] ?? '')),
            'store_id' => (int)($record['store_id'] ?? $record['门店ID'] ?? 0),
            'role' => trim((string)($record['role'] ?? $record['角色'] ?? 'sales')),
            'job_title' => trim((string)($record['job_title'] ?? $record['岗位'] ?? '')),
            'phone' => trim((string)($record['phone'] ?? $record['手机号'] ?? '')),
            'entry_date' => trim((string)($record['entry_date'] ?? $record['入职日期'] ?? '')),
            'stage' => trim((string)($record['stage'] ?? $record['阶段'] ?? 'intern')),
            'status' => isset($record['status']) ? (int)$record['status'] : 1,
            'username' => trim((string)($record['username'] ?? $record['账号'] ?? '')),
            'email' => trim((string)($record['email'] ?? $record['邮箱'] ?? '')),
            'password' => (string)($record['password'] ?? $record['initial_password'] ?? $record['初始密码'] ?? ''),
            'openid' => trim((string)($record['openid'] ?? '')),
        ];
    }
    return $normalized;
}

function upsertStaffAccount(PDO $db, array $record) {
    $employeeNo = $record['employee_no'];
    $name = $record['name'];
    $storeId = (int)$record['store_id'];
    $role = $record['role'] ?: 'sales';
    $username = $record['username'] ?: $employeeNo;
    $email = $record['email'] ?: strtolower($username) . '@staff.local';
    $password = $record['password'];

    if ($employeeNo === '' || $name === '' || $storeId <= 0) {
        throw new Exception('工号、姓名、门店ID为必填项');
    }
    if ($password === '') {
        throw new Exception('初始密码为必填项');
    }
    if (!preg_match('/^[a-zA-Z0-9_\-.@]+$/', $username)) {
        throw new Exception('账号只能包含字母、数字、下划线、横线、点或@');
    }

    $storeStmt = $db->prepare('SELECT id FROM stores WHERE id = ? LIMIT 1');
    $storeStmt->execute([$storeId]);
    if (!$storeStmt->fetchColumn()) {
        throw new Exception('门店ID不存在');
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
            $record['job_title'] ?: null,
            $record['phone'] ?: null,
            $record['entry_date'] ?: null,
            $record['stage'] ?: 'intern',
            $record['status'],
            $record['openid'] ?: null,
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
            $record['job_title'] ?: null,
            $record['phone'] ?: null,
            $record['entry_date'] ?: null,
            $record['stage'] ?: 'intern',
            $record['status'],
            $record['openid'] ?: null,
        ]);
        $db->commit();
        return ['created' => true, 'linked' => true];
    } catch (Exception $e) {
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
    if (!isset($columns['wecom_userid'])) {
        $db->exec('ALTER TABLE staffs ADD COLUMN wecom_userid VARCHAR(128) NULL AFTER openid_bound_at');
    }
    if (!isset($columns['wecom_name'])) {
        $db->exec('ALTER TABLE staffs ADD COLUMN wecom_name VARCHAR(100) NULL AFTER wecom_userid');
    }
    if (!isset($columns['wecom_mobile'])) {
        $db->exec('ALTER TABLE staffs ADD COLUMN wecom_mobile VARCHAR(32) NULL AFTER wecom_name');
    }
    if (!isset($columns['wecom_department_id'])) {
        $db->exec('ALTER TABLE staffs ADD COLUMN wecom_department_id VARCHAR(128) NULL AFTER wecom_mobile');
    }
    if (!isset($columns['wecom_department_path'])) {
        $db->exec('ALTER TABLE staffs ADD COLUMN wecom_department_path VARCHAR(255) NULL AFTER wecom_department_id');
    }
    if (!isset($columns['wecom_status'])) {
        $db->exec('ALTER TABLE staffs ADD COLUMN wecom_status TINYINT NULL AFTER wecom_department_path');
    }
    if (!isset($columns['wecom_bound_at'])) {
        $db->exec('ALTER TABLE staffs ADD COLUMN wecom_bound_at DATETIME NULL AFTER wecom_status');
    }
}
