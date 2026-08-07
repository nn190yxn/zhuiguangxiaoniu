<?php
declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'Forbidden'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/platform/AiCapabilityGateway.php';

final class AiRuntimeInvocationStore implements PlatformAiInvocationStore
{
    private bool $databaseChecked = false;
    private ?PlatformPdoAiInvocationStore $databaseStore = null;

    public function recordInvocation(array $invocation): void
    {
        if (!$this->databaseChecked) {
            $this->databaseChecked = true;
            if (function_exists('getDB')) {
                try {
                    $pdo = getDB();
                    if ($pdo instanceof PDO) {
                        $this->databaseStore = new PlatformPdoAiInvocationStore($pdo);
                    }
                } catch (Throwable $exception) {
                    error_log('AI invocation database audit unavailable: ' . $exception->getMessage());
                }
            }
        }

        if ($this->databaseStore !== null) {
            try {
                $this->databaseStore->recordInvocation($invocation);
                return;
            } catch (Throwable $exception) {
                error_log('AI invocation database audit failed: ' . $exception->getMessage());
            }
        }

        $encoded = json_encode($invocation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            error_log('AI invocation audit: ' . $encoded);
        }
    }
}

function ai_runtime_gateway(): PlatformAiCapabilityGateway
{
    static $gateway = null;
    if ($gateway instanceof PlatformAiCapabilityGateway) {
        return $gateway;
    }

    $providers = array(
        'deepseek' => static function (array $request): array {
            if (($request['capability'] ?? '') !== PlatformAiCapabilityGateway::TEXT_GENERATE) {
                throw new PlatformAiException('capability_unsupported', 'deepseek');
            }
            $input = (array) ($request['input'] ?? array());
            try {
                $content = ai_deepseek_chat(
                    trim((string) ($input['prompt'] ?? '')),
                    trim((string) ($input['system_prompt'] ?? '')),
                    (int) ($input['max_tokens'] ?? 3000),
                    (float) ($input['temperature'] ?? 0.7)
                );
            } catch (Throwable $exception) {
                throw PlatformAiException::providerFailure(
                    str_contains($exception->getMessage(), '后台未配置') ? 'provider_unconfigured' : 'provider_unavailable',
                    $exception->getMessage(),
                    'deepseek'
                );
            }
            $settings = ai_runtime_load_settings();
            return array(
                'model' => trim((string) ($settings['deepseek_model'] ?? 'deepseek-v4-flash')),
                'processing_version' => 'deepseek-text-v1',
                'output' => array('content' => $content),
            );
        },
        'baidu_ocr' => static function (array $request): array {
            if (($request['capability'] ?? '') !== PlatformAiCapabilityGateway::OCR_EXTRACT) {
                throw new PlatformAiException('capability_unsupported', 'baidu_ocr');
            }
            $input = (array) ($request['input'] ?? array());
            try {
                $providerTimeout = max(1, min(60, (int) ceil(((int) ($request['timeout_ms'] ?? 45000)) / 1000)));
                $text = ai_baidu_ocr_text(trim((string) ($input['image'] ?? '')), $providerTimeout);
            } catch (Throwable $exception) {
                throw PlatformAiException::providerFailure(
                    str_contains($exception->getMessage(), '后台未配置') ? 'provider_unconfigured' : 'provider_unavailable',
                    $exception->getMessage(),
                    'baidu_ocr'
                );
            }
            return array(
                'model' => 'baidu-general-basic',
                'processing_version' => 'baidu-ocr-v1',
                'output' => array('text' => $text),
            );
        },
    );

    $gateway = new PlatformAiCapabilityGateway(
        new AiRuntimeInvocationStore(),
        $providers,
        array(
            PlatformAiCapabilityGateway::TEXT_GENERATE => array('deepseek'),
            PlatformAiCapabilityGateway::OCR_EXTRACT => array('baidu_ocr'),
        ),
        static function (array $decision): array {
            $context = (array) ($decision['approval_context'] ?? array());
            $approved = in_array((string) ($decision['data_classification'] ?? ''), array('public', 'internal'), true)
                || ($context['business_authorized'] ?? false) === true;
            return array(
                'approved' => $approved,
                'approval_id' => $approved ? trim((string) ($context['approval_id'] ?? 'runtime-business-approval')) : null,
                'reason_code' => $approved ? 'approved' : 'explicit_data_processing_approval_required',
            );
        }
    );

    return $gateway;
}

function ai_gateway_request_id(): string
{
    $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    return PlatformRequestContext::isValidRequestId($incoming)
        ? $incoming
        : 'ai-runtime-' . bin2hex(random_bytes(12));
}

function ai_gateway_text_generate(
    string $prompt,
    string $systemPrompt,
    string $purpose,
    array $options = array()
): string {
    if (trim($prompt) === '' || trim($systemPrompt) === '') {
        throw new InvalidArgumentException('AI 文本生成输入不完整');
    }
    $result = ai_runtime_gateway()->invoke(array(
        'capability' => PlatformAiCapabilityGateway::TEXT_GENERATE,
        'contract_version' => 'ai-capability.v1',
        'request_id' => isset($options['request_id']) && PlatformRequestContext::isValidRequestId((string) $options['request_id'])
            ? (string) $options['request_id']
            : ai_gateway_request_id(),
        'purpose' => $purpose,
        'data_classification' => (string) ($options['data_classification'] ?? 'personal'),
        'input' => array(
            'prompt' => $prompt,
            'system_prompt' => $systemPrompt,
            'max_tokens' => (int) ($options['max_tokens'] ?? 3000),
            'temperature' => (float) ($options['temperature'] ?? 0.7),
        ),
        'preferred_provider' => 'deepseek',
        'timeout_ms' => (int) ($options['timeout_ms'] ?? 60000),
        'max_attempts' => (int) ($options['max_attempts'] ?? 2),
        'idempotency_key' => (string) ($options['idempotency_key'] ?? 'ai-text-' . bin2hex(random_bytes(12))),
        'retention_policy_code' => (string) ($options['retention_policy_code'] ?? 'ai-summary-180d'),
        'approval_context' => array(
            'business_authorized' => ($options['business_authorized'] ?? false) === true,
            'approval_id' => (string) ($options['approval_id'] ?? 'runtime-business-approval'),
        ),
    ));
    return trim((string) ($result['output']['content'] ?? ''));
}

function ai_gateway_ocr_extract(string $imageInput, string $purpose, array $options = array()): string
{
    if (trim($imageInput) === '') {
        throw new InvalidArgumentException('缺少图片数据');
    }
    $result = ai_runtime_gateway()->invoke(array(
        'capability' => PlatformAiCapabilityGateway::OCR_EXTRACT,
        'contract_version' => 'ai-capability.v1',
        'request_id' => isset($options['request_id']) && PlatformRequestContext::isValidRequestId((string) $options['request_id'])
            ? (string) $options['request_id']
            : ai_gateway_request_id(),
        'purpose' => $purpose,
        'data_classification' => (string) ($options['data_classification'] ?? 'personal'),
        'input' => array('image' => $imageInput),
        'preferred_provider' => 'baidu_ocr',
        'timeout_ms' => (int) ($options['timeout_ms'] ?? 90000),
        'max_attempts' => (int) ($options['max_attempts'] ?? 2),
        'idempotency_key' => (string) ($options['idempotency_key'] ?? 'ai-ocr-' . bin2hex(random_bytes(12))),
        'retention_policy_code' => (string) ($options['retention_policy_code'] ?? 'ai-summary-180d'),
        'approval_context' => array(
            'business_authorized' => ($options['business_authorized'] ?? false) === true,
            'approval_id' => (string) ($options['approval_id'] ?? 'runtime-business-approval'),
        ),
    ));
    return trim((string) ($result['output']['text'] ?? ''));
}

function ai_runtime_load_settings(): array
{
    $settings = array(
        'deepseek_api_key' => trim((string) (getenv('DEEPSEEK_API_KEY') ?: '')),
        'deepseek_model' => trim((string) (getenv('DEEPSEEK_MODEL') ?: 'deepseek-v4-flash')),
        'zhipu_api_key' => trim((string) getenv('ZHIPU_API_KEY')),
        'baidu_ocr_api_key' => trim((string) getenv('BAIDU_OCR_API_KEY')),
        'baidu_ocr_secret_key' => trim((string) getenv('BAIDU_OCR_SECRET_KEY')),
        'doubao_api_key' => trim((string) getenv('DOUBAO_API_KEY')),
        'doubao_model' => trim((string) getenv('DOUBAO_MODEL')),
    );

    if (function_exists('ai_load_settings')) {
        try {
            $adminSettings = ai_load_settings();
            if (is_array($adminSettings)) {
                foreach ($adminSettings as $key => $value) {
                    if (array_key_exists((string) $key, $settings) && trim((string) $value) !== '') {
                        $settings[(string) $key] = trim((string) $value);
                    }
                }
            }
        } catch (Throwable $exception) {
            error_log('AI runtime admin settings load failed: ' . $exception->getMessage());
        }
    }

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
    return ai_has_service('baidu_ocr');
}

function ai_ocr_time_remaining(float $deadline): int
{
    $remaining = (int) ceil($deadline - microtime(true));
    if ($remaining <= 0) {
        throw new RuntimeException('OCR 总处理时间已超时');
    }
    return min($remaining, 60);
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

    if (preg_match('#^data:image/([^;]+);base64,(.+)$#is', $imageInput, $matches) === 1) {
        $mime = strtolower(trim($matches[1]));
        if (!in_array($mime, array('jpeg', 'jpg', 'png', 'webp'), true)) {
            throw new InvalidArgumentException('图片输入格式不兼容，请使用 JPG、PNG 或 WebP 图片');
        }
        $imageInput = $matches[2];
    }

    $imageInput = preg_replace('/\s+/', '', $imageInput) ?? '';
    if ($imageInput === '') {
        throw new InvalidArgumentException('图片数据为空');
    }

    $binary = base64_decode($imageInput, true);
    if ($binary === false) {
        throw new InvalidArgumentException('图片 base64 数据无效');
    }
    if (strlen($binary) > 4 * 1024 * 1024) {
        throw new InvalidArgumentException('图片过大，请压缩到 4MB 以内后重试');
    }

    return $imageInput;
}

function ai_deepseek_chat(string $prompt, string $systemPrompt, int $maxTokens = 3000, float $temperature = 0.7, int $timeout = 60, bool $jsonObject = false): string
{
    $settings = ai_runtime_load_settings();
    $apiKey = trim((string) ($settings['deepseek_api_key'] ?? ''));
    $model = trim((string) ($settings['deepseek_model'] ?? 'deepseek-v4-flash'));
    if ($apiKey === '') {
        throw new RuntimeException('DeepSeek 后台未配置');
    }

    $payload = array(
        'model' => $model,
        'messages' => array(
            array('role' => 'system', 'content' => $systemPrompt),
            array('role' => 'user', 'content' => $prompt),
        ),
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
    );
    if ($jsonObject) {
        $payload['response_format'] = array('type' => 'json_object');
    }

    $result = ai_post_json(
        'https://api.deepseek.com/chat/completions',
        array('Authorization' => 'Bearer ' . $apiKey),
        $payload,
        $timeout
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

function ai_parse_fitness_ocr_text(string $ocrText): array
{
    $text = str_replace(array("\r\n", "\r", '×', 'Ｘ'), array("\n", "\n", 'x', 'x'), trim($ocrText));
    $lines = array_values(array_filter(array_map(
        static fn(string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? ''),
        explode("\n", $text)
    ), static fn(string $line): bool => $line !== ''));
    $result = array();

    if (preg_match('/姓名\s*[:：]?\s*([\x{4e00}-\x{9fa5}A-Za-z·]{1,32})/u', $text, $matches) === 1) {
        $result['name'] = $matches[1];
    }
    if (preg_match('/性别\s*[:：]?\s*([男女])/u', $text, $matches) === 1) {
        $result['gender'] = $matches[1];
    }
    if (preg_match('/年龄\s*[:：]?\s*(\d{1,2})\s*岁(?:\s*(\d{1,2})\s*个?月)?/u', $text, $matches) === 1) {
        $result['ageYears'] = (int) $matches[1];
        $result['ageMonths'] = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
    }
    if (preg_match('/(?:测试日期|测评日期|日期)\s*[:：]?\s*(\d{4})[年\/\-.](\d{1,2})[月\/\-.](\d{1,2})日?/u', $text, $matches) === 1) {
        $result['testDate'] = sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    $fieldAliases = array(
        'shuttle_run_4x10' => array('4x10米往返跑', '4x10米折返跑'),
        'standing_jump' => array('立定跳远'),
        'continuous_jump' => array('双脚连续跳'),
        'rope_skip' => array('一分钟跳绳', '1分钟跳绳', '跳绳'),
        'tennis_throw' => array('网球掷远'),
        'sit_ups' => array('一分钟仰卧起坐', '1分钟仰卧起坐', '仰卧起坐'),
        'sit_reach' => array('坐位体前屈'),
        'balance_beam' => array('走平衡木', '平衡木'),
        'step_test' => array('台阶测试'),
        'shuttle_run' => array('10米折返跑', '十米折返跑'),
        'height' => array('身高'),
        'weight' => array('体重'),
        'bmi' => array('BMI', '身体质量指数'),
    );
    $ratings = array('待提升', '优秀', '良好', '中等', '合格', '一般', '较差', '欠佳', '标准', '偏胖', '偏瘦', '正常', '偏高', '偏低');

    foreach ($fieldAliases as $field => $aliases) {
        foreach ($lines as $index => $line) {
            $matchedAlias = null;
            foreach ($aliases as $alias) {
                if (mb_stripos($line, $alias, 0, 'UTF-8') !== false) {
                    $matchedAlias = $alias;
                    break;
                }
            }
            if ($matchedAlias === null) {
                continue;
            }

            $aliasPosition = mb_stripos($line, $matchedAlias, 0, 'UTF-8');
            $valueText = mb_substr($line, (int) $aliasPosition + mb_strlen($matchedAlias, 'UTF-8'), null, 'UTF-8');
            if (preg_match('/[-+]?\d+(?:\.\d+)?/', $valueText, $valueMatch, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $numeric = (float) $valueMatch[0][0];
            $result[$field] = floor($numeric) === $numeric ? (int) $numeric : $numeric;

            $ratingText = substr($valueText, (int) $valueMatch[0][1] + strlen((string) $valueMatch[0][0]));
            $matchedRating = null;
            $matchedRatingPosition = null;
            foreach ($ratings as $rating) {
                $ratingPosition = mb_strpos($ratingText, $rating, 0, 'UTF-8');
                if ($ratingPosition !== false && ($matchedRatingPosition === null || $ratingPosition < $matchedRatingPosition)) {
                    $matchedRating = $rating;
                    $matchedRatingPosition = $ratingPosition;
                }
            }
            if ($matchedRating !== null) {
                $result[$field . '_rating'] = $matchedRating;
            }
            break;
        }
    }

    if ($result === array()) {
        throw new RuntimeException('百度 OCR 未提取到有效体测数据');
    }

    return $result;
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

function ai_numeric_measurement($value): ?float
{
    if (is_int($value) || is_float($value)) {
        $number = (float) $value;
    } else {
        $text = trim((string) $value);
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(?:cm|kg|次|秒|m|ml|毫升)?\s*(?:优秀|良好|中等|合格|一般|较差|欠佳|标准|偏胖|偏瘦|正常|偏高|偏低|待提升)?$/iu', $text, $matches) !== 1) {
            return null;
        }
        $number = (float) $matches[1];
    }

    return is_finite($number) && $number >= 0 && $number <= 100000 ? $number : null;
}

function ai_has_ocr_measurement(array $result): bool
{
    foreach (array('height', 'weight', 'bmi', 'standing_jump', 'continuous_jump', 'rope_skip', 'tennis_throw', 'sit_ups', 'sit_reach', 'balance_beam', 'step_test', 'shuttle_run', 'shuttle_run_4x10') as $field) {
        if (ai_numeric_measurement($result[$field] ?? null) !== null) {
            return true;
        }
    }
    return false;
}

function ai_normalize_result_field_key(string $key): ?string
{
    $map = array(
        'name' => 'name',
        'gender' => 'gender',
        'ageYears' => 'ageYears',
        'ageMonths' => 'ageMonths',
        'testDate' => 'testDate',
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

function ai_pick_ocr_item_value(array $item)
{
    foreach (array('测试值', '测试结果', '数值', '成绩', '结果', 'value', 'score', 'result') as $key) {
        if (array_key_exists($key, $item) && ai_has_value($item[$key])) {
            return $item[$key];
        }
    }
    return null;
}

function ai_pick_ocr_item_rating(array $item)
{
    foreach (array('评级', '等级', '评价', 'rating', 'level') as $key) {
        if (array_key_exists($key, $item) && ai_has_value($item[$key])) {
            return $item[$key];
        }
    }
    return null;
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
        $field = ai_normalize_result_field_key($baseKey) ?? ai_map_item_name_to_field($baseKey);
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
                $itemValue = ai_pick_ocr_item_value($item);
                if ($itemValue !== null && !isset($normalized[$field])) {
                    $normalized[$field] = $itemValue;
                }
                $itemRating = ai_pick_ocr_item_rating($item);
                if ($itemRating !== null && !isset($normalized[$field . '_rating'])) {
                    $normalized[$field . '_rating'] = $itemRating;
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
            $itemValue = ai_pick_ocr_item_value($item);
            if ($itemValue !== null && !isset($normalized[$field])) {
                $normalized[$field] = $itemValue;
            }
            $itemRating = ai_pick_ocr_item_rating($item);
            if ($itemRating !== null && !isset($normalized[$field . '_rating'])) {
                $normalized[$field . '_rating'] = $itemRating;
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
            $itemValue = ai_pick_ocr_item_value($item);
            if ($itemValue !== null && !isset($normalized[$field])) {
                $normalized[$field] = $itemValue;
            }
            $itemRating = ai_pick_ocr_item_rating($item);
            if ($itemRating !== null && !isset($normalized[$field . '_rating'])) {
                $normalized[$field . '_rating'] = $itemRating;
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
        'request_id' => (string) ($meta['request_id'] ?? ''),
        'provider' => (string) ($meta['provider'] ?? 'unknown'),
        'duration_ms' => (int) ($meta['duration_ms'] ?? 0),
        'input_bytes' => (int) ($meta['input_bytes'] ?? 0),
        'measurement_field_count' => count(array_filter($result, static function ($value, $key): bool {
            return substr((string) $key, -7) !== '_rating' && ai_numeric_measurement($value) !== null;
        }, ARRAY_FILTER_USE_BOTH)),
    );

    $line = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    @file_put_contents($logDir . '/fitness-ocr.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function ai_ocr_failure_code(Throwable $exception): string
{
    $message = $exception->getMessage();
    if ($exception instanceof InvalidArgumentException) {
        return 'invalid_input';
    }
    if (strpos($message, '总处理时间已超时') !== false || strpos($message, 'timeout') !== false) {
        return 'time_budget_exhausted';
    }
    if (strpos($message, '获取 token 失败') !== false) {
        return 'baidu_token_request_failed';
    }
    if (strpos($message, '百度 OCR') !== false || strpos($message, 'provider_') !== false) {
        return 'baidu_ocr_failed';
    }
    if (strpos($message, '未提取到有效体测数据') !== false || strpos($message, '未识别到文字') !== false) {
        return 'no_measurements_extracted';
    }
    return 'unexpected_runtime_error';
}

function ai_log_ocr_stage(string $requestId, string $stage, string $status, int $durationMs, int $inputBytes, array $details = array()): void
{
    $baseDir = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $logDir = $baseDir . '/wp-content/uploads/ocr-logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        return;
    }
    $summary = array_merge(array(
        'time' => gmdate('c'),
        'event' => 'stage',
        'request_id' => $requestId,
        'stage' => $stage,
        'status' => $status,
        'duration_ms' => $durationMs,
        'input_bytes' => $inputBytes,
    ), $details);
    $line = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        @file_put_contents($logDir . '/fitness-ocr.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function ai_log_ocr_failure(string $requestId, string $stage, Throwable $exception, int $durationMs, int $inputBytes): void
{
    ai_log_ocr_stage($requestId, $stage, 'failed', $durationMs, $inputBytes, array(
        'failure_code' => ai_ocr_failure_code($exception),
        'error_code' => substr(hash('sha256', $exception->getMessage()), 0, 16),
    ));
}

function ai_fitness_ocr_fields(): array
{
    return array('height', 'weight', 'bmi', 'standing_jump', 'continuous_jump', 'rope_skip', 'tennis_throw', 'sit_ups', 'sit_reach', 'balance_beam', 'step_test', 'shuttle_run', 'shuttle_run_4x10');
}

function ai_fitness_ocr_missing_rating_fields(array $result): array
{
    $missing = array();
    foreach (ai_fitness_ocr_fields() as $field) {
        if (ai_numeric_measurement($result[$field] ?? null) !== null && !ai_has_value($result[$field . '_rating'] ?? null)) {
            $missing[] = $field;
        }
    }
    return $missing;
}

function ai_merge_fitness_vision_result(array $result, array $visionResult): array
{
    foreach (ai_fitness_ocr_fields() as $field) {
        if (ai_numeric_measurement($result[$field] ?? null) === null) {
            $visionNumber = ai_numeric_measurement($visionResult[$field] ?? null);
            if ($visionNumber !== null) {
                $result[$field] = floor($visionNumber) === $visionNumber ? (int) $visionNumber : $visionNumber;
            }
        }

        if (!ai_has_value($result[$field . '_rating'] ?? null) && ai_has_value($visionResult[$field . '_rating'] ?? null)) {
            $result[$field . '_rating'] = trim((string) $visionResult[$field . '_rating']);
        }
    }
    return $result;
}

function ai_get_fitness_ocr_vision_prompt(string $prompt): string
{
    $basePrompt = trim($prompt);
    return ($basePrompt !== '' ? $basePrompt . "\n\n" : '')
        . '请从这张儿童体测报告中提取结构化 JSON。必须逐项读取图片原文中的测试值和评级文字，不要根据数字自行计算等级。'
        . '字段包括：height,height_rating,weight,weight_rating,bmi,bmi_rating,standing_jump,standing_jump_rating,continuous_jump,continuous_jump_rating,rope_skip,rope_skip_rating,tennis_throw,tennis_throw_rating,sit_ups,sit_ups_rating,sit_reach,sit_reach_rating,balance_beam,balance_beam_rating,step_test,step_test_rating,shuttle_run,shuttle_run_rating,shuttle_run_4x10,shuttle_run_4x10_rating。'
        . '图片没有的项目填 null。评级必须使用图片原文，例如 优秀、良好、中等、合格、一般、较差、欠佳、标准、偏胖、偏瘦、正常、偏高、偏低、待提升。';
}
function ai_ocr_fitness_image(string $imageDataUrl, string $imageUrl, string $prompt, array $options = array()): array
{
    if (!ai_has_service('baidu_ocr')) {
        throw new RuntimeException('百度 OCR 后台未配置');
    }

    $startedAt = microtime(true);
    $normalizedInput = ai_normalize_vision_input($imageDataUrl);
    $inputBytes = preg_match('#^https?://#i', $normalizedInput) === 1 ? 0 : (int) floor(strlen($normalizedInput) * 3 / 4);
    $ocrText = ai_gateway_ocr_extract($imageDataUrl, 'fitness_ocr', $options);
    $result = ai_normalize_ocr_result(ai_parse_fitness_ocr_text($ocrText));
    $provider = 'baidu_ocr_deterministic_parser';
    $visionInput = preg_match('#^https?://#i', $normalizedInput) === 1 ? $normalizedInput : trim($imageUrl);
    if ($visionInput !== '' && ai_fitness_ocr_missing_rating_fields($result) !== array() && ai_has_service('doubao')) {
        try {
            $visionResult = ai_doubao_vision($visionInput, ai_get_fitness_ocr_vision_prompt($prompt));
            $result = ai_merge_fitness_vision_result($result, $visionResult);
            $provider = 'baidu_ocr_with_doubao_rating_fill';
        } catch (Throwable $exception) {
            error_log('Fitness OCR rating fill unavailable: ' . $exception->getMessage());
        }
    }
    if (!ai_has_ocr_measurement($result)) {
        throw new RuntimeException('OCR 未提取到有效体测数据');
    }
    ai_log_ocr_result($imageUrl, '', $result, array(
        'request_id' => (string) ($options['request_id'] ?? ''),
        'provider' => $provider,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'input_bytes' => $inputBytes,
    ));
    return $result;
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
5. 家长引导：通过专业诊断让家长理解问题需要重视，并理解坚持训练的必要性

禁止事项：
- 不要输出完整HTML、Markdown代码块或CSS
- 不要说"保过""满分""100%达标"
- 不要承诺具体分数
- 不要写价格、金额、优惠、套餐
- 不要写购买、续课、正式课包、锁定周期等明确成交话术
- 不要诋毁其他机构
- 不要在回复中出现任何关于AI、人工智能、大模型、系统生成等相关字眼';
}

function ai_get_summer_camp_json_prompt_templates(): array
{
    return array(
        'zhongkao' => <<<'PROMPT'
请根据以下测试数据，生成一份中考体训评估报告的结构化内容：

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

【评价依据】
- 采用贵阳市教育局《贵阳市2026-2028届初中学业水平考试体育考试实施方案》（筑教发〔2026〕12号）附件2《统一现场考试内容及评分标准（试行）》。
- 初一（2028届）现场项目：男1000米10分线4'31"，女800米10分线3'57"，一分钟跳绳男156次、女161次；初一没有立定跳远正式评分项，立定跳远只作为下肢爆发力诊断参考。
- 初二（2027届）现场项目：男1000米10分线4'03"，女800米10分线3'42"；初二没有立定跳远正式评分项，立定跳远只作为下肢爆发力诊断参考。
- 初三（2026届）现场项目：男1000米10分线3'42"，女800米10分线3'35"，立定跳远男215cm、女182cm。

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，80字以内",
  "standard": "根据年级说明对应届数、体育总分和当前测试差距，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对中考体育得分稳定性、专项发挥和后续训练成本的影响，160字以内",
  "age_advice": "根据年级输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. 初一对应2028届，总分80分；初二对应2027届，总分60分；初三对应2026届，总分50分。
2. standard必须引用【评价依据】中的对应届数和项目标准，初一、初二不得把立定跳远写成正式评分项。
3. 根据实际年级写年龄段建议，不能固定写初一窗口期。
4. analysis要有专业深度，但必须让家长看懂；可解释心肺耐力、下肢爆发力、核心稳定、协调节奏、发力链条、动作代偿。
5. training_focus写本期5次训练重点，包含问题、训练方向、观察指标和预期变化，不要写成每日训练动作清单。
6. score_impact要从成绩稳定性和后续纠正成本角度制造重视感。
7. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
8. 不写具体价格、优惠、套餐金额。
9. 不写购买、续课、正式课包、锁定周期等明确销售文字。
10. 不出现AI、人工智能、大模型、系统生成等字眼。
PROMPT,
        'tineng' => <<<'PROMPT'
请根据以下测试数据，生成一份体能达标营评估报告的结构化内容：

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
- 肺活量/心肺问题：{vital_issue}
- 速度/协调问题：{speed_issue}
- 柔韧/基础力量问题：{flex_issue}
- 最核心问题：{main_issue}

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，80字以内",
  "standard": "结合5-12岁基础体能发展要求，说明当前心肺、速度、协调、柔韧的达标情况，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对体能达标稳定性、运动参与和日常学习状态的影响，160字以内",
  "age_advice": "根据年级或年龄输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. standard要围绕基础体能达标，不套用中考分值。
2. analysis要解释心肺耐力、速度启动、协调节奏、柔韧活动度、核心稳定和基础发育之间的关系。
3. training_focus写本期5次训练重点，包含训练方向、观察指标和预期变化，不要写成每日训练动作清单。
4. score_impact要从体能基础、课堂专注、运动安全和后续训练成本角度制造重视感。
5. age_advice必须根据实际年级或年龄输出，不能固定写单一年龄窗口。
6. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
7. 不写具体价格、优惠、套餐金额。
8. 不写购买、续课、正式课包、锁定周期等明确销售文字。
9. 不出现AI、人工智能、大模型、系统生成等字眼。
PROMPT,
        'tiaosheng' => <<<'PROMPT'
请根据以下测试数据，生成一份跳绳达标营评估报告的结构化内容：

【学员信息】
- 姓名：{name}
- 年级：{grade}
- 性别：{gender}
- 身高：{height}cm
- 体重：{weight}kg

【测试成绩】
- 1分钟跳绳：{jump_rope_1min}个
- 30秒跳绳：{jump_rope_30s}个
- 连续不掉绳：{consecutive}个

【教练诊断】
- 跳绳核心问题：{general_issue}
- 最核心问题：{main_issue}

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，80字以内",
  "standard": "结合跳绳达标要求，说明当前速度、连续性、节奏和稳定性水平，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对跳绳达标、体育成绩稳定性和后续纠错成本的影响，160字以内",
  "age_advice": "根据年级或年龄输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. standard要围绕跳绳达标，不套用中考总分。
2. analysis要解释摇绳发力、起跳高度、节奏控制、连续性、耐力和手脚协调之间的关系。
3. training_focus写本期5次训练重点，包含训练方向、观察指标和预期变化，不要写成每日训练动作清单。
4. score_impact要从动作固化、速度上限、掉绳风险和后续纠错成本角度制造重视感。
5. age_advice必须根据实际年级或年龄输出，不能固定写单一年龄窗口。
6. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
7. 不写具体价格、优惠、套餐金额。
8. 不写购买、续课、正式课包、锁定周期等明确销售文字。
9. 不出现AI、人工智能、大模型、系统生成等字眼。
PROMPT,
        'lanqiu' => <<<'PROMPT'
请根据以下测试数据，生成一份篮球体能营评估报告的结构化内容：

【学员信息】
- 姓名：{name}
- 年级：{grade}
- 性别：{gender}
- 身高：{height}cm
- 体重：{weight}kg

【测试成绩】
- 原地运球（30秒）：{dribble}次
- 定点投篮（10次）：{shoot}个
- 三步上篮（10次）：{layup}个

【教练诊断】
- 运球问题：{dribble_issue}
- 投篮问题：{shoot_issue}
- 上篮/脚步问题：{layup_issue}
- 最核心问题：{main_issue}

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，80字以内",
  "standard": "结合篮球基础能力要求，说明当前球感、运球、投篮、上篮和体能支撑情况，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对篮球技能进阶、运动自信和后续训练成本的影响，160字以内",
  "age_advice": "根据年级或年龄输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. standard要围绕篮球基础技能和专项体能，不套用中考分值。
2. analysis要解释球感、手眼协调、运球稳定性、投篮发力链条、上篮脚步、核心控制和爆发力之间的关系。
3. training_focus写本期5次训练重点，包含训练方向、观察指标和预期变化，不要写成每日训练动作清单。
4. score_impact要从技能迁移、对抗能力、参与自信和后续训练成本角度制造重视感。
5. age_advice必须根据实际年级或年龄输出，不能固定写单一年龄窗口。
6. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
7. 不写具体价格、优惠、套餐金额。
8. 不写购买、续课、正式课包、锁定周期等明确销售文字。
9. 不出现AI、人工智能、大模型、系统生成等字眼。
PROMPT,
        'tuobei' => <<<'PROMPT'
请根据以下测试数据，生成一份驼背体态调整营评估报告的结构化内容：

【学员信息】
- 姓名：{name}
- 年级：{grade}
- 性别：{gender}
- 身高：{height}cm
- 体重：{weight}kg

【测试成绩】
- 坐位体前屈：{sit_reach}cm
- 平板支撑：{plank}秒
- 俯卧撑：{pushup}个

【教练诊断】
- 体态问题：{posture_issue}
- 柔韧/活动度问题：{mobility_issue}
- 核心/力量问题：{strength_issue}
- 最核心问题：{main_issue}

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，80字以内",
  "standard": "结合儿童青少年体态健康要求，说明当前体态、柔韧、核心和肩背力量情况，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对体态气质、运动表现、疲劳代偿和后续纠正成本的影响，160字以内",
  "age_advice": "根据年级或年龄输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. standard要围绕体态健康和功能性表现，不套用中考分值。
2. analysis要解释胸椎活动度、肩胛稳定、核心控制、柔韧性、背部力量和姿势代偿之间的关系。
3. training_focus写本期5次训练重点，包含训练方向、观察指标和预期变化，不要写成每日训练动作清单。
4. score_impact要从体态固化、视觉气质、疲劳不适、运动效率和后续纠正成本角度制造重视感。
5. age_advice必须根据实际年级或年龄输出，不能固定写单一年龄窗口。
6. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
7. 不写具体价格、优惠、套餐金额。
8. 不写购买、续课、正式课包、锁定周期等明确销售文字。
9. 不出现AI、人工智能、大模型、系统生成等字眼。
PROMPT
    );
}

function ai_get_summer_camp_prompt_template(string $campType): string
{
    $jsonTemplates = ai_get_summer_camp_json_prompt_templates();
    if (isset($jsonTemplates[$campType])) {
        return $jsonTemplates[$campType];
    }

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

用一句话概括孩子的体能状况，要让家长清楚理解当前核心问题和训练重点。

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
- **影响**：每100米慢1.2秒，1000米可能多花25秒，影响成绩稳定性

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

**问题→影响→阶段目标的逻辑闭环**：
通过专业的身体缺失解读（肌肉群、解剖学），家长已意识到：
- 当前问题对肺活量、动作质量和专项表现的影响
- 长期代偿可能增加后续纠正成本
- 早期建立正确动作模式有助于后续训练稳定见效

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
6. **专业引导**：不提金额，通过问题、影响和阶段目标引导
7. **专业口吻**：不提AI字眼，真诚克制',

        'tineng' => '体能达标营报告模板由下方统一JSON结构覆盖。',

        'tiaosheng' => '跳绳达标营报告模板由下方统一JSON结构覆盖。',

        'lanqiu' => '篮球体能营报告模板由下方统一JSON结构覆盖。',

        'tuobei' => '驼背体态调整营报告模板由下方统一JSON结构覆盖。'
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
  "summary": "一句话总评，80字以内",
  "standard": "根据年级说明对应届数、体育总分和当前测试差距，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对中考体育得分稳定性、专项发挥和后续训练成本的影响，160字以内",
  "age_advice": "根据年级输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. 初一对应2028届，总分80分；初二对应2027届，总分60分；初三对应2026届，总分50分。
2. 根据实际年级写年龄段建议，不能固定写初一窗口期。
3. analysis要有专业深度，但必须让家长看懂；可解释心肺耐力、下肢爆发力、核心稳定、协调节奏、发力链条、动作代偿。
4. training_focus写本期5次训练重点，包含问题、训练方向、观察指标和预期变化，不要写成每日训练动作清单。
5. score_impact要从成绩稳定性和后续纠正成本角度制造重视感。
6. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
7. 不写具体价格、优惠、套餐金额。
8. 不写购买、续课、正式课包、锁定周期等明确销售文字。
9. 不出现AI、人工智能、大模型、系统生成等字眼。';

    $templates['tineng'] = '请根据以下测试数据，生成一份体能达标营评估报告的结构化内容：

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
- 肺活量/心肺问题：{vital_issue}
- 速度/协调问题：{speed_issue}
- 柔韧/基础力量问题：{flex_issue}
- 最核心问题：{main_issue}

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，80字以内",
  "standard": "结合5-12岁基础体能发展要求，说明当前心肺、速度、协调、柔韧的达标情况，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对体能达标稳定性、运动参与和日常学习状态的影响，160字以内",
  "age_advice": "根据年级或年龄输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. standard要围绕基础体能达标，不套用中考分值。
2. analysis要解释心肺耐力、速度启动、协调节奏、柔韧活动度、核心稳定和基础发育之间的关系。
3. training_focus写本期5次训练重点，包含训练方向、观察指标和预期变化，不要写成每日训练动作清单。
4. score_impact要从体能基础、课堂专注、运动安全和后续训练成本角度制造重视感。
5. age_advice必须根据实际年级或年龄输出，不能固定写单一年龄窗口。
6. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
7. 不写具体价格、优惠、套餐金额。
8. 不写购买、续课、正式课包、锁定周期等明确销售文字。
9. 不出现AI、人工智能、大模型、系统生成等字眼。';

    $templates['tiaosheng'] = '请根据以下测试数据，生成一份跳绳达标营评估报告的结构化内容：

【学员信息】
- 姓名：{name}
- 年级：{grade}
- 性别：{gender}
- 身高：{height}cm
- 体重：{weight}kg

【测试成绩】
- 1分钟跳绳：{jump_rope_1min}个
- 30秒跳绳：{jump_rope_30s}个
- 连续不掉绳：{consecutive}个

【教练诊断】
- 跳绳核心问题：{general_issue}
- 最核心问题：{main_issue}

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，80字以内",
  "standard": "结合跳绳达标要求，说明当前速度、连续性、节奏和稳定性水平，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对跳绳达标、体育成绩稳定性和后续纠错成本的影响，160字以内",
  "age_advice": "根据年级或年龄输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. standard要围绕跳绳达标，不套用中考总分。
2. analysis要解释摇绳发力、起跳高度、节奏控制、连续性、耐力和手脚协调之间的关系。
3. training_focus写本期5次训练重点，包含训练方向、观察指标和预期变化，不要写成每日训练动作清单。
4. score_impact要从动作固化、速度上限、掉绳风险和后续纠错成本角度制造重视感。
5. age_advice必须根据实际年级或年龄输出，不能固定写单一年龄窗口。
6. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
7. 不写具体价格、优惠、套餐金额。
8. 不写购买、续课、正式课包、锁定周期等明确销售文字。
9. 不出现AI、人工智能、大模型、系统生成等字眼。';

    $templates['lanqiu'] = '请根据以下测试数据，生成一份篮球体能营评估报告的结构化内容：

【学员信息】
- 姓名：{name}
- 年级：{grade}
- 性别：{gender}
- 身高：{height}cm
- 体重：{weight}kg

【测试成绩】
- 原地运球（30秒）：{dribble}次
- 定点投篮（10次）：{shoot}个
- 三步上篮（10次）：{layup}个

【教练诊断】
- 运球问题：{dribble_issue}
- 投篮问题：{shoot_issue}
- 上篮/脚步问题：{layup_issue}
- 最核心问题：{main_issue}

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，80字以内",
  "standard": "结合篮球基础能力要求，说明当前球感、运球、投篮、上篮和体能支撑情况，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对篮球技能进阶、运动自信和后续训练成本的影响，160字以内",
  "age_advice": "根据年级或年龄输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. standard要围绕篮球基础技能和专项体能，不套用中考分值。
2. analysis要解释球感、手眼协调、运球稳定性、投篮发力链条、上篮脚步、核心控制和爆发力之间的关系。
3. training_focus写本期5次训练重点，包含训练方向、观察指标和预期变化，不要写成每日训练动作清单。
4. score_impact要从技能迁移、对抗能力、参与自信和后续训练成本角度制造重视感。
5. age_advice必须根据实际年级或年龄输出，不能固定写单一年龄窗口。
6. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
7. 不写具体价格、优惠、套餐金额。
8. 不写购买、续课、正式课包、锁定周期等明确销售文字。
9. 不出现AI、人工智能、大模型、系统生成等字眼。';

    $templates['tuobei'] = '请根据以下测试数据，生成一份驼背体态调整营评估报告的结构化内容：

【学员信息】
- 姓名：{name}
- 年级：{grade}
- 性别：{gender}
- 身高：{height}cm
- 体重：{weight}kg

【测试成绩】
- 坐位体前屈：{sit_reach}cm
- 平板支撑：{plank}秒
- 俯卧撑：{pushup}个

【教练诊断】
- 体态问题：{posture_issue}
- 柔韧/活动度问题：{mobility_issue}
- 核心/力量问题：{strength_issue}
- 最核心问题：{main_issue}

只返回JSON对象，禁止输出JSON以外的任何文字。字段如下：
{
  "summary": "一句话总评，80字以内",
  "standard": "结合儿童青少年体态健康要求，说明当前体态、柔韧、核心和肩背力量情况，120字以内",
  "analysis": ["身体素质缺失解读1，120字以内", "身体素质缺失解读2，120字以内", "身体素质缺失解读3，120字以内", "身体素质缺失解读4，120字以内"],
  "training_focus": ["本期5次训练重点1，120字以内", "本期5次训练重点2，120字以内", "本期5次训练重点3，120字以内", "本期5次训练重点4，120字以内"],
  "score_impact": "说明当前问题对体态气质、运动表现、疲劳代偿和后续纠正成本的影响，160字以内",
  "age_advice": "根据年级或年龄输出对应阶段建议，160字以内",
  "parent_message": "给家长的重视与坚持建议，160字以内"
}

内容要求：
1. standard要围绕体态健康和功能性表现，不套用中考分值。
2. analysis要解释胸椎活动度、肩胛稳定、核心控制、柔韧性、背部力量和姿势代偿之间的关系。
3. training_focus写本期5次训练重点，包含训练方向、观察指标和预期变化，不要写成每日训练动作清单。
4. score_impact要从体态固化、视觉气质、疲劳不适、运动效率和后续纠正成本角度制造重视感。
5. age_advice必须根据实际年级或年龄输出，不能固定写单一年龄窗口。
6. parent_message通过专业判断让家长理解重视和坚持训练的必要性。
7. 不写具体价格、优惠、套餐金额。
8. 不写购买、续课、正式课包、锁定周期等明确销售文字。
9. 不出现AI、人工智能、大模型、系统生成等字眼。';

    return (string) ($templates[$campType] ?? '');
}
