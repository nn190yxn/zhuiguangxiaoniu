<?php
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

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $action = trim($input['action'] ?? '');
    if ($action === 'scenarios') {
        freeChatScenarios($db);
    } elseif ($action === 'start') {
        freeChatStart($db, $userId, $input);
    } elseif ($action === 'chat') {
        freeChatContinue($db, $userId, $input);
    } elseif ($action === 'end') {
        freeChatEnd($db, $userId, $input);
    } else {
        jsonResponse(1, '未知操作');
    }
} catch (Throwable $e) {
    error_log('free-chat error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误，请稍后重试');
}

function freeChatScenarios(PDO $db) {
    $defaults = freeChatDefaultScenarios();
    try {
        $stmt = $db->query("SELECT dimension_code, dimension_name, description FROM script_dimensions WHERE status = 1 ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }

    $scenarios = [];
    foreach ($rows as $row) {
        $code = $row['dimension_code'];
        $scenarios[] = [
            'id' => $code,
            'name' => $row['dimension_name'] ?: ($defaults[$code]['name'] ?? $code),
            'description' => $row['description'] ?: ($defaults[$code]['description'] ?? '自由对练场景')
        ];
    }

    jsonResponse(0, 'success', ['list' => $scenarios ?: array_values($defaults)]);
}

function freeChatStart(PDO $db, int $userId, array $input) {
    $scenario = trim($input['scenario'] ?? 'qa');
    if (!preg_match('/^[a-z0-9_\-]+$/i', $scenario)) {
        $scenario = 'qa';
    }

    $questions = freeChatQuestions($db, $scenario) ?: freeChatDefaultQuestions($scenario);
    $sessionId = 'FREE_' . time() . '_' . bin2hex(random_bytes(4));
    $stmt = $db->prepare("INSERT INTO drill_conversations (session_id, user_id, scenario, questions, current_index, status, created_at) VALUES (?, ?, ?, ?, 0, 'active', NOW())");
    $stmt->execute([$sessionId, $userId, $scenario, json_encode(array_values($questions), JSON_UNESCAPED_UNICODE)]);

    $firstQuestion = $questions[0];
    freeChatSaveMessage($db, $sessionId, 'assistant', $firstQuestion, null);

    jsonResponse(0, 'success', [
        'session_id' => $sessionId,
        'scenario' => $scenario,
        'welcome' => freeChatWelcome($scenario),
        'message' => $firstQuestion,
        'progress' => ['current' => 1, 'total' => min(count($questions), 5)]
    ]);
}

function freeChatContinue(PDO $db, int $userId, array $input) {
    $sessionId = trim($input['session_id'] ?? '');
    $message = trim($input['message'] ?? '');
    if ($sessionId === '' || $message === '') {
        jsonResponse(1, '缺少会话或回答内容');
    }

    $session = freeChatSession($db, $sessionId, $userId);
    if (!$session) {
        jsonResponse(1, '会话不存在或已结束');
    }

    $analysis = freeChatAnalyze($message, $session['scenario']);
    $score = (int)($analysis['score'] ?? 75);
    $feedback = trim($analysis['feedback'] ?? '回答已记录');
    freeChatSaveMessage($db, $sessionId, 'user', $message, $score);

    $questions = json_decode($session['questions'], true);
    if (!is_array($questions) || !$questions) {
        $questions = freeChatDefaultQuestions($session['scenario']);
    }

    $nextIndex = (int)$session['current_index'] + 1;
    $maxRounds = min(count($questions), 5);
    if ($nextIndex < $maxRounds) {
        $nextQuestion = $questions[$nextIndex];
        $assistantMessage = "点评：{$feedback}\n\n下一问：{$nextQuestion}";
        $stmt = $db->prepare("UPDATE drill_conversations SET current_index = ? WHERE session_id = ? AND user_id = ?");
        $stmt->execute([$nextIndex, $sessionId, $userId]);
        freeChatSaveMessage($db, $sessionId, 'assistant', $assistantMessage, null);

        jsonResponse(0, 'success', [
            'type' => 'continue',
            'score' => $score,
            'feedback' => $feedback,
            'message' => $assistantMessage,
            'progress' => ['current' => $nextIndex + 1, 'total' => $maxRounds]
        ]);
    }

    $summary = freeChatSummary($db, $sessionId, $session['scenario']);
    freeChatSaveMessage($db, $sessionId, 'assistant', $summary['final_message'], null);
    jsonResponse(0, 'success', [
        'type' => 'end',
        'score' => $score,
        'feedback' => $feedback,
        'message' => $summary['final_message'],
        'summary' => $summary,
        'progress' => ['current' => $maxRounds, 'total' => $maxRounds]
    ]);
}

function freeChatEnd(PDO $db, int $userId, array $input) {
    $sessionId = trim($input['session_id'] ?? '');
    if ($sessionId === '') {
        jsonResponse(1, '缺少session_id');
    }
    $session = freeChatSession($db, $sessionId, $userId, false);
    if (!$session) {
        jsonResponse(1, '会话不存在');
    }
    jsonResponse(0, 'success', freeChatSummary($db, $sessionId, $session['scenario']));
}

function freeChatSession(PDO $db, string $sessionId, int $userId, bool $activeOnly = true) {
    $sql = "SELECT * FROM drill_conversations WHERE session_id = ? AND user_id = ?";
    if ($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $stmt = $db->prepare($sql . " LIMIT 1");
    $stmt->execute([$sessionId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function freeChatSaveMessage(PDO $db, string $sessionId, string $role, string $content, $score) {
    $stmt = $db->prepare("INSERT INTO drill_messages (session_id, role, content, score, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$sessionId, $role, $content, $score]);
}

function freeChatQuestions(PDO $db, string $scenario): array {
    try {
        $stmt = $db->prepare("SELECT scene_name FROM script_knowledge WHERE dimension_id = (SELECT id FROM script_dimensions WHERE dimension_code = ? LIMIT 1) AND status = 1 ORDER BY RAND() LIMIT 8");
        $stmt->execute([$scenario]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $rows = [];
    }

    $questions = [];
    foreach ($rows as $sceneName) {
        $sceneName = trim((string)$sceneName);
        if ($sceneName !== '') {
            $questions[] = freeChatQuestionFromScene($sceneName, $scenario);
        }
    }
    return array_values(array_unique(array_merge($questions, freeChatDefaultQuestions($scenario))));
}

function freeChatQuestionFromScene(string $sceneName, string $scenario): string {
    if ($scenario === 'knowledge') {
        return '请你用家长听得懂的话讲一下：' . $sceneName;
    }
    if ($scenario === 'feedback') {
        return '孩子刚上完课，家长问：' . $sceneName . '，你会怎么反馈？';
    }
    if ($scenario === 'deal') {
        return '家长对' . $sceneName . '还有顾虑，你会怎么回应？';
    }
    return '家长问：' . $sceneName . '，你会怎么回答？';
}

function freeChatDefaultQuestions(string $scenario): array {
    $defaults = [
        'qa' => ['你们这个课程适合几岁的孩子？', '体适能课和感统训练有什么区别？', '孩子注意力不集中，训练有帮助吗？', '多久能看到效果？', '能先体验一下吗？'],
        'knowledge' => ['什么是前庭觉？', '什么是本体觉？', '为什么孩子需要做平衡协调训练？', '感统训练和普通运动有什么不同？', '家长在家可以怎么配合？'],
        'feedback' => ['孩子今天上课表现怎么样？', '他今天最大进步是什么？', '回家需要练习什么？', '下节课重点会练什么？', '孩子不愿意配合时怎么办？'],
        'deal' => ['价格能不能再优惠？', '我们想再考虑一下，可以吗？', '孩子时间不固定怎么办？', '如果效果不好怎么办？', '为什么要现在报名？']
    ];
    return $defaults[$scenario] ?? $defaults['qa'];
}

function freeChatDefaultScenarios(): array {
    return [
        'qa' => ['id' => 'qa', 'name' => '客户问答', 'description' => '练习家长常见问题的一问一答'],
        'knowledge' => ['id' => 'knowledge', 'name' => '专业讲解', 'description' => '练习把专业知识讲给家长听'],
        'feedback' => ['id' => 'feedback', 'name' => '课后反馈', 'description' => '练习课后点评和课前沟通'],
        'deal' => ['id' => 'deal', 'name' => '谈单异议', 'description' => '练习销售异议处理和转化']
    ];
}

function freeChatWelcome(string $scenario): string {
    $map = [
        'qa' => '客户问答自由演练开始，我会扮演家长连续提问。',
        'knowledge' => '专业讲解自由演练开始，请用通俗语言回应家长。',
        'feedback' => '课后反馈自由演练开始，请给出具体、温暖、有建议的回应。',
        'deal' => '谈单异议自由演练开始，请稳住信任并推进下一步。'
    ];
    return $map[$scenario] ?? $map['qa'];
}

function freeChatAnalyze(string $message, string $scenario): array {
    $settings = ai_load_settings();
    $apiKey = $settings['deepseek_api_key'] ?? '';
    $model = 'deepseek-chat';
    $url = 'https://api.deepseek.com/chat/completions';
    if ($apiKey === '') {
        $apiKey = $settings['zhipu_api_key'] ?? '';
        $model = 'glm-4-flashx';
        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
    }
    if ($apiKey === '') {
        return ['score' => 80, 'feedback' => 'AI服务未配置，已按基础规则记录回答'];
    }

    $prompt = "你是儿童体适能培训教练，请评估员工在{$scenario}场景下的回答。\n员工回答：{$message}\n请只返回JSON：{\"score\":0到100的整数,\"feedback\":\"一句具体点评\"}";
    $result = freeChatPostJson($url, ['Authorization' => 'Bearer ' . $apiKey], [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.3
    ], 30);

    if (($result['status'] ?? 0) >= 300 || ($result['status'] ?? 0) === 0) {
        return ['score' => 75, 'feedback' => '分析服务暂时不可用，回答已记录'];
    }

    $content = $result['body']['choices'][0]['message']['content'] ?? '';
    $json = json_decode($content, true);
    if (!is_array($json) && preg_match('/\{.*\}/s', $content, $matches)) {
        $json = json_decode($matches[0], true);
    }

    return [
        'score' => max(0, min(100, (int)($json['score'] ?? 75))),
        'feedback' => trim((string)($json['feedback'] ?? '回答已记录，可继续优化表达'))
    ];
}

function freeChatSummary(PDO $db, string $sessionId, string $scenario): array {
    $stmt = $db->prepare("SELECT * FROM drill_messages WHERE session_id = ? ORDER BY id ASC");
    $stmt->execute([$sessionId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $scores = [];
    foreach ($messages as $message) {
        if ($message['role'] === 'user' && $message['score'] !== null) {
            $scores[] = (int)$message['score'];
        }
    }
    $avg = $scores ? round(array_sum($scores) / count($scores), 1) : 0;
    $level = $avg >= 90 ? 'excellent' : ($avg >= 75 ? 'good' : ($avg >= 60 ? 'pass' : 'fail'));
    $levelName = ['excellent' => '优秀', 'good' => '良好', 'pass' => '合格', 'fail' => '不合格'][$level];
    $final = "自由演练结束\n本次完成" . count($scores) . "轮问答\n综合得分：{$avg}分（{$levelName}）";
    $stmt = $db->prepare("UPDATE drill_conversations SET status = 'completed', total_score = ?, level = ?, ended_at = NOW() WHERE session_id = ?");
    $stmt->execute([$avg, $level, $sessionId]);
    return ['avg_score' => $avg, 'level' => $level, 'level_name' => $levelName, 'total_responses' => count($scores), 'final_message' => $final];
}

function freeChatPostJson(string $url, array $headers, array $payload, int $timeout): array {
    $headerLines = ['Content-Type: application/json'];
    foreach ($headers as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'timeout' => $timeout,
        'ignore_errors' => true,
        'header' => implode("\r\n", $headerLines),
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $line) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
            $status = (int)$matches[1];
            break;
        }
    }
    $body = json_decode((string)$response, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : []];
}
