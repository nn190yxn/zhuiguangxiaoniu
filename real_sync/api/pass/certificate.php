<?php
/**
 * 证书和徽章API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        $action = isset($_GET['action']) ? $_GET['action'] : 'certificates';

        if ($action === 'certificates') {
            // 获取用户证书
            $sql = "SELECT pc.*, ps.name as stage_name, ps.role, ps.stage
                    FROM pass_certificates pc
                    JOIN pass_stages ps ON pc.stage_id = ps.id
                    WHERE pc.user_id = ?
                    ORDER BY pc.issued_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse(0, 'success', ['list' => $certificates]);

        } elseif ($action === 'achievements') {
            // 获取用户徽章
            $sql = "SELECT ua.*, a.name, a.code, a.icon, a.description
                    FROM user_achievements ua
                    JOIN achievements a ON ua.achievement_id = a.id
                    WHERE ua.user_id = ?
                    ORDER BY ua.earned_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 获取可获得的徽章
            $allSql = "SELECT * FROM achievements WHERE status = 1 ORDER BY sort_order ASC";
            $stmt = $db->prepare($allSql);
            $stmt->execute();
            $allAchievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $earnedIds = array_column($achievements, 'achievement_id');

            $available = [];
            foreach ($allAchievements as $a) {
                if (!in_array($a['id'], $earnedIds)) {
                    $a['earned'] = false;
                    $available[] = $a;
                }
            }

            foreach ($achievements as &$a) {
                $a['earned'] = true;
            }

            jsonResponse(0, 'success', [
                'earned' => $achievements,
                'available' => $available
            ]);

        } elseif ($action === 'verify') {
            // 验证证书
            $verifyCode = isset($_GET['code']) ? trim($_GET['code']) : '';

            if (!$verifyCode) {
                jsonResponse(1, '缺少验证码');
            }

            $sql = "SELECT pc.*, ps.name as stage_name, u.display_name as user_name
                    FROM pass_certificates pc
                    JOIN pass_stages ps ON pc.stage_id = ps.id
                    JOIN users u ON pc.user_id = u.id
                    WHERE pc.verify_code = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$verifyCode]);
            $cert = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cert) {
                jsonResponse(1, '证书不存在');
            }

            jsonResponse(0, 'success', [
                'is_valid' => true,
                'certificate' => [
                    'certificate_no' => $cert['certificate_no'],
                    'stage_name' => $cert['stage_name'],
                    'user_name' => $cert['user_name'],
                    'issued_at' => $cert['issued_at'],
                    'score' => $cert['score']
                ]
            ]);

        } else {
            jsonResponse(1, '未知操作');
        }
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
