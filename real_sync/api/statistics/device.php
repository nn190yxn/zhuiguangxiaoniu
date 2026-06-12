<?php
/**
 * 设备登录记录API
 * POST /api/statistics/device.php - 记录设备登录
 * GET /api/statistics/device.php - 获取当前账号的设备列表
 *
 * POST参数:
 *   device_id     - 设备标识
 *   device_name   - 设备名称（如 "iPhone 14 Pro"）
 *   device_model  - 设备型号
 *   os_version    - 操作系统版本
 *   app_version   - 小程序版本
 *   screen_width  - 屏幕宽度
 *   screen_height - 屏幕高度
 *
 * GET参数:
 *   staff_id - 员工ID（管理员可查看其他人的）
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();
    ensureDeviceLoginsTableForStatistics($db);

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        $deviceId = isset($input['device_id']) ? trim($input['device_id']) : '';
        $deviceName = isset($input['device_name']) ? trim($input['device_name']) : '';
        $deviceModel = isset($input['device_model']) ? trim($input['device_model']) : '';
        $osVersion = isset($input['os_version']) ? trim($input['os_version']) : '';
        $appVersion = isset($input['app_version']) ? trim($input['app_version']) : '';
        $screenWidth = isset($input['screen_width']) ? intval($input['screen_width']) : 0;
        $screenHeight = isset($input['screen_height']) ? intval($input['screen_height']) : 0;

        if (empty($deviceId)) {
            jsonResponse(1, '设备标识不能为空');
        }

        // 获取当前用户的员工ID
        $staffId = 0;
        $stmt = $db->prepare("SELECT id FROM staffs WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $staffId = $row['id'];
        }

        if ($staffId == 0) {
            jsonResponse(1, '未找到员工信息');
        }

        // 小程序本地生成的 device_id 是登录与设备审计的统一口径。
        $deviceFingerprint = $deviceId;

        // 检查是否是新设备
        $checkSql = "SELECT id, is_trusted FROM device_logins WHERE staff_id = ? AND device_fingerprint = ? ORDER BY last_login DESC LIMIT 1";
        $stmt = $db->prepare($checkSql);
        $stmt->execute([$staffId, $deviceFingerprint]);
        $existingDevice = $stmt->fetch(PDO::FETCH_ASSOC);

        $isNewDevice = empty($existingDevice);
        $isTrusted = $existingDevice ? (bool)$existingDevice['is_trusted'] : false;

        if ($existingDevice) {
            // 更新登录记录
            $updateSql = "UPDATE device_logins SET
                          last_login = NOW(),
                          login_count = login_count + 1,
                          device_name = COALESCE(?, device_name),
                          app_version = COALESCE(?, app_version)
                          WHERE id = ?";
            $stmt = $db->prepare($updateSql);
            $stmt->execute([$deviceName, $appVersion, $existingDevice['id']]);
            $loginId = $existingDevice['id'];
        } else {
            // 新设备，记录并标记
            $insertSql = "INSERT INTO device_logins
                          (staff_id, device_id, device_fingerprint, device_name, device_model,
                           os_version, app_version, screen_width, screen_height, login_count, is_trusted)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)";
            $stmt = $db->prepare($insertSql);
            $stmt->execute([
                $staffId, $deviceId, $deviceFingerprint, $deviceName, $deviceModel,
                $osVersion, $appVersion, $screenWidth, $screenHeight
            ]);
            $loginId = $db->lastInsertId();
        }

        jsonResponse(0, 'success', [
            'login_id' => (int)$loginId,
            'is_new_device' => $isNewDevice,
            'is_trusted' => $isTrusted,
            'device_fingerprint' => $deviceFingerprint,
            'message' => $isNewDevice ? '新设备记录已创建' : '设备信息已更新'
        ]);
    }
    elseif ($method === 'GET') {
        $view = isset($_GET['view']) ? trim((string)$_GET['view']) : '';
        if ($view === 'alerts' || $view === 'usage') {
            if (!isCurrentUserAdmin($db, (int)$userId)) {
                jsonResponse(403, '无权查看设备安全数据');
            }
            if ($view === 'alerts') {
                $sql = "SELECT s.id AS staff_id, s.name AS staff_name, st.name AS store_name,
                        COUNT(d.id) AS device_count, MAX(d.last_login) AS last_login
                    FROM device_logins d
                    JOIN staffs s ON s.id = d.staff_id
                    LEFT JOIN stores st ON st.id = s.store_id
                    GROUP BY s.id, s.name, st.name
                    HAVING device_count >= 3
                    ORDER BY device_count DESC, last_login DESC
                    LIMIT 100";
                jsonResponse(0, 'success', ['alerts' => $db->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
            }
            $sql = "SELECT s.id AS staff_id, s.name AS staff_name, st.name AS store_name,
                    COUNT(d.id) AS device_count,
                    SUM(CASE WHEN d.is_trusted = 1 THEN 1 ELSE 0 END) AS trusted_count,
                    MAX(d.last_login) AS last_login
                FROM device_logins d
                JOIN staffs s ON s.id = d.staff_id
                LEFT JOIN stores st ON st.id = s.store_id
                GROUP BY s.id, s.name, st.name
                ORDER BY last_login DESC
                LIMIT 100";
            jsonResponse(0, 'success', ['usage' => $db->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
        }

        $staffId = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;

        // 获取当前用户的员工ID（如果没传staff_id）
        if ($staffId <= 0) {
            $stmt = $db->prepare("SELECT id FROM staffs WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $staffId = $row ? $row['id'] : 0;
        }

        if ($staffId == 0) {
            jsonResponse(1, '未找到员工信息');
        }

        if (!isCurrentUserAdmin($db, (int)$userId)) {
            $stmt = $db->prepare("SELECT id FROM staffs WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $selfStaffId = (int)($stmt->fetchColumn() ?: 0);
            if ($selfStaffId !== $staffId) {
                jsonResponse(403, '无权查看其他员工设备');
            }
        }

        // 获取该员工的所有设备
        $sql = "SELECT * FROM device_logins WHERE staff_id = ? ORDER BY last_login DESC LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->execute([$staffId]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 格式化数据
        foreach ($devices as &$device) {
            $device['is_trusted'] = (bool)$device['is_trusted'];
            $device['last_login'] = date('Y-m-d H:i', strtotime($device['last_login']));
            $device['created_at'] = date('Y-m-d H:i', strtotime($device['created_at']));
        }

        // 统计信息
        $stats = [
            'total_devices' => count($devices),
            'trusted_devices' => count(array_filter($devices, fn($d) => $d['is_trusted'])),
            'recent_login' => !empty($devices) ? $devices[0]['last_login'] : null
        ];

        jsonResponse(0, 'success', [
            'devices' => $devices,
            'stats' => $stats
        ]);
    }
    else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}

function ensureDeviceLoginsTableForStatistics(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS device_logins (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id BIGINT UNSIGNED NOT NULL,
        openid VARCHAR(128) DEFAULT NULL,
        device_id VARCHAR(120) DEFAULT NULL,
        device_fingerprint VARCHAR(120) NOT NULL DEFAULT '',
        device_name VARCHAR(120) DEFAULT NULL,
        device_model VARCHAR(120) DEFAULT NULL,
        os_version VARCHAR(120) DEFAULT NULL,
        app_version VARCHAR(60) DEFAULT NULL,
        screen_width INT DEFAULT 0,
        screen_height INT DEFAULT 0,
        login_count INT NOT NULL DEFAULT 0,
        is_trusted TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        first_login DATETIME DEFAULT NULL,
        last_login DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_staff_device (staff_id, device_fingerprint),
        KEY idx_last_login (last_login)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'openid' => "ALTER TABLE device_logins ADD COLUMN openid VARCHAR(128) DEFAULT NULL AFTER staff_id",
        'device_id' => "ALTER TABLE device_logins ADD COLUMN device_id VARCHAR(120) DEFAULT NULL AFTER openid",
        'device_fingerprint' => "ALTER TABLE device_logins ADD COLUMN device_fingerprint VARCHAR(120) NOT NULL DEFAULT '' AFTER device_id",
        'device_name' => "ALTER TABLE device_logins ADD COLUMN device_name VARCHAR(120) DEFAULT NULL AFTER device_fingerprint",
        'device_model' => "ALTER TABLE device_logins ADD COLUMN device_model VARCHAR(120) DEFAULT NULL AFTER device_name",
        'os_version' => "ALTER TABLE device_logins ADD COLUMN os_version VARCHAR(120) DEFAULT NULL AFTER device_model",
        'app_version' => "ALTER TABLE device_logins ADD COLUMN app_version VARCHAR(60) DEFAULT NULL AFTER os_version",
        'screen_width' => "ALTER TABLE device_logins ADD COLUMN screen_width INT DEFAULT 0 AFTER app_version",
        'screen_height' => "ALTER TABLE device_logins ADD COLUMN screen_height INT DEFAULT 0 AFTER screen_width",
        'login_count' => "ALTER TABLE device_logins ADD COLUMN login_count INT NOT NULL DEFAULT 0 AFTER screen_height",
        'is_trusted' => "ALTER TABLE device_logins ADD COLUMN is_trusted TINYINT(1) NOT NULL DEFAULT 0 AFTER login_count",
        'is_active' => "ALTER TABLE device_logins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_trusted",
        'first_login' => "ALTER TABLE device_logins ADD COLUMN first_login DATETIME DEFAULT NULL AFTER is_active",
        'last_login' => "ALTER TABLE device_logins ADD COLUMN last_login DATETIME DEFAULT NULL AFTER first_login",
    ] as $column => $sql) {
        if (!deviceColumnExists($db, 'device_logins', $column)) {
            $db->exec($sql);
        }
    }
}

function deviceColumnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $db->quote($column));
    return (bool)($stmt ? $stmt->fetchColumn() : false);
}

function isCurrentUserAdmin(PDO $db, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $stmt = $db->prepare("SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'wp_capabilities' LIMIT 1");
    $stmt->execute([$userId]);
    $meta = $stmt->fetchColumn();
    if (!is_string($meta) || $meta === '') {
        return false;
    }
    $caps = @unserialize($meta);
    return is_array($caps) && !empty($caps['administrator']);
}
