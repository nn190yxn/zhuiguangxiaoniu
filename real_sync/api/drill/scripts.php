<?php
/**
 * 演练话术API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if (!$userId) {
        jsonResponse(401, '请先登录');
    }

    if ($method === 'GET') {
        $templateId = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;

        if (!$templateId) {
            jsonResponse(1, '缺少演练模板ID');
        }

        $sql = "SELECT * FROM drill_scripts WHERE template_id = ? ORDER BY sort_order ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$templateId]);
        $scripts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($scripts as &$script) {
            $script['audio_url'] = $script['audio_url'] ? getResourceUrl($script['audio_url']) : null;
            $script['video_url'] = $script['video_url'] ? getResourceUrl($script['video_url']) : null;
        }

        jsonResponse(0, 'success', ['list' => $scripts]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    error_log('drill/scripts error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误，请稍后重试');
}
