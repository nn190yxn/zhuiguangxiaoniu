<?php
declare(strict_types=1);

require '/www/wwwroot/122.51.223.46/api/ai-runtime.php';

$file = $argv[1] ?? '';
if ($file === '') {
    fwrite(STDERR, "missing file\n");
    exit(1);
}

$imageUrl = 'http://122.51.223.46/wp-content/uploads/ocr-cache/' . basename($file);
$prompt = '请从这张儿童体测报告中提取以下数据，并以JSON格式返回，只返回JSON不要其他文字。请特别注意逐项提取测试值和图片上的原始评级文字，不要遗漏身体形态和体能项目的 rating 字段。';

$settings = ai_runtime_load_settings();
$apiKey = trim((string) ($settings['doubao_api_key'] ?? ''));
$model = trim((string) ($settings['doubao_model'] ?? 'doubao-seed-2-0-lite-260428'));

$resp = ai_post_json(
    'https://ark.cn-beijing.volces.com/api/v3/responses',
    array('Authorization' => 'Bearer ' . $apiKey),
    array(
        'model' => $model,
        'input' => array(array('role' => 'user', 'content' => array(
            array('type' => 'input_image', 'image_url' => $imageUrl),
            array('type' => 'input_text', 'text' => $prompt . "\n\n只返回一个 JSON 对象，不要输出解释、Markdown 或代码块。"),
        ))),
    ),
    60
);

$text = '';
foreach (($resp['body']['output'] ?? array()) as $outputItem) {
    if (($outputItem['type'] ?? '') !== 'message') continue;
    $text = ai_extract_response_text($outputItem['content'] ?? array());
    if ($text !== '') break;
}

$decoded = ai_extract_json_object($text, 'debug');
$normalized = ai_normalize_ocr_result($decoded);
echo json_encode(array('decoded' => $decoded, 'normalized' => $normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
