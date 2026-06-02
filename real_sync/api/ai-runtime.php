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

    $content = (string) (($result['body']['choices'][0]['message']['content'] ?? ''));
    return ai_extract_json_object($content, '智谱识别失败');
}
