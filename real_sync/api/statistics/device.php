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

        // 生成设备指纹（基于多因素组合）
        $deviceFingerprint = md5($deviceModel . $screenWidth . $screenHeight . $osVersion);

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