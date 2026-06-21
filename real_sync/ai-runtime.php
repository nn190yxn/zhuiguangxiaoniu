<?php
declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'Forbidden'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_runtime_load_settings(): array
{
    $settings = array(
        'deepseek_api_key' => trim((string) (getenv('DEEPSEEK_API_KEY') ?: '')),
        'zhipu_api_key' => trim((string) getenv('ZHIPU_API_KEY')),
        'baidu_ocr_api_key' => trim((string) getenv('BAIDU_OCR_API_KEY')),
        'baidu_ocr_secret_key' => trim((string) getenv('BAIDU_OCR_SECRET_KEY')),
        'doubao_api_key' => trim((string) getenv('DOUBAO_API_KEY')),
        'doubao_model' => trim((string) getenv('DOUBAO_MODEL')),
    );

    $configSource = __DIR__ . '/config.php';
    if (is_file($configSource)) {
        try {
            $configText = (string) file_get_contents($configSource);
            $db = array();
            foreach (array('DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET') as $constant) {
                // Try simple define('CONST', 'value') first
                if (preg_match("/define\\(\\s*['\"]" . $constant . "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]\\s*\\)/", $configText, $matches) === 1) {
                    $db[$constant] = $matches[1];
                }
                // Fallback: support configValue('CONST', 'value') pattern used on server
                elseif (preg_match("/configValue\\(\\s*['\"]" . $constant . "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]\\s*\\)/", $configText, $matches) === 1) {
                    $db[$constant] = $matches[1];
                }
            }

            // Deep fallback: read .env.local.php directly if DB_PASSWORD is still missing
            if (empty($db['DB_PASSWORD'])) {
                $envFile = __DIR__ . '/.env.local.php';
                if (is_file($envFile)) {
                    $envData = require $envFile;
                    if (is_array($envData)) {
                        foreach (array('DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET') as $key) {
                            if (!empty($envData[$key])) {
                                $db[$key] = $envData[$key];
                            }
                        }
                    }
                }
            }

            if (isset($db['DB_HOST'], $db['DB_NAME'], $db['DB_USER'], $db['DB_PASSWORD'])) {
                $charset = $db['DB_CHARSET'] ?? 'utf8mb4';
                $pdo = new PDO(
                    'mysql:host=' . $db['DB_HOST'] . ';dbname=' . $db['DB_NAME'] . ';charset=' . $charset,
                    $db['DB_USER'],
                    $db['DB_PASSWORD'],
                    array(
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    )
                );
                $stmt = $pdo->query('SELECT setting_key, setting_value FROM ai_settings');
                foreach ($stmt->fetchAll() as $row) {
                    $key = (string) ($row['setting_key'] ?? '');
                    $value = trim((string) ($row['setting_value'] ?? ''));
                    if (array_key_exists($key, $settings) && $value !== '') {
                        $settings[$key] = $value;
                    }
                }
            }
        } catch (Throwable $exception) {
            error_log('AI runtime database settings load failed: ' . $exception->getMessage());
        }
    }

    $configPath = __DIR__ . '/ai-config.php';
    if (is_file($configPath)) {
        $localSettings = require $configPath;
        if (is_array($localSettings)) {
            foreach ($localSettings as $key => $value) {
                if (array_key_exists($key, $settings) && trim((string) $value) !== '') {
                    $settings[$key] = trim((string) $value);
                }
            }
        }
    }

    return $settings;
}

function ai_has_service(string $name): bool
{
    $settings = ai_runtime_load_settings();

    if ($name === 'baidu_ocr') {
        return trim((string) ($settings['baidu_ocr_api_key'] ?? '')) !== ''
            && trim((string) ($settings['baidu_ocr_secret_key'] ?? '')) !== '';
    }

    return trim((string) ($settings[$name . '_api_key'] ?? '')) !== '';
}

function ai_ocr_ready(): bool
{
    return ai_has_service('baidu_ocr') && ai_has_service('deepseek');
}

function ai_post_json(string $url, array $headers, array $payload, int $timeout = 45): array
{
    $headerLines = array('Content-Type: application/json');
    foreach ($headers as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headerLines),
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ),
    ));

    $response = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = $http_response_header ?? array();
    if ($responseHeaders) {
        foreach ($responseHeaders as $line) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }
    }

    $decoded = json_decode((string) $response, true);
    return array(
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : array(),
        'raw' => (string) $response,
    );
}

function ai_post_form(string $url, array $headers, array $payload, int $timeout = 45): array
{
    $headerLines = array('Content-Type: application/x-www-form-urlencoded');
    foreach ($headers as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headerLines),
            'content' => http_build_query($payload),
        ),
    ));

    $response = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = $http_response_header ?? array();
    if ($responseHeaders) {
        foreach ($responseHeaders as $line) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }
    }

    $decoded = json_decode((string) $response, true);
    return array(
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : array(),
        'raw' => (string) $response,
    );
}

function ai_normalize_vision_input(string $imageInput): string
{
    $imageInput = trim($imageInput);
    if ($imageInput === '') {
        throw new InvalidArgumentException('缺少图片数据');
    }

    if (preg_match('#^https?://#i', $imageInput) === 1) {
        return $imageInput;
    }

    if (preg_match('#^data:image/[^;]+;base64,(.+)$#is', $imageInput, $matches) === 1) {
        $imageInput = $matches[1];
    }

    $imageInput = preg_replace('/\s+/', '', $imageInput) ?? '';
    if ($imageInput === '') {
        throw new InvalidArgumentException('图片数据为空');
    }

    return $imageInput;
}

function ai_deepseek_chat(string $prompt, string $systemPrompt, int $maxTokens = 3000, float $temperature = 0.7): string
{
    $settings = ai_runtime_load_settings();
    $apiKey = trim((string) ($settings['deepseek_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('DeepSeek 后台未配置');
    }

    $result = ai_post_json(
        'https://api.deepseek.com/chat/completions',
        array('Authorization' => 'Bearer ' . $apiKey),
        array(
            'model' => 'deepseek-chat',
            'messages' => array(
                array('role' => 'system', 'content' => $systemPrompt),
                array('role' => 'user', 'content' => $prompt),
            ),
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ),
        60
    );

    if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
        $message = $result['body']['error']['message'] ?? ('HTTP ' . ($result['status'] ?: 0));
        throw new RuntimeException('DeepSeek 调用失败：' . $message);
    }

    return (string) (($result['body']['choices'][0]['message']['content'] ?? ''));
}

function ai_extract_json_object(string $content, string $errorPrefix): array
{
    if (preg_match('/\{[\s\S]*\}/', $content, $matches) !== 1) {
        throw new RuntimeException($errorPrefix . '：未提取到有效 JSON');
    }

    $decoded = json_decode($matches[0], true);
    if (!is_array($decoded)) {
        throw new RuntimeException($errorPrefix . '：JSON 解析失败');
    }

    return $decoded;
}

function ai_baidu_ocr_access_token(): string
{
    $settings = ai_runtime_load_settings();
    $apiKey = trim((string) ($settings['baidu_ocr_api_key'] ?? ''));
    $secretKey = trim((string) ($settings['baidu_ocr_secret_key'] ?? ''));
    if ($apiKey === '' || $secretKey === '') {
        throw new RuntimeException('百度 OCR 后台未配置');
    }

    $result = ai_post_form(
        'https://aip.baidubce.com/oauth/2.0/token',
        array(),
        array(
            'grant_type' => 'client_credentials',
            'client_id' => $apiKey,
            'client_secret' => $secretKey,
        ),
        20
    );

    if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
        $message = $result['body']['error_description'] ?? $result['body']['error'] ?? ('HTTP ' . ($result['status'] ?: 0));
        throw new RuntimeException('百度 OCR 获取 token 失败：' . $message);
    }

    $token = trim((string) ($result['body']['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('百度 OCR token 响应无效');
    }

    return $token;
}

function ai_baidu_ocr_text(string $imageInput): string
{
    $token = ai_baidu_ocr_access_token();
    $imageInput = trim($imageInput);
    if ($imageInput === '') {
        throw new InvalidArgumentException('缺少图片数据');
    }

    if (preg_match('#^https?://#i', $imageInput) === 1) {
        $payload = array('url' => $imageInput);
    } else {
        $payload = array('image' => ai_normalize_vision_input($imageInput));
    }

    $result = ai_post_form(
        'https://aip.baidubce.com/rest/2.0/ocr/v1/general_basic?access_token=' . rawurlencode($token),
        array(),
        $payload,
        45
    );

    if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300 || isset($result['body']['error_code'])) {
        $message = $result['body']['error_msg'] ?? ('HTTP ' . ($result['status'] ?: 0));
        throw new RuntimeException('百度 OCR 识别失败：' . $message);
    }

    $words = array();
    foreach (($result['body']['words_result'] ?? array()) as $row) {
        $text = trim((string) ($row['words'] ?? ''));
        if ($text !== '') {
            $words[] = $text;
        }
    }

    $ocrText = trim(implode("\n", $words));
    if ($ocrText === '') {
        throw new RuntimeException('百度 OCR 未识别到文字');
    }

    return $ocrText;
}

function ai_parse_ocr_text_with_deepseek(string $ocrText, string $prompt): array
{
    $content = ai_deepseek_chat(
        "原始识别要求：\n" . $prompt . "\n\nOCR 识别出的体测图片文字如下：\n" . $ocrText . "\n\n请严格根据 OCR 文字提取体测数据，只返回一个 JSON 对象，不要输出解释、Markdown 或代码块。无法确认的字段用空字符串，不要编造数据。",
        '你是体测报告 OCR 数据结构化助手。你只负责从 OCR 文本中提取身高、体重、BMI、肺活量、坐位体前屈、跳绳等体测字段，并按用户要求返回 JSON。禁止编造缺失数据。',
        1500,
        0.1
    );

    return ai_extract_json_object($content, 'DeepSeek OCR 结构化失败');
}

function ai_has_value($value): bool
{
    if ($value === null) {
        return false;
    }

    if (is_array($value)) {
        return false;
    }

    $text = trim((string) $value);
    return $text !== '' && strtolower($text) !== 'null';
}

function ai_collect_missing_rating_fields(array $result): array
{
    $fields = array(
        'height',
        'weight',
        'standing_jump',
        'continuous_jump',
        'jump_rope',
        'rope_skip',
        'tennis_throw',
        'situp',
        'sit_ups',
        'sit_reach',
        'balance_beam',
        'step_test',
        'shuttle_run',
        'shuttle_run_4x10',
    );

    $missing = array();
    foreach ($fields as $field) {
        if (ai_has_value($result[$field] ?? null) && !ai_has_value($result[$field . '_rating'] ?? null)) {
            $missing[] = $field;
        }
    }

    return array_values(array_unique($missing));
}

function ai_merge_ocr_results(array $primary, array $fallback): array
{
    $merged = $primary;
    foreach ($fallback as $key => $value) {
        if (!array_key_exists($key, $merged) || !ai_has_value($merged[$key])) {
            $merged[$key] = $value;
        }
    }

    return $merged;
}

function ai_normalize_result_field_key(string $key): ?string
{
    $map = array(
        'height' => 'height',
        'weight' => 'weight',
        'bmi' => 'bmi',
        'standing_jump' => 'standing_jump',
        'standing_long_jump' => 'standing_jump',
        'continuous_jump' => 'continuous_jump',
        'double_foot_continuous_jump' => 'continuous_jump',
        'jump_rope' => 'rope_skip',
        'rope_skip' => 'rope_skip',
        'rope_skipping' => 'rope_skip',
        'tennis_throw' => 'tennis_throw',
        'situp' => 'sit_ups',
        'sit_ups' => 'sit_ups',
        'sit_and_reach' => 'sit_reach',
        'sit_reach' => 'sit_reach',
        'balance_beam' => 'balance_beam',
        'step_test' => 'step_test',
        'vital_capacity' => 'step_test',
        'shuttle_run' => 'shuttle_run',
        'ten_meter_shuttle_run' => 'shuttle_run',
        'shuttle_run_4x10' => 'shuttle_run_4x10',
    );

    return $map[$key] ?? null;
}

function ai_map_item_name_to_field(string $name): ?string
{
    $name = trim($name);
    $map = array(
        '身高' => 'height',
        '体重' => 'weight',
        'BMI' => 'bmi',
        '立定跳远' => 'standing_jump',
        '立定跳远(爆发力)' => 'standing_jump',
        '双脚连续跳' => 'continuous_jump',
        '双脚连续跳(协调能力)' => 'continuous_jump',
        '网球掷远' => 'tennis_throw',
        '网球掷远(上肢力量)' => 'tennis_throw',
        '坐位体前屈' => 'sit_reach',
        '坐位体前屈(柔韧性)' => 'sit_reach',
        '走平衡木' => 'balance_beam',
        '走平衡木(平衡力)' => 'balance_beam',
        '十米折返跑' => 'shuttle_run',
        '十米折返跑(灵敏力)' => 'shuttle_run',
        '十米折返跑(灵敏度)' => 'shuttle_run',
        '10米折返跑' => 'shuttle_run',
        '一分钟跳绳' => 'rope_skip',
        '仰卧起坐' => 'sit_ups',
        '台阶测试' => 'step_test',
        '4x10米往返跑' => 'shuttle_run_4x10',
    );

    return $map[$name] ?? null;
}

function ai_normalize_ocr_result(array $result): array
{
    $normalized = array();

    foreach ($result as $key => $value) {
        if (is_array($value)) {
            continue;
        }

        $isRating = substr((string) $key, -7) === '_rating';
        $baseKey = $isRating ? substr((string) $key, 0, -7) : (string) $key;
        $field = ai_normalize_result_field_key($baseKey);
        if ($field === null) {
            continue;
        }

        $normalized[$field . ($isRating ? '_rating' : '')] = $value;
    }

    foreach (array('身体形态', '身体形态数据') as $sectionKey) {
        if (!isset($result[$sectionKey]) || !is_array($result[$sectionKey])) {
            continue;
        }

        foreach ($result[$sectionKey] as $name => $item) {
            if (is_array($item) && is_string($name)) {
                $field = ai_map_item_name_to_field((string) $name);
                if ($field === null) {
                    continue;
                }
                if (isset($item['测试值']) && !isset($normalized[$field])) {
                    $normalized[$field] = $item['测试值'];
                } elseif (isset($item['value']) && !isset($normalized[$field])) {
                    $normalized[$field] = $item['value'];
                }
                if (isset($item['rating']) && !isset($normalized[$field . '_rating'])) {
                    $normalized[$field . '_rating'] = $item['rating'];
                }
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $field = ai_map_item_name_to_field((string) ($item['项目'] ?? $item['项目名称'] ?? ''));
            if ($field === null) {
                continue;
            }
            if (isset($item['测试值']) && !isset($normalized[$field])) {
                $normalized[$field] = $item['测试值'];
            } elseif (isset($item['value']) && !isset($normalized[$field])) {
                $normalized[$field] = $item['value'];
            }
            if (isset($item['rating']) && !isset($normalized[$field . '_rating'])) {
                $normalized[$field . '_rating'] = $item['rating'];
            }
        }

        if ($sectionKey === '身体形态') {
            foreach ($result[$sectionKey] as $name => $value) {
                if (is_array($value) || !is_string($name)) {
                    continue;
                }

                if ($name === '整体形态评级' || $name === '形态总评级' || $name === '形态评级') {
                    continue;
                }

                if (preg_match('/^(.+?)评级$/u', $name, $matches) === 1) {
                    $baseName = $matches[1];
                    $field = ai_map_item_name_to_field($baseName);
                    if ($field !== null && !isset($normalized[$field . '_rating'])) {
                        $normalized[$field . '_rating'] = $value;
                    }
                    continue;
                }

                $field = ai_map_item_name_to_field($name);
                if ($field !== null && !isset($normalized[$field])) {
                    $normalized[$field] = $value;
                }
            }
        }
    }

    foreach (array('体能测评项目', '体测项目', '体能测试项目', '体能测评项目数据', '测评项目详情') as $sectionKey) {
        if (!isset($result[$sectionKey]) || !is_array($result[$sectionKey])) {
            continue;
        }
        foreach ($result[$sectionKey] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = ai_map_item_name_to_field((string) ($item['项目名称'] ?? $item['项目'] ?? ''));
            if ($field === null) {
                continue;
            }
            if (isset($item['测试值']) && !isset($normalized[$field])) {
                $normalized[$field] = $item['测试值'];
            } elseif (isset($item['value']) && !isset($normalized[$field])) {
                $normalized[$field] = $item['value'];
            }
            if (isset($item['rating']) && !isset($normalized[$field . '_rating'])) {
                $normalized[$field . '_rating'] = $item['rating'];
            }
        }
    }

    if (
        ai_has_value($normalized['weight'] ?? null)
        && !ai_has_value($normalized['weight_rating'] ?? null)
        && ai_has_value($normalized['bmi_rating'] ?? null)
    ) {
        $bmiRating = trim((string) $normalized['bmi_rating']);
        if (in_array($bmiRating, array('偏胖', '偏瘦', '标准', '正常', '偏高', '偏低'), true)) {
            $normalized['weight_rating'] = $bmiRating;
        }
    }

    return $normalized;
}

function ai_collect_filled_fields(array $before, array $after): array
{
    $filled = array();
    foreach ($after as $key => $value) {
        if (!ai_has_value($value)) {
            continue;
        }
        if (!ai_has_value($before[$key] ?? null)) {
            $filled[] = (string) $key;
        }
    }

    sort($filled);
    return $filled;
}

function ai_extract_response_text($content): string
{
    if (is_string($content)) {
        return $content;
    }

    if (!is_array($content)) {
        return '';
    }

    $parts = array();
    foreach ($content as $item) {
        if (is_string($item)) {
            $parts[] = $item;
            continue;
        }
        if (!is_array($item)) {
            continue;
        }

        if (isset($item['text']) && is_string($item['text'])) {
            $parts[] = $item['text'];
            continue;
        }

        if (($item['type'] ?? '') === 'output_text' && isset($item['text']) && is_string($item['text'])) {
            $parts[] = $item['text'];
        }
    }

    return trim(implode("\n", $parts));
}

function ai_doubao_vision(string $imageUrl, string $prompt): array
{
    $settings = ai_runtime_load_settings();
    $apiKey = trim((string) ($settings['doubao_api_key'] ?? ''));
    $model = trim((string) ($settings['doubao_model'] ?? 'doubao-seed-2-0-lite-260428'));

    if ($apiKey === '') {
        throw new RuntimeException('豆包视觉后台未配置');
    }

    $result = ai_post_json(
        'https://ark.cn-beijing.volces.com/api/v3/responses',
        array('Authorization' => 'Bearer ' . $apiKey),
        array(
            'model' => $model,
            'input' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'input_image',
                            'image_url' => $imageUrl,
                        ),
                        array(
                            'type' => 'input_text',
                            'text' => $prompt . "\n\n只返回一个 JSON 对象，不要输出解释、Markdown 或代码块。",
                        ),
                    ),
                ),
            ),
        ),
        60
    );

    if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
        $message = $result['body']['error']['message'] ?? ('HTTP ' . ($result['status'] ?: 0));
        throw new RuntimeException('豆包视觉识别失败：' . $message);
    }

    $text = '';
    foreach (($result['body']['output'] ?? array()) as $outputItem) {
        if (($outputItem['type'] ?? '') !== 'message') {
            continue;
        }
        $text = ai_extract_response_text($outputItem['content'] ?? array());
        if ($text !== '') {
            break;
        }
    }

    if ($text === '') {
        $text = ai_extract_response_text($result['body']['content'] ?? array());
    }

    if ($text === '') {
        throw new RuntimeException('豆包视觉识别失败：未返回有效内容');
    }

    $decoded = ai_extract_json_object($text, '豆包视觉识别失败');
    return ai_normalize_ocr_result($decoded);
}

function ai_log_ocr_result(string $imageUrl, string $ocrText, array $result, array $meta = array()): void
{
    $baseDir = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $logDir = $baseDir . '/wp-content/uploads/ocr-logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        return;
    }

    $summary = array(
        'time' => gmdate('c'),
        'image' => basename((string) parse_url($imageUrl, PHP_URL_PATH)),
        'name' => $result['name'] ?? null,
        'height' => $result['height'] ?? null,
        'height_rating' => $result['height_rating'] ?? null,
        'weight' => $result['weight'] ?? null,
        'weight_rating' => $result['weight_rating'] ?? null,
        'doubao_triggered' => (bool) ($meta['doubao_triggered'] ?? false),
        'doubao_reason' => $meta['doubao_reason'] ?? array(),
        'doubao_filled_fields' => $meta['doubao_filled_fields'] ?? array(),
        'doubao_hit' => (bool) ($meta['doubao_hit'] ?? false),
        'ocr_text' => $ocrText,
    );

    $line = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    @file_put_contents($logDir . '/fitness-ocr.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function ai_ocr_fitness_image(string $imageDataUrl, string $imageUrl, string $prompt): array
{
    if (ai_has_service('baidu_ocr') && ai_has_service('deepseek')) {
        $ocrText = ai_baidu_ocr_text($imageDataUrl);
        $result = ai_normalize_ocr_result(ai_parse_ocr_text_with_deepseek($ocrText, $prompt));
        $missingRatingFields = ai_collect_missing_rating_fields($result);
        $ocrMeta = array(
            'doubao_triggered' => false,
            'doubao_reason' => $missingRatingFields,
            'doubao_filled_fields' => array(),
            'doubao_hit' => false,
        );

        if (!empty($missingRatingFields) && ai_has_service('doubao')) {
            $ocrMeta['doubao_triggered'] = true;
            try {
                $beforeMerge = $result;
                $doubaoResult = ai_doubao_vision($imageUrl, $prompt);
                $result = ai_merge_ocr_results($result, $doubaoResult);
                $filledFields = ai_collect_filled_fields($beforeMerge, $result);
                $ocrMeta['doubao_filled_fields'] = $filledFields;
                $ocrMeta['doubao_hit'] = !empty($filledFields);
            } catch (Throwable $exception) {
                error_log('Doubao OCR fallback failed: ' . $exception->getMessage());
            }
        }

        ai_log_ocr_result($imageUrl, $ocrText, $result, $ocrMeta);
        return $result;
    }

    if (!ai_has_service('baidu_ocr')) {
        throw new RuntimeException('百度 OCR 后台未配置');
    }

    if (!ai_has_service('deepseek')) {
        throw new RuntimeException('DeepSeek 后台未配置');
    }

    throw new RuntimeException('OCR 后台未配置');
}

function ai_zhipu_vision(string $imageDataUrl, string $prompt): array
{
    $settings = ai_runtime_load_settings();
    $apiKey = trim((string) ($settings['zhipu_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('智谱图片识别后台未配置');
    }

    $imageInput = ai_normalize_vision_input($imageDataUrl);

    $result = ai_post_json(
        'https://open.bigmodel.cn/api/paas/v4/chat/completions',
        array('Authorization' => 'Bearer ' . $apiKey),
        array(
            'model' => 'glm-4.6v-flashx',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'image_url',
                            'image_url' => array('url' => $imageInput),
                        ),
                        array(
                            'type' => 'text',
                            'text' => $prompt,
                        ),
                    ),
                ),
            ),
            'temperature' => 0.1,
            'max_tokens' => 1500,
        ),
        60
    );

    if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
        $message = $result['body']['error']['message'] ?? ('HTTP ' . ($result['status'] ?: 0));
throw new RuntimeException('智谱识别失败：' . $message);
    }

    return (array) ($result['body']['choices'][0]['message']['content'] ?? array());
}

function ai_get_summer_camp_system_prompt(string $campType): string
{
    return '你是追光小牛运动成长中心的资深体能教练，拥有10年青少年体能训练经验。
你需要根据学员的测试数据，生成一份给家长看的精简评估解读。

报告要求：
1. 语言风格：专业、克制、家长能看懂
2. 内容长度：每个字段都要短，适合打印报告展示
3. 训练重点：围绕本期5次训练，给出方向和预期效果
4. 年龄判断：根据年级输出对应阶段话术
5. 家长引导：通过专业诊断让家长感受到问题需要重视，并理解坚持训练的必要性

禁止事项：
- 不要输出完整HTML、Markdown代码块或CSS
- 不要说"保过""满分""100%达标"
- 不要承诺具体分数
- 不要写价格、金额、优惠、套餐
- 不要写购买、续课、正式课包、锁定周期等明确成交话术
- 不要诋毁其他机构
- 不要在回复中出现任何关于AI、人工智能、大模型、系统生成等相关字眼';
}

function ai_get_summer_camp_prompt_template(string $campType): string
{
    $templates = array(
        'zhongkao' => '请根据以下测试数据，生成一份中考体训评估报告：

【学员信息】
- 姓名：{name}
- 年级：{grade}（判断届数：初一=2028届、初二=2027届、初三=2026届）
- 性别：{gender}
- 身高：{height}cm
- 体重：{weight}kg

【测试成绩】
- 跳绳（1分钟）：{jump_rope}个
- 跑步（{run_type}）：{run_minutes}分{run_seconds}秒
- 立定跳远：{long_jump}cm

【教练诊断】
- 跳绳问题：{jump_rope_issue}
- 跑步问题：{run_issue}
- 立定跳远问题：{long_jump_issue}
- 最核心问题：{main_issue}

---

请按以下要求生成报告（A4打印格式，美观结构）：

## 一、贵阳中考体育评分标准说明

首先明确届数和总分：
- **2026届（初三）**：总分50分（现场考试40分+综合素质评价10分）
- **2027届（初二）**：总分60分（现场考试50分+理论考试10分）
- **2028届（初一）**：总分80分（现场考试60分+过程性评价20分）

根据学员年级判断届数后，说明该届考试的具体评分标准。

## 二、一句话总评

用一句话概括孩子的体能状况，要让家长立刻意识到问题的严重性。例如："孩子目前处于\"基础动作模式错误\"阶段，如果不立即纠正，中考体育将被拉开10-15分差距。"

## 三、身体缺失专业解读（核心）

针对教练诊断的核心问题，从肌肉群、解剖学、运动生理学角度进行专业解读：

### 3.1 跳绳技术问题解读（如果有）

**手腕发力不对（用手臂甩绳）**：
- **肌肉群缺失**：腕屈肌、腕伸肌、桡侧腕屈肌、指浅屈肌发力不足，三角肌前束、肱三头肌代偿过度
- **正确发力链条**：大臂定锚→小臂传动→手腕蓄能（270度旋转）
- **后果**：能量消耗增加30%，可能引发肩峰撞击综合征，跳绳成绩卡在140个天花板

**节奏不稳（前快后慢）**：
- **生理机制**：前30秒过度发力导致心肺系统过载，后30秒乳酸堆积无法维持步频
- **肌肉缺失**：核心肌群（腹横肌、臀大肌）无法稳定身体，导致前臂疲劳加速

**跳太高（离地>5cm）**：
- **能量浪费**：每跳多耗0.1秒，1分钟少跳15-20个
- **肌肉缺失**：小腿肌肉（腓肠肌、比目鱼肌）弹性不足，无法实现轻盈落地缓冲

### 3.2 跑步技术问题解读（如果有）

**呼吸没有节奏**：
- **生理机制**：胸式呼吸导致氧气交换效率低，400米后乳酸堆积引发岔气
- **正确模式**：腹式呼吸+\"三步一吸、两步一呼\"节奏

**前快后慢（配速差）**：
- **乳酸堆积机制**：前200米冲太快（超过乳酸阈值），高浓度乳酸阻断神经传导，步频从185降至175
- **肌肉缺失**：臀大肌、腘绳肌、股四头肌抗疲劳能力不足，导致后600米掉速超过10秒
- **后果**：每100米慢1.2秒，1000米多花25秒，直接丢一档分数

**心肺耐力不足**：
- **有氧基础薄弱**：身体利用氧气效率低，过早进入无氧代谢
- **乳酸阈值低**：无法在特定配速下长时间维持，后200米\"撞墙\"现象明显

### 3.3 立定跳远技术问题解读（如果有）

**摆臂不充分**：
- **动力链条断裂**：背阔肌、三角肌后束未参与摆臂，起跳动力不足，少跳10-15cm
- **正确发力**：手臂后摆到极限→快速前摆→同步蹬地

**起跳角度不对**：
- **最佳角度45度**：当前角度小于45度（高度有了远度不够），或大于45度（远度有了落地不稳）
- **肌肉缺失**：臀大肌蹬地力量不足，无法实现最佳抛物线轨迹

**收腿不够**：
- **落地机制错误**：腹直肌、髂腰肌未在落地瞬间主动收腹抬腿，重心靠后，实际成绩比腾空距离短
- **受伤风险**：落地时膝盖过度前伸（角度>90度），可能引发髌腱炎

## 四、各项目当前水平分析

对每个测试项目给出：
- 当前成绩
- 距离满分差距（根据贵阳市届数标准）
- 在同龄孩子中的百分位排名
- 提升空间预估（纠正技术后1个月、3个月可达成绩）
- 关键纠正动作

## 五、本期5次训练重点（自动生成）

根据测试数据和教练诊断，自动生成3个最关键的训练重点：

**重点1**: 针对最核心问题（{main_issue}），给出具体训练重点和预期效果
**重点2**: 针对次要问题，给出配套训练重点和预期效果
**重点3**: 针对整体体能提升，给出综合训练重点和预期效果

示例格式：
- 重点1：纠正手腕发力错误（大臂夹紧训练+手腕画圆摇绳），预期3周后跳绳提升30个
- 重点2：改善跑步配速节奏（前400米压速训练+呼吸节奏调整），预期2周后跑步提升20秒
- 重点3：强化核心稳定性（平板支撑+臀桥训练），预期4周后核心力量提升50%

## 六、阶段性训练目标（教练填写部分）

### 6.1 阶段性目标框架
**第一阶段目标**（本期5次）:
- 训练重点：见第五部分
- 预期提升: __________（教练打印后填写）
- 是否达标: __________（教练打印后填写）

**第二阶段目标**（暑假巩固）:
- 训练重点：__________（教练打印后填写）
- 预期提升: __________（教练打印后填写）
- 阶段意义：第一阶段动作改善后需要巩固提升

**第三阶段目标**（长期提升）:
- 训练重点：__________（教练打印后填写）
- 预期提升: __________（教练打印后填写）
- 阶段意义：稳定成绩需要长期系统训练

### 6.2 训练周期建议
- 第一阶段：暑假班5次训练（每周1次，每次60分钟）
- 第二阶段：暑假巩固训练（每周2次，每次60分钟）
- 第三阶段：长期系统训练（每周3次，每次90分钟）

## 六、中考体育影响测算

根据届数总分标准，给出：
- 当前预估得分（具体分数）
- 第一阶段训练后预估得分
- 第二阶段训练后预估得分
- 第三阶段训练后预估得分
- 提升分数（相当于文化课多少分）

## 七、专业建议与阶段提醒

强调年龄段关键性：
- **初一（2028届）**：动作模式纠偏的最后黄金窗口期（骨骼未定型、肌肉记忆易纠正）
- **初二（2027届）**：动作纠正难度翻倍（骨骼基本定型、肌肉记忆固化）
- **初三（2026届）**：冲刺阶段（纠正难度最高、时间紧迫）

**痛点→结果→行动的逻辑闭环**：
通过专业的身体缺失解读（肌肉群、解剖学），家长已意识到：
- 当前问题的严重性（影响身高、肺活量、中考分数）
- 不纠正的长期后果（成年后脊柱变形不可逆）
- 立即训练的紧迫性（初一窗口期即将关闭）

通过阶段性目标展示，家长已看到：
- 第一阶段（本期5次）的训练重点和预期效果
- 第二阶段训练的巩固必要性
- 第三阶段长期训练的稳定价值

**家长沟通建议**：
- \"第一阶段训练后，孩子技术动作会有更清晰的变化，需要通过复测确认稳定性\"
- \"第二阶段训练会把纠正后的动作转化为更稳定的成绩表现\"
- \"长期系统训练能减少反复和波动，让孩子形成稳定的运动能力\"

**紧迫性强调**：
- \"当前阶段越早建立正确动作模式，后续训练越容易稳定见效\"
- \"持续训练能让孩子把动作纠正转化为成绩稳定\"
- \"问题长期放任会形成习惯性代偿，后续纠正成本更高\"

---
请生成完整的HTML文档（适合A4纸打印，美观排版），格式要求：

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body {
        font-family: \"微软雅黑\", \"Arial\", sans-serif;
        font-size: 15px;
        line-height: 1.6;
        color: #2c3e50;
        max-width: 800px;
        margin: 0 auto;
        padding: 30px 40px;
        background: #ffffff;
    }
    h1 {
        font-size: 22px;
        color: #1a5276;
        border-bottom: 3px solid #e74c3c;
        padding-bottom: 8px;
        margin-top: 10px;
        margin-bottom: 20px;
    }
    h2 {
        font-size: 18px;
        color: #1a5276;
        border-left: 5px solid #e74c3c;
        padding-left: 12px;
        margin-top: 25px;
        margin-bottom: 12px;
    }
    h3 {
        font-size: 16px;
        color: #2c3e50;
        margin-top: 15px;
        margin-bottom: 8px;
    }
    p {
        margin-bottom: 10px;
    }
    ul, ol {
        padding-left: 20px;
        margin: 10px 0;
    }
    li {
        margin-bottom: 5px;
    }
    .highlight-red {
        color: #e74c3c;
        font-weight: bold;
    }
    .highlight-blue {
        color: #2980b9;
        font-weight: bold;
    }
    .highlight-green {
        color: #27ae60;
        font-weight: bold;
    }
    .blank-field {
        background: #f0f0f0;
        padding: 5px 10px;
        display: inline-block;
        min-width: 100px;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
    }
    th {
        background: #1a5276;
        color: white;
        padding: 10px 8px;
        text-align: center;
        font-weight: bold;
    }
    td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }
    tr:nth-child(even) {
        background: #f2f6f9;
    }
    .box {
        background: #f8f9fa;
        border-left: 4px solid #e74c3c;
        padding: 12px 16px;
        margin: 15px 0;
        border-radius: 4px;
    }
    .print-button {
        margin-top: 30px;
        padding: 20px;
        background: #f8f9fa;
        text-align: center;
    }
    button {
        background: #1a5276;
        color: white;
        padding: 10px 30px;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        cursor: pointer;
    }
</style>
</head>
<body>

<!-- 报告标题 -->
<h1>追光小牛 · 中考体训评估报告</h1>

<!-- 学员信息 -->
<p><strong>学员：</strong>{name} &nbsp;&nbsp; <strong>年级：</strong>{grade} &nbsp;&nbsp; <strong>性别：</strong>{gender}</p>
<p><strong>身高：</strong>{height}cm &nbsp;&nbsp; <strong>体重：</strong>{weight}kg</p>

<!-- 报告内容各部分 -->
...（按前面要求的7个部分生成）...

<!-- 导出打印按钮 -->
<div class="print-button">
    <button onclick="window.print()">导出打印报告</button>
    <p style="margin-top: 10px; font-size: 13px; color: #7f8c8d;">提示：教练打印后填写空白部分</p>
</div>

</body>
</html>

关键要求：
1. **完整HTML文档**：包含<!DOCTYPE>、<html>、<head>、<style>、<body>完整结构
2. **内联CSS样式**：所有样式在<style>标签内定义，不依赖外部文件
3. **A4打印标准**：max-width: 800px（适合A4纸宽度）
4. **美观排版**：
   - 标题：22px，蓝色带红色下划线
   - 小节标题：18px，蓝色带红色左边框
   - 正文：15px，微软雅黑字体
   - 重点数据：红色突出显示
   - 空白填写：灰色背景标注
5. **导出打印**：底部按钮onclick="window.print()"
6. **销售引导**：不提金额，通过痛点→结果→行动引导
7. **专业口吻**：不提AI字眼，真诚紧迫',
        
        'tineng' => '请根据以下测试数据，生成一份体能评估报告：

【学员信息】
- 姓名：{name}
- 年级：{grade}
- 性别：{gender}
- 身高：{height}cm
- 体重：{weight}kg

【测试成绩】
- 肺活量：{vital_capacity}ml
- 50米跑：{sprint_50m}秒
- 跳绳（1分钟）：{jump_rope}个
- 坐位体前屈：{sit_reach}cm

【教练诊断】
- 最核心问题：{main_issue}

请按以下结构生成报告（使用HTML标签格式化）：

1. 【体能画像】：用3-4个关键词描述孩子的体能特征。

2. 【核心问题分析】：用"问题→对学习/生活的影响→对发育的影响"三段式。

3. 【各项目分析】：当前水平、同龄对比、问题根源、改善动作。

4. 【体能与学习的关系】：关联注意力、坐姿、运动受伤风险。

5. 【5天训练计划】：每天具体动作+预期效果。

6. 【行动建议】：强调6-12岁关键窗口期。',
        
        'tiaosheng' => '请根据以下测试数据，生成一份跳绳评估报告：

【学员信息】
- 姓名：{name}
- 年级：{grade}
- 性别：{gender}

【测试成绩】
- 1分钟跳绳：{jump_rope_1min}个
- 30秒跳绳：{jump_rope_30s}个
- 连续不掉绳：{consecutive}个

【教练诊断】
- 核心问题：{main_issue}

请按以下结构生成报告（使用HTML标签格式化）：

1. 【一句话诊断】：概括核心问题。

2. 【技术分析】：发力分析、节奏分析、体能分析。

3. 【跳绳与体育成绩的关系】：中考体育最容易拿满分的项目。

4. 【5天训练计划】：纠正发力、建立节奏、提升速度、提升耐力、模拟测试。

5. 【家长配合事项】：在家练习方法、检查要点、鼓励方式。',
        
        'lanqiu' => '请根据以下测试数据，生成一份篮球技能评估报告：

【学员信息】
- 姓名：{name}
- 年龄：{age}
- 基础：{level}

【测试成绩】
- 原地运球(30秒)：{dribble}次
- 定点投篮(10次)：{shoot}个
- 三步上篮(10次)：{layup}个

【教练诊断】
- 核心问题：{main_issue}

请按以下结构生成报告（使用HTML标签格式化）：

1. 【技能画像】：球感、运球、投篮、上篮评级。

2. 【核心问题分析】：运球、投篮、上篮问题及后果。

3. 【篮球与体能的关系】：手眼协调、核心力量、爆发力。

4. 【5天训练计划】：球感、运球、投篮、上篮、综合。

5. 【家长配合事项】：在家练习方法、检查要点。',
        
        'tuobei' => '请根据以下测试数据，生成一份体态评估报告：

【学员信息】
- 姓名：{name}
- 年龄：{age}
- 性别：{gender}

【测试成绩】
- 坐位体前屈：{sit_reach}cm
- 平板支撑：{plank}秒
- 俯卧撑：{pushup}个

【教练诊断】
- 体态问题：{posture_issue}
- 严重程度：{severity}

请按以下结构生成报告（使用HTML标签格式化）：

1. 【体态问题诊断】：含胸驼背、头前伸、高低肩等问题。

2. 【问题分析】：对身高、气质、健康的影响。

3. 【功能性测试分析】：柔韧性、肌肉力量评估。

4. 【5天训练计划】：拉伸、胸椎矫正、肩部矫正、核心训练、综合矫正。

5. 【家长须知】：日常注意事项、日常姿势指导。'
    );

    $templates['zhongkao'] = '请根据以下测试数据，生成一份中考体训评估报告的结构化内容：

【学员信息】
- 姓名：{name}
- 年级：{grade}（判断届数：初一=2028届、初二=2027届、初三=2026届）
- 性别：{gender}
- 身高：{height}cm
- 体重：{weight}kg

【测试成绩】
- 跳绳（1分钟）：{jump_rope}个
- 跑步（{run_type}）：{run_minutes}分{run_seconds}秒
- 立定跳远：{long_jump}cm

【教练诊断】
- 跳绳问题：{jump_rope_issue}
- 跑步问题：{run_issue}
- 立定跳远问题：{long_jump_issue}
- 最核心问题：{main_issue}

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，45字以内",
  "standard": "根据年级说明对应届数和体育总分，70字以内",
  "analysis": ["身体素质缺失解读1，60字以内", "身体素质缺失解读2，60字以内", "身体素质缺失解读3，60字以内"],
  "training_focus": ["本期5次训练重点1，60字以内", "本期5次训练重点2，60字以内", "本期5次训练重点3，60字以内"],
  "age_advice": "根据年级输出对应阶段建议，80字以内",
  "parent_message": "给家长的重视与坚持建议，80字以内"
}

内容要求：
1. 初一对应2028届，总分80分；初二对应2027届，总分60分；初三对应2026届，总分50分。
2. 根据实际年级写年龄段建议，不能固定写初一窗口期。
3. analysis只写家长能理解的身体素质缺失，不写长篇解剖学。
4. training_focus写本期5次训练重点，包含问题、训练方向和预期变化。
5. parent_message通过专业判断让家长感受到重视、焦虑和坚持训练的必要性。
6. 不写具体价格、优惠、套餐金额。
7. 不写购买、续课、正式课包、锁定周期等明确销售文字。
8. 不出现AI、人工智能、大模型、系统生成等字眼。';

    return (string) ($templates[$campType] ?? '');
}
