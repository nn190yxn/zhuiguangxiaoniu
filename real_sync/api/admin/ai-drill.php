<?php
/**
 * AI对练API
 * POST /api/admin/ai-drill.php
 *
 * 功能：
 * 1. AI扮演客户，与销售人员进行对话练习
 * 2. 从知识库获取场景相关问题
 * 3. 记录对话历史，最后给出综合评分
 */

require_once __DIR__ . '/common.php';
handleCORS();
adminRequireAuth('adminCanAccessHeadquarter');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = isset($input['action']) ? $input['action'] : '';

try {
    $db = getDB();

    switch ($action) {
        case 'start':
            startDrill($db, $input);
            break;
        case 'chat':
            continueChat($db, $input);
            break;
        case 'end':
            endDrill($db, $input);
            break;
        case 'get_scenarios':
            getScenarios($db);
            break;
        default:
            jsonResponse(1, '未知操作');
    }

} catch (Exception $e) {
    error_log('ai-drill error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误：' . $e->getMessage());
}

/**
 * 获取可用场景列表
 */
function getScenarios($db) {
    $scenarios = [];

    // 从话术知识库获取场景分布
    $sql = "SELECT sk.dimension_id, sd.dimension_code, sd.dimension_name, COUNT(*) as count
            FROM script_knowledge sk
            JOIN script_dimensions sd ON sk.dimension_id = sd.id
            WHERE sk.status = 1 GROUP BY sk.dimension_id, sd.dimension_code, sd.dimension_name";
    $stmt = $db->query($sql);
    $dimensions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dimensions as $dim) {
        $scenarios[] = [
            'id' => $dim['dimension_code'],
            'name' => $dim['dimension_name'],
            'description' => getDimensionDescription($dim['dimension_code']),
            'question_count' => $dim['count']
        ];
    }

    // 从培训模块获取场景
    $moduleSql = "SELECT id, module_name, description, total_cards FROM training_modules WHERE status = 1";
    $stmt = $db->query($moduleSql);
    $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $moduleScenarios = [];
    foreach ($modules as $m) {
        $moduleScenarios[] = [
            'id' => 'module_' . $m['id'],
            'name' => $m['module_name'],
            'description' => $m['description'] ?: '培训模块',
            'question_count' => $m['total_cards']
        ];
    }

    jsonResponse(0, 'success', [
        'dimension_scenarios' => $scenarios,
        'module_scenarios' => $moduleScenarios
    ]);
}

/**
 * 获取维度描述
 */
function getDimensionDescription($code) {
    $descriptions = [
        'qa' => '练习回答客户常见问题',
        'knowledge' => '练习专业知识点讲解',
        'feedback' => '练习课后点评和课前沟通',
        'deal' => '完整销售流程对练'
    ];
    return $descriptions[$code] ?? '通用场景';
}

/**
 * 开始对练
 */
function startDrill($db, $input) {
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $scenario = isset($input['scenario']) ? trim($input['scenario']) : 'qa';
    $role = isset($input['role']) ? trim($input['role']) : 'customer';

    if (!$userId) {
        jsonResponse(401, '请先登录');
    }

    // 获取场景相关的客户问题（从知识库）
    $questions = getScenarioQuestions($db, $scenario);

    if (empty($questions)) {
        // 如果知识库没有，使用默认问题
        $questions = getDefaultQuestions($scenario);
    }

    // 创建对练会话
    $sessionId = createDrillSession($db, $userId, $scenario, $questions);

    // 获取开场白（AI扮演客户的第一个问题）
    $firstQuestion = $questions[array_rand($questions)];

    // 构建系统提示
    $systemPrompt = buildSystemPrompt($scenario, $role);

    // 保存第一条AI消息
    saveMessage($db, $sessionId, 'assistant', $firstQuestion, null);

    jsonResponse(0, 'success', [
        'session_id' => $sessionId,
        'scenario' => $scenario,
        'welcome' => getWelcomeMessage($scenario),
        'first_question' => $firstQuestion,
        'total_questions' => count($questions),
        'system_prompt' => $systemPrompt
    ]);
}

/**
 * 获取场景相关问题
 */
function getScenarioQuestions($db, $scenario) {
    $questions = [];

    // 从知识库获取该维度的问题
    if (strpos($scenario, 'module_') === 0) {
        // 培训模块场景
        $moduleId = (int)str_replace('module_', '', $scenario);
        $sql = "SELECT sk.scene_name, sk.standard_script, sk.tips, sk.keywords
                FROM training_cards tc
                JOIN training_modules tm ON tc.module_id = tm.id
                LEFT JOIN script_knowledge sk ON sk.dimension_id = tm.id
                WHERE tm.id = ? AND tc.status = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$moduleId]);
    } else {
        // 维度场景
        $dimCode = $scenario;
        $sql = "SELECT scene_name, standard_script, tips, keywords FROM script_knowledge
                WHERE dimension_id = (SELECT id FROM script_dimensions WHERE dimension_code = ? LIMIT 1)
                AND status = 1 ORDER BY RAND() LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->execute([$dimCode]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 将知识库内容转化为客户可能问的问题
    foreach ($rows as $row) {
        if (!empty($row['scene_name'])) {
            // 从场景名称生成客户问题
            $question = generateCustomerQuestion($row['scene_name'], $scenario);
            if ($question) {
                $questions[] = $question;
            }
        }
    }

    return $questions;
}

/**
 * 生成客户问题
 */
function generateCustomerQuestion($sceneName, $scenario) {
    // 模拟客户会问的问题
    $templates = [
        '这个课程适合多大年龄的孩子？',
        '一期课程多长时间？',
        '你们学费是多少？',
        '有什么优惠吗？',
        '一周上几节课？',
        '能先体验一下吗？',
        '我家孩子感统失调，能学吗？',
        '和别的机构比有什么优势？',
        '上课时间怎么安排？',
        '需要准备什么装备吗？',
        '效果怎么样？',
        '能不能先看看上课？'
    ];

    // 根据场景选择相关问题
    $specificQuestions = [
        'qa' => [
            '你们这个感统训练是什么？',
            '体适能课和感统课有什么区别？',
            '孩子注意力不集中能改善吗？',
            '几岁开始学比较好？'
        ],
        'knowledge' => [
            '什么是感统失调？',
            '前庭觉不好有什么表现？',
            '本体觉失调会影响什么？',
            '触觉敏感怎么办？'
        ],
        'feedback' => [
            '孩子今天表现怎么样？',
            '他有什么需要加强的？',
            '回家需要练习什么？',
            '下周有什么安排？'
        ],
        'deal' => [
            '价格能便宜点吗？',
            '能不能先试课再决定？',
            '时间上不太方便，能调吗？',
            '如果效果不好能退费吗？'
        ]
    ];

    $specific = $specificQuestions[$scenario] ?? [];

    // 70%使用相关问题，30%使用通用问题
    if (rand(1, 100) <= 70 && !empty($specific)) {
        return $specific[array_rand($specific)];
    }

    return $templates[array_rand($templates)];
}

/**
 * 获取默认问题
 */
function getDefaultQuestions($scenario) {
    $defaults = [
        'qa' => [
            '你们这个课程多少钱？',
            '适合几岁的孩子？',
            '有什么优惠吗？',
            '能先体验一下吗？'
        ],
        'knowledge' => [
            '什么是感统训练？',
            '体适能是什么？',
            '前庭觉不好有什么表现？'
        ],
        'feedback' => [
            '孩子今天表现怎么样？',
            '有什么需要加强的？',
            '回家要练习什么？'
        ],
        'deal' => [
            '价格能便宜点吗？',
            '能不能先试课？',
            '时间怎么安排？'
        ]
    ];

    return $defaults[$scenario] ?? $defaults['qa'];
}

/**
 * 构建系统提示
 */
function buildSystemPrompt($scenario, $role) {
    if ($role !== 'customer') {
        $role = 'coach';
    }

    if ($role === 'coach') {
        return <<<EOT
你是专业的儿童体适能培训教练，正在扮演销售教练，观摩销售人员的表现。
根据客户的回答，给出专业的点评和指导建议。
EOT;
    }

    // 扮演客户
    $customerPrompts = [
        'qa' => '你是一位3-6岁孩子的家长，对体适能培训感兴趣。你会问一些关于课程的问题，态度友善但会有些疑虑。请用自然、口语化的方式提问，不要一次问太多问题。',
        'knowledge' => '你是一位4岁孩子的妈妈，想了解体适能训练的专业知识。提问时表现出对专业内容好奇但不太懂的态度。',
        'feedback' => '你是一位6岁孩子的家长，刚刚带孩子上完体验课。态度温和，想了解孩子的表现和后续建议。',
        'deal' => '你是一位有购买意向的客户，但价格和时间上有顾虑。提问时会有一定的异议，但表示愿意考虑。'
    ];

    return $customerPrompts[$scenario] ?? $customerPrompts['deal'];
}

/**
 * 获取欢迎语
 */
function getWelcomeMessage($scenario) {
    $messages = [
        'qa' => '欢迎来到问答话术练习！我是您的AI模拟客户，我会向您提问，请用专业话术回答。',
        'knowledge' => '欢迎来到专业知识练习！让我来考考您对儿童体适能专业知识的理解。',
        'feedback' => '欢迎来到沟通话术练习！我是模拟家长，让我们来练习课后点评和沟通技巧。',
        'deal' => '欢迎来到销售实战练习！我是您的AI模拟客户，我们将进行一场完整的销售对话。'
    ];

    if (strpos($scenario, 'module_') === 0) {
        return '欢迎来到培训模块练习！让我们开始吧。';
    }

    return $messages[$scenario] ?? '欢迎开始AI对练！';
}

/**
 * 创建对练会话
 */
function createDrillSession($db, $userId, $scenario, $questions) {
    $sessionId = 'DRILL_' . time() . '_' . bin2hex(random_bytes(4));

    $sql = "INSERT INTO drill_conversations
            (session_id, user_id, scenario, questions, current_index, status, created_at)
            VALUES (?, ?, ?, ?, 0, 'active', NOW())";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $sessionId,
        $userId,
        $scenario,
        json_encode($questions)
    ]);

    return $sessionId;
}

/**
 * 继续对话
 */
function continueChat($db, $input) {
    $sessionId = isset($input['session_id']) ? trim($input['session_id']) : '';
    $userMessage = isset($input['message']) ? trim($input['message']) : '';
    $systemPrompt = isset($input['system_prompt']) ? trim($input['system_prompt']) : '';

    if (empty($sessionId) || empty($userMessage)) {
        jsonResponse(1, '参数不完整');
    }

    // 获取会话信息
    $session = getDrillSession($db, $sessionId);
    if (!$session) {
        jsonResponse(1, '会话不存在或已结束');
    }

    // 保存用户回答
    $userScore = 0;
    $userFeedback = '';

    // AI分析用户回答
    $analysis = analyzeUserResponse($db, $userMessage, $session['scenario']);

    if ($analysis) {
        $userScore = $analysis['score'];
        $userFeedback = $analysis['feedback'];
    }

    saveMessage($db, $sessionId, 'user', $userMessage, $userScore);

    // 检查是否继续
    $questions = json_decode($session['questions'], true);
    $nextIndex = (int)$session['current_index'] + 1;

    // 如果还有问题，继续提问
    if ($nextIndex < count($questions) && $nextIndex < 5) { // 最多5轮
        $nextQuestion = $questions[$nextIndex];

        // 更新会话进度
        updateDrillSession($db, $sessionId, $nextIndex);

        // AI给出点评后提出下一个问题
        $aiMessage = $userFeedback ? "您的回答不错！{$userFeedback}\n\n下一个问题：{$nextQuestion}" : $nextQuestion;

        saveMessage($db, $sessionId, 'assistant', $aiMessage, null);

        jsonResponse(0, 'success', [
            'type' => 'continue',
            'feedback' => $userFeedback,
            'score' => $userScore,
            'next_question' => $nextQuestion,
            'progress' => [
                'current' => $nextIndex + 1,
                'total' => min(count($questions), 5)
            ]
        ]);
    } else {
        // 对练结束，给出总结
        $summary = generateSummary($db, $sessionId, $session['scenario']);

        // 更新会话状态
        updateDrillSession($db, $sessionId, $nextIndex, 'completed');

        saveMessage($db, $sessionId, 'assistant', $summary['final_message'], null);

        jsonResponse(0, 'success', [
            'type' => 'end',
            'feedback' => $userFeedback,
            'score' => $userScore,
            'summary' => $summary
        ]);
    }
}

/**
 * 获取对练会话
 */
function getDrillSession($db, $sessionId) {
    $sql = "SELECT * FROM drill_conversations WHERE session_id = ? AND status = 'active'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$sessionId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 更新对练会话
 */
function updateDrillSession($db, $sessionId, $currentIndex, $status = 'active') {
    $sql = "UPDATE drill_conversations SET current_index = ?, status = ? WHERE session_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$currentIndex, $status, $sessionId]);
}

/**
 * 保存消息
 */
function saveMessage($db, $sessionId, $role, $content, $score) {
    $sql = "INSERT INTO drill_messages (session_id, role, content, score, created_at)
            VALUES (?, ?, ?, ?, NOW())";
    $stmt = $db->prepare($sql);
    $stmt->execute([$sessionId, $role, $content, $score]);
}

/**
 * 分析用户回答
 */
function analyzeUserResponse($db, $message, $scenario) {
    $settings = ai_load_settings();
    $apiKey = $settings['deepseek_api_key'] ?? '';

    if (empty($apiKey)) {
        // 如果没有DeepSeek，使用智谱
        $apiKey = $settings['zhipu_api_key'] ?? '';
        $model = 'glm-4-flashx';
        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
    } else {
        $model = 'deepseek-chat';
        $url = 'https://api.deepseek.com/chat/completions';
    }

    if (empty($apiKey)) {
        return ['score' => 80, 'feedback' => 'AI服务未配置，使用默认评分'];
    }

    $prompt = buildAnalysisPrompt($message, $scenario);

    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.3
    ];

    $result = ai_post_json($url, ['Authorization' => 'Bearer ' . $apiKey], $data, 30);

    if (($result['status'] ?? 0) >= 300) {
        return ['score' => 75, 'feedback' => '分析服务暂时不可用'];
    }

    $content = $result['body']['choices'][0]['message']['content'] ?? '';

    // 解析评分
    $score = 75;
    $feedback = '回答合理';

    if (preg_match('/"score"\s*:\s*(\d+)/', $content, $matches)) {
        $score = (int)$matches[1];
    }

    if (preg_match('/"feedback"\s*:\s*"([^"]+)"/', $content, $matches)) {
        $feedback = $matches[1];
    }

    return ['score' => $score, 'feedback' => $feedback];
}

/**
 * 构建分析Prompt
 */
function buildAnalysisPrompt($message, $scenario) {
    $dimensionTips = [
        'qa' => '评估回答是否专业、清晰、有条理，是否有效解答客户疑问',
        'knowledge' => '评估讲解是否准确、通俗、有条理，是否用简单语言解释专业内容',
        'feedback' => '评估点评是否具体、温暖、有建设性，是否让家长感到被重视',
        'deal' => '评估销售话术是否有说服力，是否有效处理客户异议'
    ];

    return <<<EOT
你是一位儿童体适能销售培训教练，请分析销售人员的回答。

【客户问题场景】：{$scenario}
【销售回答】：{$message}

请从以下维度评估（100分制）：
整体表现

请以JSON格式返回：
{
    "score": 分数(0-100),
    "feedback": "一句话点评（指出优点或不足）",
    "suggestion": "如果分数低于80，给出一个改进建议"
}

只返回JSON，不要其他内容。
EOT;
}

/**
 * 生成总结
 */
function generateSummary($db, $sessionId, $scenario) {
    // 获取所有消息
    $sql = "SELECT * FROM drill_messages WHERE session_id = ? ORDER BY created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$sessionId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 计算平均分
    $scores = array_filter(array_column($messages, 'score'));
    $avgScore = !empty($scores) ? array_sum($scores) / count($scores) : 0;

    // 获取用户回答统计
    $userMessages = array_filter($messages, fn($m) => $m['role'] === 'user');
    $totalResponses = count($userMessages);

    // 生成综合评价
    $level = 'fail';
    $levelName = '不合格';
    if ($avgScore >= 90) {
        $level = 'excellent';
        $levelName = '优秀';
    } elseif ($avgScore >= 75) {
        $level = 'good';
        $levelName = '良好';
    } elseif ($avgScore >= 60) {
        $level = 'pass';
        $levelName = '合格';
    }

    $finalMessage = "【对练结束】\n\n";
    $finalMessage .= "本次对练完成{$totalResponses}轮问答。\n";
    $finalMessage .= "综合得分：{$avgScore}分（{$levelName}）\n\n";

    if ($level === 'excellent') {
        $finalMessage .= "表现非常出色！您的话术专业且有亲和力，继续保持！";
    } elseif ($level === 'good') {
        $finalMessage .= "整体表现良好，个别细节可以继续优化。";
    } elseif ($level === 'pass') {
        $finalMessage .= "基本达到了标准，建议多练习提升熟练度。";
    } else {
        $finalMessage .= "需要加强练习，建议多学习标准话术后再来对练。";
    }

    // 保存总结到数据库
    $updateSql = "UPDATE drill_conversations
                  SET status = 'completed',
                      total_score = ?,
                      level = ?,
                      ended_at = NOW()
                  WHERE session_id = ?";
    $stmt = $db->prepare($updateSql);
    $stmt->execute([$avgScore, $level, $sessionId]);

    return [
        'avg_score' => round($avgScore, 1),
        'level' => $level,
        'level_name' => $levelName,
        'total_responses' => $totalResponses,
        'final_message' => $finalMessage
    ];
}

/**
 * 结束对练
 */
function endDrill($db, $input) {
    $sessionId = isset($input['session_id']) ? trim($input['session_id']) : '';

    if (empty($sessionId)) {
        jsonResponse(1, '缺少session_id');
    }

    $summary = generateSummary($db, $sessionId, 'qa');

    jsonResponse(0, 'success', $summary);
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
