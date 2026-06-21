<?php
/**
 * 销售录音复盘上传与分析 API
 * POST /api/skill/upload-recording.php
 * 
 * 使用 fastcgi_finish_request() 实现异步处理：
 * 先返回响应给客户端，然后继续在后台执行转写和 AI 分析
 */

require_once __DIR__ . '/../../api/config.php';
handleCORS();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'message' => '只支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = getCurrentUserId();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['code' => 401, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$staff = getStaffByUserId($userId);
if (!$staff) {
    http_response_code(403);
    echo json_encode(['code' => 403, 'message' => '未找到员工资料'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sceneType = isset($_POST['scene_type']) ? trim($_POST['scene_type']) : '';
if (!in_array($sceneType, ['new_sale', 'renewal', 'assessment'])) {
    http_response_code(400);
    echo json_encode(['code' => 400, 'message' => '请选择复盘场景：新签/续费/体测解读'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sceneNames = [
    'new_sale' => '新签复盘',
    'renewal' => '续费复盘',
    'assessment' => '体测解读复盘',
];

if (!isset($_FILES['recording']) || $_FILES['recording']['error'] !== UPLOAD_ERR_OK) {
    $error = $_FILES['recording']['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code(400);
    echo json_encode(['code' => 400, 'message' => '录音文件上传失败，错误码: ' . $error], JSON_UNESCAPED_UNICODE);
    exit;
}

$recording = $_FILES['recording'];
// 安全过滤：只保留字母数字和点号，防止路径穿越和特殊字符注入
$rawExt = preg_replace('/[^a-zA-Z0-9.]/', '', pathinfo($recording['name'], PATHINFO_EXTENSION));
$ext = strtolower($rawExt);
$allowedExts = ['mp3', 'wav', 'm4a', 'ogg', 'webm', 'aac'];
if (!in_array($ext, $allowedExts)) {
    http_response_code(400);
    echo json_encode(['code' => 400, 'message' => '不支持的音频格式，请上传 mp3/wav/m4a/aac 格式'], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxSize = 50 * 1024 * 1024;
if ($recording['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['code' => 400, 'message' => '录音文件超过 50MB 限制'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadDir = '/www/wwwroot/122.51.223.46/uploads/review-recordings/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 安全文件名：使用 random_bytes 避免碰撞，不依赖用户输入
$filename = date('YmdHis') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
$savePath = $uploadDir . $filename;

if (!move_uploaded_file($recording['tmp_name'], $savePath)) {
    http_response_code(500);
    echo json_encode(['code' => 500, 'message' => '录音文件保存失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$recordingUrl = '/uploads/review-recordings/' . $filename;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    
    $stmt = $pdo->prepare("INSERT INTO skill_review_records 
        (user_id, staff_id, scene_type, recording_url, status) 
        VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$userId, (int)$staff['id'], $sceneType, $recordingUrl]);
    $recordId = (int)$pdo->lastInsertId();
    
} catch (Exception $e) {
    error_log('[skill.review] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['code' => 500, 'message' => '创建复盘记录失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 发送响应给客户端
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code' => 0,
    'message' => '录音已上传，将由后台自动分析',
    'data' => [
        'record_id' => $recordId,
        'scene_name' => $sceneNames[$sceneType],
        'recording_url' => $recordingUrl,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
