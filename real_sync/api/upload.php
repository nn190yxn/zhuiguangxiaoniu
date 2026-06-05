<?php
/**
 * 管理员资料上传API
 * POST /api/admin/upload.php
 * 支持: script-knowledge, training-cards, policy, image
 */

require_once __DIR__ . '/admin/common.php';

handleCORS();
header('Content-Type: application/json; charset=utf-8');

adminRequireAuth('adminCanAccessHeadquarter');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '只支持POST请求');
}

// 获取上传类型
$type = isset($_POST['type']) ? $_POST['type'] : '';

// 检查文件
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $error = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    jsonResponse(1, '文件上传失败，错误码: ' . $error);
}

$file = $_FILES['file'];
$filename = $file['name'];
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$filesize = $file['size'];
$tmpfile = $file['tmp_name'];

// 允许的文件类型
$allowed = ['json' => ['json'], 'training-cards' => ['json', 'xlsx', 'csv'], 'policy' => ['pdf', 'docx', 'doc', 'md'], 'image' => ['png', 'jpg', 'jpeg', 'gif']];

if (!isset($allowed[$type])) {
    jsonResponse(1, '无效的上传类型');
}

if (!in_array($ext, $allowed[$type])) {
    jsonResponse(1, '不支持的文件格式: ' . $ext);
}

// 最大文件大小 50MB
$maxSize = 50 * 1024 * 1024;
if ($filesize > $maxSize) {
    jsonResponse(1, '文件大小超过限制(最大50MB)');
}

try {
    $db = getDB();

    switch ($type) {
        case 'script-knowledge':
            $result = importScriptKnowledge($db, $tmpfile, $ext);
            break;
        case 'training-cards':
            $result = importTrainingCards($db, $tmpfile, $ext);
            break;
        case 'policy':
            $result = savePolicyFile($tmpfile, $filename, $ext);
            break;
        case 'image':
            $result = saveImageFile($tmpfile, $filename, $ext);
            break;
        default:
            jsonResponse(1, '未知的上传类型');
    }

    jsonResponse(0, 'success', $result);

} catch (Exception $e) {
    error_log('Upload error: ' . $e->getMessage());
    jsonResponse(1, '处理失败: ' . $e->getMessage());
}

function importScriptKnowledge($db, $tmpfile, $ext) {
    // 读取文件内容
    if ($ext === 'json') {
        $content = file_get_contents($tmpfile);
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON解析失败: ' . json_last_error_msg());
        }
    } else {
        throw new Exception('话术知识库仅支持JSON格式');
    }

    if (!is_array($data)) {
        throw new Exception('数据格式错误，应该是数组格式');
    }

    $imported = 0;
    $updated = 0;

    foreach ($data as $item) {
        if (empty($item['scene_code']) || empty($item['scene_name'])) {
            continue;
        }

        $sceneCode = trim($item['scene_code']);
        $dimensionId = isset($item['dimension_id']) ? (int)$item['dimension_id'] : 1;
        $sceneName = trim($item['scene_name']);
        $keywords = isset($item['keywords']) ? (is_array($item['keywords']) ? json_encode($item['keywords']) : $item['keywords']) : '[]';
        $standardScript = $item['standard_script'] ?? '';
        $tips = $item['tips'] ?? '';
        $customerIntent = isset($item['customer_intent_signals']) ? (is_array($item['customer_intent_signals']) ? json_encode($item['customer_intent_signals']) : $item['customer_intent_signals']) : '[]';
        $sortOrder = isset($item['sort_order']) ? (int)$item['sort_order'] : 100;
        $status = isset($item['status']) ? (int)$item['status'] : 1;

        // 检查是否已存在
        $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
        $stmt->execute([$sceneCode]);
        $existing = $stmt->fetch();

        if ($existing) {
            $sql = "UPDATE script_knowledge SET
                    dimension_id = ?, scene_name = ?, keywords = ?, standard_script = ?,
                    tips = ?, customer_intent_signals = ?, sort_order = ?, status = ?
                    WHERE scene_code = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$dimensionId, $sceneName, $keywords, $standardScript, $tips, $customerIntent, $sortOrder, $status, $sceneCode]);
            $updated++;
        } else {
            $sql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, customer_intent_signals, sort_order, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$dimensionId, $sceneCode, $sceneName, $keywords, $standardScript, $tips, $customerIntent, $sortOrder, $status]);
            $imported++;
        }
    }

    return [
        'imported' => $imported,
        'updated' => $updated,
        'total' => $imported + $updated
    ];
}

function importTrainingCards($db, $tmpfile, $ext) {
    // 读取文件内容
    if ($ext === 'json') {
        $content = file_get_contents($tmpfile);
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON解析失败: ' . json_last_error_msg());
        }
    } else {
        throw new Exception('培训卡片仅支持JSON格式');
    }

    if (!is_array($data)) {
        throw new Exception('数据格式错误，应该是数组格式');
    }

    $imported = 0;
    $updated = 0;

    foreach ($data as $item) {
        if (empty($item['card_code']) || empty($item['title'])) {
            continue;
        }

        $cardCode = trim($item['card_code']);
        $moduleId = isset($item['module_id']) ? (int)$item['module_id'] : 1;
        $cardType = isset($item['card_type']) ? strtoupper($item['card_type']) : 'K';
        $title = trim($item['title']);
        $content = $item['content'] ?? '';
        $tips = $item['tips'] ?? '';
        $options = isset($item['options']) ? (is_array($item['options']) ? json_encode($item['options']) : $item['options']) : null;
        $standardAnswer = $item['standard_answer'] ?? null;
        $difficulty = $item['difficulty'] ?? 'medium';
        $score = isset($item['score']) ? (int)$item['score'] : 100;
        $sortOrder = isset($item['sort_order']) ? (int)$item['sort_order'] : 0;
        $status = isset($item['status']) ? (int)$item['status'] : 1;

        // 检查是否已存在
        $stmt = $db->prepare("SELECT id FROM training_cards WHERE card_code = ?");
        $stmt->execute([$cardCode]);
        $existing = $stmt->fetch();

        if ($existing) {
            $sql = "UPDATE training_cards SET
                    module_id = ?, card_type = ?, title = ?, content = ?, tips = ?,
                    options = ?, standard_answer = ?, difficulty = ?, score = ?,
                    sort_order = ?, status = ?
                    WHERE card_code = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$moduleId, $cardType, $title, $content, $tips, $options, $standardAnswer, $difficulty, $score, $sortOrder, $status, $cardCode]);
            $updated++;
        } else {
            $sql = "INSERT INTO training_cards (module_id, card_type, card_code, title, content, tips, options, standard_answer, difficulty, score, sort_order, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$moduleId, $cardType, $cardCode, $title, $content, $tips, $options, $standardAnswer, $difficulty, $score, $sortOrder, $status]);
            $imported++;
        }
    }

    return [
        'imported' => $imported,
        'updated' => $updated,
        'total' => $imported + $updated
    ];
}

function savePolicyFile($tmpfile, $filename, $ext) {
    $uploadDir = __DIR__ . '/../../uploads/policy/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // 使用 UUID 重命名，防止路径穿越和文件名冲突
    $uuid = bin2hex(random_bytes(16));
    $newFilename = $uuid . '.' . $ext;
    $targetPath = $uploadDir . $newFilename;

    if (!move_uploaded_file($tmpfile, $targetPath)) {
        throw new Exception('文件保存失败');
    }

    return [
        'filename' => $newFilename,
        'original_name' => $filename,
        'path' => '/uploads/policy/' . $newFilename
    ];
}

function saveImageFile($tmpfile, $filename, $ext) {
    $uploadDir = __DIR__ . '/../../uploads/images/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // 验证图片
    $imageInfo = @getimagesize($tmpfile);
    if (!$imageInfo) {
        throw new Exception('无效的图片文件');
    }

    // 使用 UUID 重命名，防止路径穿越和文件名冲突
    $uuid = bin2hex(random_bytes(16));
    $newFilename = $uuid . '.' . $ext;
    $targetPath = $uploadDir . $newFilename;

    if (!move_uploaded_file($tmpfile, $targetPath)) {
        throw new Exception('文件保存失败');
    }

    return [
        'filename' => $newFilename,
        'original_name' => $filename,
        'path' => '/uploads/images/' . $newFilename,
        'size' => filesize($targetPath)
    ];
}
