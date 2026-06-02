<?php
declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'Forbidden'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_load_settings(): array
{
    $settings = array(
        'deepseek_api_key' => trim((string) (getenv('DEEPSEEK_API_KEY') ?: getenv('DEEPOSEEK_API_KEY'))),
        'zhipu_api_key' => trim((string) getenv('ZHIPU_API_KEY')),
    );

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
    $settings = ai_load_settings();
    return trim((string) ($settings[$name . '_api_key'] ?? '')) !== '';
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
    $settings = ai_load_settings();
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

function ai_zhipu_vision(string $imageDataUrl, string $prompt): array
{
    $settings = ai_load_settings();
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
    if (preg_match('/\{[\s\S]*\}/', $content, $matches) !== 1) {
        throw new RuntimeException('未从识别结果中提取到有效 JSON');
    }

    $decoded = json_decode($matches[0], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('识别结果 JSON 解析失败');
    }

    return $decoded;
}
