<?php
/**
 * 语音通关评估 API
 *
 * 接受小程序语音识别后的文本，进行规则+AI双引擎评估。
 * 客户端负责 ASR（微信同声传译插件），本接口负责评估。
 *
 * POST /api/pass/voice-assess.php
 * Body: { "stage_id": 1, "text": "用户语音识别文本" }
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '仅支持 POST 请求');
}

function passVoiceExtractString($item): string
{
    if (is_string($item)) {
        return trim($item);
    }

    if (!is_array($item)) {
        return '';
    }

    foreach (['title', 'name', 'task', 'content', 'description', 'desc', 'label', 'text'] as $field) {
        if (!empty($item[$field]) && is_string($item[$field])) {
            return trim($item[$field]);
        }
    }

    return '';
}

function passVoiceNormalizeChecklist($requiredTasks): array
{
    $expected = [];
    $forbidden = [];

    if (!is_array($requiredTasks)) {
        return [$expected, $forbidden];
    }

    if (!empty($requiredTasks['key_points']) && is_array($requiredTasks['key_points'])) {
        foreach ($requiredTasks['key_points'] as $item) {
            $value = passVoiceExtractString($item);
            if ($value !== '') {
                $expected[] = $value;
            }
        }
    }

    if (!empty($requiredTasks['forbidden_items']) && is_array($requiredTasks['forbidden_items'])) {
        foreach ($requiredTasks['forbidden_items'] as $item) {
            $value = passVoiceExtractString($item);
            if ($value !== '') {
                $forbidden[] = $value;
            }
        }
    }

    foreach ($requiredTasks as $key => $item) {
        if (in_array($key, ['key_points', 'forbidden_items'], true)) {
            continue;
        }
        $value = passVoiceExtractString($item);
        if ($value !== '') {
            $expected[] = $value;
        }
    }

    $expected = array_values(array_unique(array_filter($expected, static function ($item) {
        return $item !== '';
    })));
    $forbidden = array_values(array_unique(array_filter($forbidden, static function ($item) {
        return $item !== '';
    })));

    return [$expected, $forbidden];
}

function passVoiceBuildKeywords(array $phrases): array
{
    $keywords = [];
    foreach ($phrases as $phrase) {
        $phrase = trim((string)$phrase);
        if ($phrase === '') {
            continue;
        }

        $keywords[] = $phrase;
        $parts = preg_split('/[，。、“”‘’；：,.;\/\s\-]+/u', $phrase) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && mb_strlen($part) >= 2) {
                $keywords[] = $part;
            }
        }
    }

    usort($keywords, static function ($a, $b) {
        return mb_strlen($b) <=> mb_strlen($a);
    });

    return array_values(array_unique($keywords));
}

function passVoiceCountOccurrences(string $text, array $keywords): int
{
    $count = 0;
    foreach ($keywords as $keyword) {
        if ($keyword === '') {
            continue;
        }
        $offset = 0;
        while (($pos = mb_stripos($text, $keyword, $offset)) !== false) {
            $count++;
            $offset = $pos + mb_strlen($keyword);
        }
    }
    return $count;
}

function passVoiceClampScore($score): int
{
    return max(0, min(100, (int)round((float)$score)));
}

function passVoiceExtractJsonObject(string $content): ?array
{
    $content = trim($content);
    if ($content === '') {
        return null;
    }

    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/su', $content, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function passVoiceHttpJson(string $url, array $headers, array $payload, int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException('AI 请求失败: ' . $error);
    }

    $decoded = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300) {
        $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
        throw new RuntimeException('AI 评分失败: ' . $message);
    }

    return is_array($decoded) ? $decoded : [];
}

function passVoiceEvaluateWithAi(array $stage, array $expectedPoints, array $forbiddenItems, string $text): array
{
    $settings = ai_load_settings();
    $deepseekApiKey = trim((string)($settings['deepseek_api_key'] ?? ''));
    $zhipuApiKey = trim((string)($settings['zhipu_api_key'] ?? ''));

    if ($deepseekApiKey === '' && $zhipuApiKey === '') {
        return [
            'enabled' => false,
            'score' => 0,
            'feedback' => 'AI 服务暂不可用，已使用规则评分',
        ];
    }

    $prompt = sprintf(
        "你是一个销售培训通关评估助手。请仅输出 JSON。\n" .
        "阶段名称：%s\n" .
        "阶段要求：%s\n" .
        "禁忌项：%s\n" .
        "学员作答：%s\n\n" .
        "请从内容完整度、表达自然度、销售场景贴合度评分。\n" .
        "如果明显敷衍、内容过短、与阶段要求无关，分数应低。\n" .
        "如果命中禁忌项，score 必须为 0。\n" .
        "返回格式：{\"score\":0-100整数,\"feedback\":\"20字以内中文评价\"}",
        (string)($stage['name'] ?? ''),
        json_encode(array_values($expectedPoints), JSON_UNESCAPED_UNICODE),
        json_encode(array_values($forbiddenItems), JSON_UNESCAPED_UNICODE),
        $text
    );

    try {
        if ($deepseekApiKey !== '') {
            $response = passVoiceHttpJson(
                'https://api.deepseek.com/chat/completions',
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $deepseekApiKey,
                ],
                [
                    'model' => 'deepseek-chat',
                    'messages' => [
                        ['role' => 'system', 'content' => '你是严谨的销售培训评分助手，只返回 JSON。'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 300,
                    'response_format' => ['type' => 'json_object'],
                ]
            );
            $content = (string)($response['choices'][0]['message']['content'] ?? '');
            $parsed = passVoiceExtractJsonObject($content);
            if (is_array($parsed)) {
                return [
                    'enabled' => true,
                    'score' => passVoiceClampScore($parsed['score'] ?? 0),
                    'feedback' => trim((string)($parsed['feedback'] ?? 'AI 已参与评分')),
                ];
            }
        }

        if ($zhipuApiKey !== '') {
            $response = passVoiceHttpJson(
                'https://open.bigmodel.cn/api/paas/v4/chat/completions',
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $zhipuApiKey,
                ],
                [
                    'model' => 'glm-4-flash',
                    'messages' => [
                        ['role' => 'system', 'content' => '你是严谨的销售培训评分助手，只返回 JSON。'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 300,
                    'response_format' => ['type' => 'json_object'],
                ]
            );
            $content = (string)($response['choices'][0]['message']['content'] ?? '');
            $parsed = passVoiceExtractJsonObject($content);
            if (is_array($parsed)) {
                return [
                    'enabled' => true,
                    'score' => passVoiceClampScore($parsed['score'] ?? 0),
                    'feedback' => trim((string)($parsed['feedback'] ?? 'AI 已参与评分')),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('voice-assess AI eval failed: ' . $e->getMessage());
    }

    return [
        'enabled' => false,
        'score' => 0,
        'feedback' => 'AI 评分暂不可用，已使用规则评分',
    ];
}

try {
    $db = getDB();
    $userId = getCurrentUserId();
    $user = getJwtCurrentUser();

    $body = json_decode(file_get_contents('php://input'), true);
    $stageId = isset($body['stage_id']) ? (int)$body['stage_id'] : 0;
    $text = isset($body['text']) ? trim((string)$body['text']) : '';
    $attemptNum = isset($body['attempt_num']) ? max(1, (int)$body['attempt_num']) : 1;

    if (!$userId || !$user) {
        jsonResponse(401, '请先登录');
    }
    if (!$stageId) {
        jsonResponse(1, '缺少阶段ID');
    }
    if ($text === '') {
        jsonResponse(1, '语音识别文本不能为空');
    }

    $stageSql = "SELECT * FROM pass_stages WHERE id = ? AND is_active = 1";
    $stmt = $db->prepare($stageSql);
    $stmt->execute([$stageId]);
    $stage = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stage) {
        jsonResponse(1, '通关阶段不存在');
    }

    $effectiveRole = normalizeStaffRoleCode(getEffectiveStaffRole($user));
    if (!isPassStageRoleAllowed($stage['role'], $effectiveRole)) {
        jsonResponse(403, '无权进行该阶段的语音通关');
    }

    $requiredTasks = json_decode($stage['required_tasks'] ?? '[]', true);
    [$keyPoints, $forbiddenItems] = passVoiceNormalizeChecklist($requiredTasks);
    $requiredScore = (int)($stage['required_score'] ?? 60);
    $requiredScore = $requiredScore > 0 ? $requiredScore : 60;

    $ruleDetails = [];
    $forbiddenHit = 0;
    foreach ($forbiddenItems as $item) {
        if ($item !== '' && mb_stripos($text, $item) !== false) {
            $forbiddenHit = 1;
            $ruleDetails[] = '命中禁忌: ' . $item;
        }
    }

    $expectedPoints = $keyPoints;
    if (empty($expectedPoints) && !empty($stage['name'])) {
        $expectedPoints[] = trim((string)$stage['name']);
    }

    $keywords = passVoiceBuildKeywords($expectedPoints);
    $keyPointsHit = 0;
    $keyPointsMissed = [];
    foreach ($expectedPoints as $point) {
        $matched = false;
        $pointKeywords = passVoiceBuildKeywords([$point]);
        foreach ($pointKeywords as $keyword) {
            if ($keyword !== '' && mb_stripos($text, $keyword) !== false) {
                $matched = true;
                break;
            }
        }
        if ($matched) {
            $keyPointsHit++;
        } else {
            $keyPointsMissed[] = $point;
        }
    }

    $totalPoints = count($expectedPoints);
    $coverageScore = $totalPoints > 0 ? ($keyPointsHit / $totalPoints) * 100 : 60;
    $textLength = mb_strlen($text);
    $lengthScore = passVoiceClampScore(min(100, max(20, ($textLength - 8) * 2.5)));
    $fillerKeywords = ['嗯', '啊', '这个', '那个', '就是', '然后', '不知道', '不会'];
    $fillerCount = passVoiceCountOccurrences($text, $fillerKeywords);
    $expressionScore = passVoiceClampScore(100 - ($fillerCount * 12));

    $ruleScore = $totalPoints > 0
        ? (($coverageScore * 0.65) + ($lengthScore * 0.25) + ($expressionScore * 0.10))
        : (($lengthScore * 0.55) + ($expressionScore * 0.20) + (min(100, count($keywords) > 0 ? passVoiceCountOccurrences($text, $keywords) * 20 : 40) * 0.25));
    $ruleScore = passVoiceClampScore($ruleScore);

    $ruleDetails[] = '要点覆盖: ' . $keyPointsHit . '/' . $totalPoints;
    $ruleDetails[] = '文本长度: ' . $textLength . '字';
    if ($fillerCount > 0) {
        $ruleDetails[] = '敷衍词次数: ' . $fillerCount;
    }

    $ruleWeight = 60;
    $ruleFinal = $forbiddenHit ? 0 : (int)round($ruleScore * $ruleWeight / 100);

    $aiResult = passVoiceEvaluateWithAi($stage, $expectedPoints, $forbiddenItems, $text);
    $aiScore = $forbiddenHit ? 0 : passVoiceClampScore($aiResult['score'] ?? 0);
    $aiFeedback = trim((string)($aiResult['feedback'] ?? ''));
    $aiWeight = 40;
    $aiFinal = $forbiddenHit ? 0 : (int)round($aiScore * $aiWeight / 100);

    $totalScore = $ruleFinal + $aiFinal;
    if (!$aiResult['enabled']) {
        $totalScore = $ruleScore;
    }
    $totalScore = passVoiceClampScore($totalScore);

    if ($forbiddenHit) {
        $result = 'failed';
        $aiFeedback = '使用了禁忌用语，请避免后重试';
    } elseif ($totalScore >= $requiredScore) {
        $result = 'passed';
        if ($aiFeedback === '') {
            $aiFeedback = '表达较完整，可通过';
        }
    } else {
        $result = 'failed';
        if ($aiFeedback === '') {
            $aiFeedback = '要点不足，建议补充后重试';
        }
    }

    jsonResponse(0, '评估完成', [
        'stage_id' => $stageId,
        'stage_name' => $stage['name'],
        'attempt_num' => $attemptNum,
        'asr_text' => $text,
        'asr_confidence' => 1.0,
        'rule_score' => $ruleFinal,
        'ai_score' => $aiResult['enabled'] ? $aiFinal : 0,
        'total_score' => $totalScore,
        'result' => $result,
        'forbidden_hit' => (bool)$forbiddenHit,
        'key_points_hit' => $keyPointsHit,
        'key_points_total' => $totalPoints,
        'key_points_missed' => $keyPointsMissed,
        'required_score' => $requiredScore,
        'feedback' => $aiFeedback,
        'details' => $ruleDetails,
        'ai_enabled' => (bool)$aiResult['enabled'],
    ]);
} catch (Throwable $e) {
    jsonResponse(1, '评估失败: ' . $e->getMessage());
}
