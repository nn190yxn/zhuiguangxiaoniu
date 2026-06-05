<?php
/**
 * 演练录音上传API
 * POST /api/drill/upload-recording.php
 *
 * 功能：
 * 1. 接收录音文件
 * 2. 保存录音
 * 3. 调用AI进行转文本和分析
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '不支持的请求方法');
}

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if (!$userId) {
        jsonResponse(401, '请先登录');
    }

    // 获取POST数据
    $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $scriptId = isset($_POST['script_id']) ? (int)$_POST['script_id'] : 0;
    $step = isset($_POST['step']) ? (int)$_POST['step'] : 3;

    if (!$taskId || !$scriptId) {
        jsonResponse(1, '缺少必要参数：task_id 或 script_id');
    }

    // 检查是否有录音文件上传
    if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(1, '录音文件上传失败');
    }

    $audioFile = $_FILES['audio'];

    // 验证文件类型
    $allowedTypes = ['audio/mpeg', 'audio/wav', 'audio/mp3', 'audio/x-wav', 'audio/m4a', 'audio/aac', 'audio/mp4', 'audio/x-m4a', 'application/octet-stream'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $audioFile['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonResponse(1, '不支持的音频格式，请上传MP3、WAV或M4A格式');
    }

    // 验证文件大小 (最大10MB)
    $maxSize = 10 * 1024 * 1024;
    if ($audioFile['size'] > $maxSize) {
        jsonResponse(1, '音频文件过大，请控制在10MB以内');
    }

    // 获取话术信息
    $scriptSql = "SELECT ds.*, dt.id AS template_id FROM drill_scripts ds
                  JOIN drill_templates dt ON ds.template_id = dt.id
                  WHERE ds.id = ?";
    $stmt = $db->prepare($scriptSql);
    $stmt->execute([$scriptId]);
    $script = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$script) {
        jsonResponse(1, '话术不存在');
    }

    // 验证任务归属
    $taskSql = "SELECT * FROM user_drill_tasks WHERE id = ? AND user_id = ?";
    $stmt = $db->prepare($taskSql);
    $stmt->execute([$taskId, $userId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        jsonResponse(1, '任务不存在或无权操作');
    }

    // 创建上传目录
    $uploadDir = dirname(__DIR__) . '/wp-content/uploads/drill-recordings/' . date('Y/m/d/');
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        jsonResponse(1, '无法创建上传目录');
    }

    // 生成唯一文件名
    $extension = pathinfo($audioFile['name'], PATHINFO_EXTENSION) ?: 'mp3';
    $fileName = sprintf('recording_%d_%d_%d_%s.%s',
        $userId, $taskId, $scriptId,
        bin2hex(random_bytes(8)),
        $extension);
    $filePath = $uploadDir . $fileName;
    $fileUrl = '/wp-content/uploads/drill-recordings/' . date('Y/m/d/') . $fileName;

    // 移动文件
    if (!move_uploaded_file($audioFile['tmp_name'], $filePath)) {
        jsonResponse(1, '文件保存失败');
    }

    // 获取录音时长（如果可用）
    $audioDuration = isset($_POST['duration']) ? (int)$_POST['duration'] : 0;

    // 保存录音记录
    $insertSql = "INSERT INTO drill_recordings
                  (task_id, user_id, script_id, step, audio_url, audio_duration, file_size, status)
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
    $stmt = $db->prepare($insertSql);
    $stmt->execute([
        $taskId, $userId, $scriptId, $step,
        $fileUrl, $audioDuration, $audioFile['size']
    ]);
    $recordingId = $db->lastInsertId();

    // 异步调用AI分析（这里直接调用，也可以用队列）
    $analysisResult = analyzeRecording($db, $recordingId, $userId, $scriptId, $filePath, $fileUrl, $step);

    jsonResponse(0, 'success', [
        'recording_id' => $recordingId,
        'audio_url' => $fileUrl,
        'duration' => $audioDuration,
        'ai_feedback' => $analysisResult
    ]);

} catch (Exception $e) {
    error_log('upload-recording error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误，请稍后重试');
}

/**
 * 分析录音
 */
function analyzeRecording($db, $recordingId, $userId, $scriptId, $audioPath, $audioUrl, $step = 3) {
    $startTime = microtime(true);

    try {
        // 更新状态为处理中
        $updateSql = "UPDATE drill_recordings SET status = 'processing' WHERE id = ?";
        $db->prepare($updateSql)->execute([$recordingId]);

        // 获取话术内容 - 支持新旧两种表结构
        $script = null;
        $dimensionCode = 'qa';
        $dimensionId = 1;

        // 尝试从新表 script_knowledge 获取
        $newScriptSql = "SELECT sk.*, sd.dimension_code, sd.dimension_name, sd.id as dim_id
                         FROM script_knowledge sk
                         JOIN script_dimensions sd ON sk.dimension_id = sd.id
                         WHERE sk.id = ? AND sk.status = 1";
        $stmt = $db->prepare($newScriptSql);
        $stmt->execute([$scriptId]);
        $script = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($script) {
            $dimensionCode = $script['dimension_code'];
            $dimensionId = $script['dim_id'];
            $standardScript = $script['standard_script'];
            $tips = $script['tips'] ?: '';
        } else {
            // 兼容旧表 drill_scripts
            $oldScriptSql = "SELECT ds.*, dt.id AS template_id FROM drill_scripts ds
                             JOIN drill_templates dt ON ds.template_id = dt.id
                             WHERE ds.id = ?";
            $stmt = $db->prepare($oldScriptSql);
            $stmt->execute([$scriptId]);
            $oldScript = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldScript) {
                throw new Exception('话术不存在');
            }
            $standardScript = $oldScript['content'];
            $tips = $oldScript['tips'] ?: '';
            $dimensionCode = 'qa';
            $dimensionId = 1;
        }

        $transcribedText = recognizeAudioText($audioPath);
        if (!$transcribedText) {
            throw new Exception('语音识别失败');
        }

        // 调用AI分析转写后的文本
        $analysisResult = analyzeScriptByDimension($transcribedText, $standardScript, $tips, $dimensionCode);

        // 保存到新表 script_analysis_records
        $insertSql = "INSERT INTO script_analysis_records
                       (user_id, dimension_id, script_id, audio_url, transcribed_text, dialogue_analysis,
                        customer_intent, intent_signals, flow_analysis, missing_steps,
                        total_score, level, ai_feedback, suggestions, model_used, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')";

        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $userId,
            $dimensionId,
            $scriptId,
            $audioUrl,
            $analysisResult['transcribed_text'],
            json_encode($analysisResult['dialogue_analysis'] ?? []),
            $analysisResult['customer_intent'] ?? null,
            json_encode($analysisResult['intent_signals'] ?? []),
            json_encode($analysisResult['flow_analysis'] ?? []),
            json_encode($analysisResult['missing_steps'] ?? []),
            $analysisResult['total_score'] ?? 0,
            $analysisResult['level'] ?? 'fail',
            $analysisResult['feedback'] ?? '',
            json_encode($analysisResult['suggestions'] ?? []),
            $analysisResult['model'] ?? 'glm-4-flashx'
        ]);

        $analysisId = $db->lastInsertId();

        // 兼容旧反馈查询接口。
        $legacySql = "INSERT INTO script_ai_feedback
                       (recording_id, user_id, script_id, transcribed_text, dimension_scores,
                        total_score, level, feedback, suggestions, model_used, processing_time)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $db->prepare($legacySql)->execute([
            $recordingId,
            $userId,
            $scriptId,
            $analysisResult['transcribed_text'] ?? '',
            json_encode($analysisResult['dimension_scores'] ?? []),
            $analysisResult['total_score'] ?? 0,
            $analysisResult['level'] ?? 'fail',
            $analysisResult['feedback'] ?? '',
            json_encode($analysisResult['suggestions'] ?? []),
            $analysisResult['model'] ?? 'glm-4-flashx',
            round(microtime(true) - $startTime, 3)
        ]);

        // 更新录音记录状态
        $updateSql = "UPDATE drill_recordings SET status = 'completed' WHERE id = ?";
        $db->prepare($updateSql)->execute([$recordingId]);

        // 返回分析结果
        return [
            'feedback_id' => $analysisId,
            'total_score' => $analysisResult['total_score'] ?? 0,
            'level' => $analysisResult['level'] ?? 'fail',
            'feedback' => $analysisResult['feedback'] ?? '',
            'transcribed_text' => mb_substr($analysisResult['transcribed_text'] ?? '', 0, 100) . '...',
            'dimension_code' => $dimensionCode
        ];

    } catch (Exception $e) {
        // 更新状态为失败
        $updateSql = "UPDATE drill_recordings SET status = 'failed' WHERE id = ?";
        $db->prepare($updateSql)->execute([$recordingId]);

        throw $e;
    }
}

/**
 * 根据维度调用不同的AI分析
 */
function analyzeScriptByDimension($transcribedText, $standardScript, $tips, $dimensionCode) {
    $settings = ai_load_settings();
    $apiKey = $settings['zhipu_api_key'] ?? '';

    if (empty($apiKey)) {
        throw new Exception('AI服务未配置');
    }

    // 构建基础prompt
    $standardPart = $standardScript ? "\n\n参考标准话术：\n{$standardScript}" : '';
    $tipsPart = $tips ? "\n\n教练提示：\n{$tips}" : '';

    // 根据维度选择不同的分析prompt
    switch ($dimensionCode) {
        case 'qa':
            $prompt = buildQAPrompt($transcribedText, $standardPart, $tipsPart);
            break;
        case 'knowledge':
            $prompt = buildKnowledgePrompt($transcribedText, $standardPart, $tipsPart);
            break;
        case 'feedback':
            $prompt = buildFeedbackPrompt($transcribedText, $standardPart, $tipsPart);
            break;
        case 'deal':
            $prompt = buildDealPrompt($transcribedText, $tipsPart);
            break;
        default:
            $prompt = buildQAPrompt($transcribedText, $standardPart, $tipsPart);
    }

    // 调用智谱API
    $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';

    $data = [
        'model' => 'glm-4-flashx',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.3
    ];

    $result = ai_post_json($url, ['Authorization' => 'Bearer ' . $apiKey], $data, 60);

    if (($result['status'] ?? 0) >= 300) {
        throw new Exception('AI服务调用失败');
    }

    $content = $result['body']['choices'][0]['message']['content'] ?? '';

    return parseAIResponse($content, $dimensionCode);
}

function recognizeAudioText($audioPath) {
    $settings = ai_load_settings();
    $apiKey = $settings['zhipu_api_key'] ?? '';
    if (empty($apiKey)) {
        throw new Exception('AI服务未配置');
    }

    $mimeType = mime_content_type($audioPath) ?: 'audio/mpeg';
    $ch = curl_init('https://open.bigmodel.cn/api/paas/v4/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => [
            'model' => 'glm-asr-2512',
            'stream' => 'false',
            'file' => new CURLFile($audioPath, $mimeType, basename($audioPath))
        ],
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('语音识别请求失败：' . $error);
    }
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    if ($status >= 300 || !is_array($decoded)) {
        throw new Exception('语音识别服务调用失败');
    }

    return trim($decoded['text'] ?? ($decoded['result'] ?? ''));
}

/**
 * 构建问答话术分析Prompt
 */
function buildQAPrompt($text, $standardPart, $tipsPart) {
    return <<<EOT
你是专业的儿童体能培训销售教练。请分析以下销售话术，判断其回答客户问题的质量。

【客户问题回复录音文本】：
{$text}
{$standardPart}
{$tipsPart}

请从以下维度评估（每项100分）：
1. 专业性：是否用专业、通俗的话术解释感统和体能知识
2. 逻辑性：回答是否有条理，结构清晰
3. 亲和力：语气是否友好、有感染力
4. 完整性：是否完整回答了客户的问题

请以JSON格式返回评估结果：
{
  "transcribed_text": "转录的文本内容",
  "dimension_scores": {
    "professional": 分数,
    "logical": 分数,
    "affinity": 分数,
    "completeness": 分数
  },
  "total_score": 总分,
  "level": "excellent/good/pass/fail",
  "feedback": "详细反馈（指出优点和不足）",
  "suggestions": ["改进建议1", "改进建议2"]
}
EOT;
}

/**
 * 构建专业知识讲解分析Prompt
 */
function buildKnowledgePrompt($text, $standardPart, $tipsPart) {
    return <<<EOT
你是专业的儿童体能培训教练。请分析以下专业知识点讲解话术。

【讲解录音文本】：
{$text}
{$standardPart}
{$tipsPart}

请从以下维度评估（每项100分）：
1. 准确性：专业术语使用是否正确
2. 通俗性：是否能把专业内容讲得通俗易懂
3. 生动性：是否有例子、类比，让内容更易理解
4. 结构清晰：是否有条理地展开讲解

请以JSON格式返回评估结果：
{
  "transcribed_text": "转录的文本内容",
  "dimension_scores": {
    "accuracy": 分数,
    "clarity": 分数,
    "vividness": 分数,
    "structure": 分数
  },
  "total_score": 总分,
  "level": "excellent/good/pass/fail",
  "feedback": "详细反馈",
  "suggestions": ["改进建议1", "改进建议2"]
}
EOT;
}

/**
 * 构建课后点评/课前反馈分析Prompt
 */
function buildFeedbackPrompt($text, $standardPart, $tipsPart) {
    return <<<EOT
你是专业的儿童体能培训教练。请分析以下家长沟通话术（课后点评/课前反馈）。

【沟通录音文本】：
{$text}
{$standardPart}
{$tipsPart}

请从以下维度评估（每项100分）：
1. 完整性：是否包含必要的沟通要素（孩子表现、具体描述、下一步建议）
2. 专业性：描述是否准确使用专业术语
3. 温暖度：表达是否让家长感到温暖、被重视
4. 实用性：给出的建议是否具体可操作

请以JSON格式返回评估结果：
{
  "transcribed_text": "转录的文本内容",
  "dimension_scores": {
    "completeness": 分数,
    "professional": 分数,
    "warmth": 分数,
    "practicality": 分数
  },
  "total_score": 总分,
  "level": "excellent/good/pass/fail",
  "feedback": "详细反馈",
  "suggestions": ["改进建议1", "改进建议2"]
}
EOT;
}

/**
 * 构建谈单录音分析Prompt（包含客户意向判断）
 */
function buildDealPrompt($text, $tipsPart) {
    return <<<EOT
你是专业的儿童体能培训销售教练。请分析以下完整谈单录音，包含销售话术和客户回复。

【谈单录音文本（销售:S,客户:C）】：
{$text}
{$tipsPart}

【标准销售流程】：
1. 开场问候
2. 自我介绍
3. 需求了解 - 询问孩子年龄、目标、现状
4. 课程介绍 - 介绍课程内容和价值
5. 异议处理 - 回应价格、时间、效果等顾虑
6. 促成关单 - 邀请报名、体验、预约时间
7. 礼貌告别

【客户意向信号识别】：
- 高意向信号：主动询问价格/如何报名/体验时间/带孩子来体验
- 中意向信号：愿意听但态度中立/说"我再考虑"等
- 低意向信号：直接拒绝/表示没兴趣/明显敷衍

请从以下维度评估（每项100分）：
1. 流程完整性：是否完成所有销售步骤
2. 流程逻辑：是否按正确顺序进行
3. 客户意向判断：是否正确识别客户意向并有效应对
4. 关键要点覆盖：是否覆盖销售话术的关键要点
5. 异议处理质量：对客户异议的处理是否恰当

请以JSON格式返回评估结果：
{
  "transcribed_text": "转录的文本内容",
  "dimension_scores": {
    "flow_completeness": 分数,
    "flow_sequence": 分数,
    "customer_intent_judgment": 分数,
    "key_points_coverage": 分数,
    "objection_handling": 分数
  },
  "customer_intent": "high/medium/low",
  "intent_signals": [
    {"speaker": "客户", "content": "客户说的话", "intent": "high", "sales_response": "销售是否恰当应对"}
  ],
  "flow_analysis": {
    "steps_covered": ["已完成的步骤"],
    "steps_order": "顺序是否正确"
  },
  "missing_steps": ["遗漏的步骤"],
  "total_score": 总分,
  "level": "excellent/good/pass/fail",
  "feedback": "详细反馈",
  "suggestions": ["改进建议1", "改进建议2"]
}
EOT;
}

/**
 * 解析AI响应
 */
function parseAIResponse($content, $dimension) {
    $scores = ['total_score' => 0, 'level' => 'fail', 'dimension_scores' => []];
    $intentSignals = [];
    $flowAnalysis = [];
    $missingSteps = [];
    $feedback = '';
    $suggestions = [];
    $customerIntent = null;

    // 尝试提取JSON
    if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
        $data = json_decode($matches[0], true);
        if (is_array($data)) {
            $scores = [
                'total_score' => (int)($data['total_score'] ?? 0),
                'level' => $data['level'] ?? 'fail',
                'dimension_scores' => $data['dimension_scores'] ?? []
            ];
            $intentSignals = $data['intent_signals'] ?? [];
            $flowAnalysis = $data['flow_analysis'] ?? [];
            $missingSteps = $data['missing_steps'] ?? [];
            $feedback = $data['feedback'] ?? '';
            $suggestions = $data['suggestions'] ?? [];
            $customerIntent = $data['customer_intent'] ?? null;
        }
    }

    // 计算总分
    if (empty($scores['dimension_scores'])) {
        $scores['total_score'] = 0;
        $scores['level'] = 'fail';
    } else {
        $scores['total_score'] = array_sum($scores['dimension_scores']) / count($scores['dimension_scores']);
        if ($scores['total_score'] >= 90) {
            $scores['level'] = 'excellent';
        } elseif ($scores['total_score'] >= 75) {
            $scores['level'] = 'good';
        } elseif ($scores['total_score'] >= 60) {
            $scores['level'] = 'pass';
        } else {
            $scores['level'] = 'fail';
        }
    }

    return [
        'transcribed_text' => $data['transcribed_text'] ?? '[转录文本]',
        'total_score' => $scores['total_score'],
        'level' => $scores['level'],
        'dimension_scores' => $scores['dimension_scores'],
        'customer_intent' => $customerIntent,
        'intent_signals' => $intentSignals,
        'flow_analysis' => $flowAnalysis,
        'missing_steps' => $missingSteps,
        'feedback' => $feedback,
        'suggestions' => $suggestions,
        'dialogue_analysis' => $scores['dimension_scores'],
        'model' => 'glm-4-flashx'
    ];
}

/**
 * 发送JSON POST请求
 */
function ai_post_json($url, $headers, $payload, $timeout = 45) {
    $headerLines = ['Content-Type: application/json'];
    foreach ($headers as $key => $value) {
        $headerLines[] = "$key: $value";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headerLines),
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;

    foreach ($responseHeaders as $line) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
            $status = (int)$matches[1];
            break;
        }
    }

    $decoded = json_decode((string)$response, true);
    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : []
    ];
}
