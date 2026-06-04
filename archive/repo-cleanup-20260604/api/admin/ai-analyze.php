<?php
/**
 * AI智能分析API
 * POST /api/admin/ai-analyze.php
 * 功能：上传文件 → AI解析 → 智能分类 → 确认后入库
 */

require_once __DIR__ . '/common.php';
handleCORS();
adminRequireAuth('adminCanAccessHeadquarter');
require_once __DIR__ . '/../ai-runtime.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '只支持POST请求');
}

// 检查是否是导入确认请求（JSON body）
$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (strpos($contentType, 'application/json') !== false) {
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);

    if (isset($payload['action']) && $payload['action'] === 'import') {
        handleImport($payload);
        exit;
    }
}

// 文件上传处理
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $error = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    jsonResponse(1, '文件上传失败，错误码: ' . $error);
}

$file = $_FILES['file'];
$filename = $file['name'];
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$tmpfile = $file['tmp_name'];

// 允许的文件类型
$allowedExts = ['pdf', 'docx', 'doc', 'txt', 'png', 'jpg', 'jpeg'];
if (!in_array($ext, $allowedExts)) {
    jsonResponse(1, '不支持的文件格式: ' . $ext);
}

// 最大文件大小 20MB
$maxSize = 20 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    jsonResponse(1, '文件大小超过限制(最大20MB)');
}

try {
    // 1. 解析文件内容
    $content = extractFileContent($tmpfile, $ext);

    if (empty($content)) {
        jsonResponse(1, '无法解析文件内容，请确保文件包含可识别的文本');
    }

    // 2. 调用AI分析
    $analysis = analyzeContent($content, $filename);

    // 3. 返回分析结果
    jsonResponse(0, '分析完成', $analysis);

} catch (Exception $e) {
    error_log('AI Analyze Error: ' . $e->getMessage());
    jsonResponse(1, '分析失败: ' . $e->getMessage());
}

/**
 * 提取文件内容
 */
function extractFileContent($tmpfile, $ext) {
    $content = '';

    switch ($ext) {
        case 'txt':
            $content = file_get_contents($tmpfile);
            break;

        case 'png':
        case 'jpg':
        case 'jpeg':
            // 使用AI的OCR能力识别图片
            $content = '[图片文件，需要OCR识别]' . base64_encode(file_get_contents($tmpfile));
            break;

        case 'pdf':
            $content = extractPdfText($tmpfile);
            break;

        case 'docx':
            $content = extractDocxText($tmpfile);
            break;

        case 'doc':
            $content = extractDocText($tmpfile);
            break;

        default:
            $content = file_get_contents($tmpfile);
    }

    return trim($content);
}

/**
 * 提取PDF文本（简化版，实际可能需要更复杂的库）
 */
function extractPdfText($tmpfile) {
    // 尝试使用pdftotext命令（如果可用）
    if (function_exists('exec')) {
        $output = [];
        $return = 0;
        @exec('pdftotext -layout "' . $tmpfile . '" - 2>/dev/null', $output, $return);
        if ($return === 0 && !empty($output)) {
            return implode("\n", $output);
        }
    }

    // 如果没有pdftotext，尝试读取原始内容（可能包含乱码）
    $content = @file_get_contents($tmpfile);
    if ($content) {
        // 简单的文本提取尝试
        if (preg_match_all('/\((.*?)\)/s', $content, $matches)) {
            return implode("\n", array_filter($matches[1], function($m) {
                return strlen($m) > 3 && preg_match('/[\x{4e00}-\x{9fa5}]/u', $m);
            }));
        }
    }

    return '';
}

/**
 * 提取DOCX文本
 */
function extractDocxText($tmpfile) {
    $zip = new ZipArchive();
    if ($zip->open($tmpfile) === true) {
        $content = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($content) {
            // 去除XML标签，提取纯文本
            $content = preg_replace('/<[^>]+>/', ' ', $content);
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
            $content = preg_replace('/\s+/', ' ', $content);
            return trim($content);
        }
    }
    return '';
}

/**
 * 提取DOC文本（老格式，需要特殊处理）
 */
function extractDocText($tmpfile) {
    // DOC格式较难解析，尝试使用antiword或catdoc
    if (function_exists('exec')) {
        $output = [];
        $return = 0;
        @exec('antiword "' . $tmpfile . '" 2>/dev/null', $output, $return);
        if ($return === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        @exec('catdoc "' . $tmpfile . '" 2>/dev/null', $output, $return);
        if ($return === 0 && !empty($output)) {
            return implode("\n", $output);
        }
    }

    return '[DOC格式文件，建议转换为DOCX后重试]';
}

/**
 * 调用AI分析内容
 */
function analyzeContent($content, $filename) {
    $isImage = strpos($content, '[图片文件，需要OCR识别]') === 0;

    if ($isImage) {
        $prompt = "这是一个图片文件，请描述图片中的文字内容。直接返回图片中的文字，不要解释。";
        $imageData = substr($content, strlen('[图片文件，需要OCR识别]'));
        $result = callAIRuntime([
            'model' => 'glm-4v', // 视觉模型
            'image' => $imageData,
            'prompt' => $prompt
        ]);

        if (!empty($result)) {
            $content = $result;
        } else {
            throw new Exception('图片OCR识别失败');
        }
    }

    // 截取内容前4000字符进行分析
    $analyzeContent = mb_substr($content, 0, 4000, 'utf-8');

    // 构建分析prompt
    $analysisPrompt = <<<EOT
你是一个教育培训行业的AI助手，需要分析上传的文档内容，并判断它属于哪个类别。

## 内容类型判断

请根据内容判断它属于以下哪个分类：

### 话术知识库（4个维度）
1. **qa** - 问答话术：销售过程中的问答对白，包括客户常见问题及回答
2. **knowledge** - 专业知识：体适能、儿童发展等相关知识点
3. **feedback** - 课后点评/课前反馈：教练对学员的评价、反馈类内容
4. **deal** - 独立谈单：销售成交环节的话术和技巧

### 培训卡片
5. **K** - 知识点：需要学习的知识内容
6. **S** - 话术卡：需要练习的话术
7. **D** - 演练卡：需要实操演练的内容
8. **C** - 通关卡：用于通关评估的内容

### 其他
9. **policy** - 制度文档：公司制度、政策文件
10. **other** - 其他：不属于以上类别

## 输出格式

请以JSON格式返回分析结果：
{
    "category": "分类代码",
    "confidence": 0.0-1.0的置信度,
    "summary": "50字以内的内容摘要",
    "preview": "前500字的内容预览",
    "dimensions": ["qa", "knowledge"]（如果是话术知识库，可能有多个维度）,
    "target": "建议的目标板块，如'话术知识库'、'培训卡片'等",
    "data_type": "数据类型，如'问答话术'、'培训知识点'等",
    "module": "如果目标板块是培训卡片，指定模块名称"
}

## 内容如下：

{$analyzeContent}

请只返回JSON，不要有其他内容。
EOT;

    $result = callAIRuntime([
        'model' => 'glm-4-flashx',
        'prompt' => $analysisPrompt
    ]);

    // 解析AI返回的JSON
    $result = trim($result);
    // 去除可能的markdown代码块
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $result, $matches)) {
        $result = $matches[1];
    }
    // 去除首尾空白
    $result = trim($result);

    $analysis = json_decode($result, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // 如果JSON解析失败，返回默认分析
        return [
            'category' => 'other',
            'confidence' => 0.3,
            'summary' => mb_substr($content, 0, 50, 'utf-8'),
            'preview' => mb_substr($content, 0, 500, 'utf-8'),
            'dimensions' => [],
            'target' => '待分类',
            'data_type' => '未知',
            'module' => null,
            'raw_content' => $content
        ];
    }

    // 添加原始内容供后续导入使用
    $analysis['raw_content'] = $content;
    $analysis['filename'] = $filename;

    return $analysis;
}

/**
 * 处理导入请求
 */
function handleImport($payload) {
    $data = $payload['data'] ?? null;
    $filename = $payload['filename'] ?? 'unknown';

    if (!$data) {
        jsonResponse(1, '缺少导入数据');
    }

    $db = getDB();
    $target = $data['target'] ?? '';
    $category = $data['category'] ?? '';

    try {
        switch ($target) {
            case '话术知识库':
                importToScriptKnowledge($db, $data, $filename);
                break;

            case '培训卡片':
                importToTrainingCards($db, $data, $filename);
                break;

            default:
                // 保存为文件
                saveAsFile($data, $filename);
                break;
        }

        jsonResponse(0, '导入成功');

    } catch (Exception $e) {
        error_log('Import Error: ' . $e->getMessage());
        jsonResponse(1, '导入失败: ' . $e->getMessage());
    }
}

/**
 * 导入到话术知识库
 */
function importToScriptKnowledge($db, $data, $filename) {
    $dimensions = $data['dimensions'] ?? [$data['category']];
    $content = $data['raw_content'] ?? $data['summary'] ?? '';
    $summary = $data['summary'] ?? '';

    // 生成scene_code
    $sceneCode = 'AI_' . date('YmdHis') . '_' . substr(md5($filename), 0, 6);

    // 维度映射
    $dimensionMap = [
        'qa' => 1,
        'knowledge' => 2,
        'feedback' => 3,
        'deal' => 4
    ];

    $dimensionId = $dimensionMap[$dimensions[0]] ?? 1;

    // 提取关键词（从摘要中取前5个）
    $keywords = [];
    if (!empty($summary)) {
        $words = preg_split('/[，,、\s]+/', $summary);
        $keywords = array_slice(array_filter($words), 0, 5);
    }

    $sql = "INSERT INTO script_knowledge
            (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $dimensionId,
        $sceneCode,
        $summary ?: $filename,
        json_encode($keywords),
        $content,
        '来源：AI分析导入 - ' . $filename,
        100,
        1
    ]);
}

/**
 * 导入到培训卡片
 */
function importToTrainingCards($db, $data, $filename) {
    $content = $data['raw_content'] ?? $data['summary'] ?? '';
    $summary = $data['summary'] ?? '';

    // 生成card_code
    $cardCode = 'AI_' . date('YmdHis') . '_' . substr(md5($filename), 0, 6);

    // 默认模块
    $moduleId = 1;
    $moduleName = $data['module'] ?? '';

    if (!empty($moduleName)) {
        // 查找对应模块
        $stmt = $db->prepare("SELECT id FROM training_modules WHERE name LIKE ? LIMIT 1");
        $stmt->execute(['%' . $moduleName . '%']);
        $module = $stmt->fetch();
        if ($module) {
            $moduleId = $module['id'];
        }
    }

    // 卡片类型
    $cardTypeMap = ['K' => 'K', 'S' => 'S', 'D' => 'D', 'C' => 'C'];
    $cardType = $cardTypeMap[$data['category']] ?? 'K';

    $sql = "INSERT INTO training_cards
            (module_id, card_type, card_code, title, content, difficulty, score, sort_order, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $moduleId,
        $cardType,
        $cardCode,
        $summary ?: $filename,
        $content,
        'medium',
        100,
        100,
        1
    ]);
}

/**
 * 保存为文件
 */
function saveAsFile($data, $filename) {
    $uploadDir = __DIR__ . '/../../uploads/ai-analysis/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newFilename = time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    $targetPath = $uploadDir . $newFilename;

    $saveData = [
        'original_filename' => $filename,
        'analysis' => $data,
        'imported_at' => date('Y-m-d H:i:s')
    ];

    file_put_contents($targetPath, json_encode($saveData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * 调用AI运行时
 */
function callAIRuntime($params) {
    $model = $params['model'] ?? 'glm-4-flashx';
    $prompt = $params['prompt'] ?? '';

    // 调用现有的AI运行时
    $requestData = [
        'model' => $model,
        'prompt' => $prompt
    ];

    if (isset($params['image'])) {
        $requestData['image'] = $params['image'];
    }

    $response = callAI($requestData);

    if (isset($response['choices'][0]['message']['content'])) {
        return $response['choices'][0]['message']['content'];
    }

    return '';
}
