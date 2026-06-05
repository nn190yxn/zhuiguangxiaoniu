<?php
/**
 * 录音话术分析API v2
 * POST /api/drill/analyze-script.php
 *
 * 支持4个维度的话术分析：
 * 1. qa - 问答话术（客户问什么，怎么答）
 * 2. knowledge - 专业知识点讲解
 * 3. feedback - 课后点评/课前反馈
 * 4. deal - 独立谈单录音（包含客户意向判断）
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

    $data = json_decode(file_get_contents('php://input'), true);

    $dimension = isset($data['dimension']) ? trim($data['dimension']) : 'qa';
    $scriptId = isset($data['script_id']) ? (int)$data['script_id'] : 0;
    $transcribedText = isset($data['transcribed_text']) ? trim($data['transcribed_text']) : '';
    $audioUrl = isset($data['audio_url']) ? trim($data['audio_url']) : '';

    if (empty($transcribedText)) {
        jsonResponse(1, '缺少转录文本');
    }

    // 获取维度信息
    $dimSql = "SELECT * FROM script_dimensions WHERE dimension_code = ? AND status = 1";
    $stmt = $db->prepare($dimSql);
    $stmt->execute([$dimension]);
    $dimensionInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dimensionInfo) {
        jsonResponse(1, '无效的分析维度');
    }

    // 获取标准话术作为参考
    $standardScript = '';
    $tips = '';
    if ($scriptId > 0) {
        $scriptSql = "SELECT * FROM script_knowledge WHERE id = ? AND dimension_id = ?";
        $stmt = $db->prepare($scriptSql);
        $stmt->execute([$scriptId, $dimensionInfo['id']]);
        $scriptInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($scriptInfo) {
            $standardScript = $scriptInfo['standard_script'];
            $tips = $scriptInfo['tips'];
        }
    }

    // 调用AI分析
    $startTime = microtime(true);
    $analysisResult = analyzeScript($db, $userId, $dimension, $dimensionInfo, $transcribedText, $standardScript, $tips);

    // 保存分析记录
    $insertSql = "INSERT INTO script_analysis_records
                   (user_id, dimension_id, script_id, audio_url, transcribed_text, dialogue_analysis,
                    customer_intent, intent_signals, flow_analysis, missing_steps,
                    total_score, level, ai_feedback, suggestions, model_used, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')";

    $stmt = $db->prepare($insertSql);
    $stmt->execute([
        $userId,
        $dimensionInfo['id'],
        $scriptId ?: null,
        $audioUrl ?: null,
        $transcribedText,
        json_encode($analysisResult['dialogue_analysis'] ?? []),
        $analysisResult['customer_intent'] ?? null,
        json_encode($analysisResult['intent_signals'] ?? []),
        json_encode($analysisResult['flow_analysis'] ?? []),
        json_encode($analysisResult['missing_steps'] ?? []),
        $analysisResult['total_score'] ?? 0,
        $analysisResult['level'] ?? 'fail',
        $analysisResult['feedback'] ?? '',
        json_encode($analysisResult['suggestions'] ?? []),
        $analysisResult['model'] ?? 'glm-4'
    ]);

    $analysisId = $db->lastInsertId();

    jsonResponse(0, 'success', [
        'analysis_id' => $analysisId,
        'dimension' => $dimension,
        'dimension_name' => $dimensionInfo['dimension_name'],
        'total_score' => $analysisResult['total_score'] ?? 0,
        'level' => $analysisResult['level'] ?? 'fail',
        'level_name' => getLevelName($analysisResult['level'] ?? 'fail'),
        'customer_intent' => $analysisResult['customer_intent'] ?? null,
        'intent_signals' => $analysisResult['intent_signals'] ?? [],
        'flow_analysis' => $analysisResult['flow_analysis'] ?? [],
        'missing_steps' => $analysisResult['missing_steps'] ?? [],
        'feedback' => $analysisResult['feedback'] ?? '',
        'suggestions' => $analysisResult['suggestions'] ?? [],
        'dimension_scores' => $analysisResult['dimension_scores'] ?? [],
        'processing_time' => round((microtime(true) - $startTime) * 1000)
    ]);

} catch (Exception $e) {
    error_log('analyze-script error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误：' . $e->getMessage());
}

/**
 * AI话术分析
 */
function analyzeScript($db, $userId, $dimension, $dimensionInfo, $transcribedText, $standardScript, $tips) {
    // 根据维度构建不同的分析Prompt
    switch ($dimension) {
        case 'qa':
            return analyzeQA($transcribedText, $standardScript, $tips);
        case 'knowledge':
            return analyzeKnowledge($transcribedText, $standardScript, $tips);
        case 'feedback':
            return analyzeFeedback($transcribedText, $standardScript, $tips);
        case 'deal':
            return analyzeDeal($transcribedText, $standardScript, $tips);
        default:
            return analyzeQA($transcribedText, $standardScript, $tips);
    }
}

/**
 * 维度1：问答话术分析
 */
function analyzeQA($text, $standardScript, $tips) {
    $prompt = buildQAPrompt($text, $standardScript, $tips);
    $result = callAI($prompt);

    if (!empty($result['error'])) {
        throw new Exception($result['error']);
    }

    return parseAIResponse($result['content'], 'qa');
}

/**
 * 维度2：专业知识讲解分析
 */
function analyzeKnowledge($text, $standardScript, $tips) {
    $prompt = buildKnowledgePrompt($text, $standardScript, $tips);
    $result = callAI($prompt);

    if (!empty($result['error'])) {
        throw new Exception($result['error']);
    }

    return parseAIResponse($result['content'], 'knowledge');
}

/**
 * 维度3：课后点评/课前反馈分析
 */
function analyzeFeedback($text, $standardScript, $tips) {
    $prompt = buildFeedbackPrompt($text, $standardScript, $tips);
    $result = callAI($prompt);

    if (!empty($result['error'])) {
        throw new Exception($result['error']);
    }

    return parseAIResponse($result['content'], 'feedback');
}

/**
 * 维度4：独立谈单录音分析（包含客户意向）
 */
function analyzeDeal($text, $standardScript, $tips) {
    $prompt = buildDealPrompt($text, $standardScript, $tips);
    $result = callAI($prompt);

    if (!empty($result['error'])) {
        throw new Exception($result['error']);
    }

    return parseAIResponse($result['content'], 'deal');
}

/**
 * 构建问答话术分析Prompt
 */
function buildQAPrompt($text, $standardScript, $tips) {
    $standardPart = $standardScript ? "\n\n参考标准话术：\n{$standardScript}" : '';
    $tipsPart = $tips ? "\n\n教练提示：\n{$tips}" : '';

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
function buildKnowledgePrompt($text, $standardScript, $tips) {
    $standardPart = $standardScript ? "\n\n参考标准讲解：\n{$standardScript}" : '';
    $tipsPart = $tips ? "\n\n教练提示：\n{$tips}" : '';

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
function buildFeedbackPrompt($text, $standardScript, $tips) {
    $standardPart = $standardScript ? "\n\n参考标准模板：\n{$standardScript}" : '';
    $tipsPart = $tips ? "\n\n教练提示：\n{$tips}" : '';

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
 * 谈单7环节：开场接待、需求了解、专业展示、方案介绍、异议处理、逼单促成、礼貌告别
 */
function buildDealPrompt($text, $standardScript, $tips) {
    $tipsPart = $tips ? "\n\n教练提示：\n{$tips}" : '';

    return <<<EOT
你是专业的儿童体能培训销售教练。请分析以下完整谈单录音，对销售7个环节分别给出详细反馈。

【谈单录音文本（销售:S,客户:C）】：
{$text}
{$tipsPart}

【标准销售7步流程】：
1. 开场接待 - 破冰、建立氛围、叫出孩子名字、介绍环境
2. 需求了解 - 询问孩子年龄、目标、现状、痛点
3. 专业展示 - 展示专业知识、建立信任、讲解感统/体能概念
4. 方案介绍 - 根据年龄和问题推荐课程、讲解课程价值
5. 异议处理 - 处理价格、时间、孩子意愿等顾虑
6. 逼单促成 - 邀请报名、设置紧迫感、优惠逼单
7. 礼貌告别 - 约定下次联系、感谢、发送资料

【客户意向信号识别】：
- 高意向信号：主动询问价格/如何报名/体验时间/带孩子来体验
- 中意向信号：愿意听但态度中立/说"我再考虑"等
- 低意向信号：直接拒绝/表示没兴趣/明显敷衍

请按以下JSON格式返回评估结果（每个环节单独评分和反馈）：
{
  "stage_scores": {
    "reception": {"score": 分数, "feedback": "环节反馈（好的地方和不足）"},
    "demand": {"score": 分数, "feedback": "环节反馈"},
    "professional": {"score": 分数, "feedback": "环节反馈"},
    "solution": {"score": 分数, "feedback": "环节反馈"},
    "objection": {"score": 分数, "feedback": "环节反馈"},
    "closing": {"score": 分数, "feedback": "环节反馈"},
    "farewell": {"score": 分数, "feedback": "环节反馈"}
  },
  "dimension_scores": {
    "reception": 分数,
    "demand": 分数,
    "professional": 分数,
    "solution": 分数,
    "objection": 分数,
    "closing": 分数,
    "farewell": 分数
  },
  "customer_intent": "high/medium/low",
  "intent_signals": [
    {"speaker": "客户", "content": "客户说的话", "intent": "high/medium/low", "stage": "环节", "sales_response": "销售是否恰当应对"}
  ],
  "flow_analysis": {
    "steps_covered": ["已完成的环节"],
    "steps_order": "顺序是否正确",
    "problem_stages": ["有问题的环节"]
  },
  "missing_steps": ["遗漏的环节"],
  "total_score": 总分,
  "level": "excellent/good/pass/fail",
  "feedback": "总体反馈",
  "suggestions": ["改进建议1", "改进建议2"]
}
EOT;
}

/**
 * 调用AI服务
 */
function callAI($prompt) {
    $settings = ai_load_settings();
    $apiKey = $settings['zhipu_api_key'] ?? '';

    if (empty($apiKey)) {
        return ['error' => 'AI服务未配置'];
    }

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
        return ['error' => 'AI服务调用失败'];
    }

    $content = $result['body']['choices'][0]['message']['content'] ?? '';
    return ['content' => $content];
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
    $stageScores = [];

    // 尝试提取JSON
    if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
        $data = json_decode($matches[0], true);
        if (is_array($data)) {
            // 处理环节详细反馈（谈单7环节）
            if (isset($data['stage_scores']) && is_array($data['stage_scores'])) {
                $stageScores = $data['stage_scores'];
            }

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

    // 环节名称映射
    $stageNames = [
        'reception' => '开场接待',
        'demand' => '需求了解',
        'professional' => '专业展示',
        'solution' => '方案介绍',
        'objection' => '异议处理',
        'closing' => '逼单促成',
        'farewell' => '礼貌告别'
    ];

    return [
        'total_score' => $scores['total_score'],
        'level' => $scores['level'],
        'dimension_scores' => $scores['dimension_scores'],
        'stage_scores' => $stageScores,
        'stage_names' => $stageNames,
        'customer_intent' => $customerIntent,
        'intent_signals' => $intentSignals,
        'flow_analysis' => $flowAnalysis,
        'missing_steps' => $missingSteps,
        'feedback' => $feedback,
        'suggestions' => $suggestions,
        'model' => 'glm-4-flashx'
    ];
}

/**
 * 获取等级名称
 */
function getLevelName($level) {
    $names = [
        'excellent' => '优秀',
        'good' => '良好',
        'pass' => '合格',
        'fail' => '不合格'
    ];
    return $names[$level] ?? $level;
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
