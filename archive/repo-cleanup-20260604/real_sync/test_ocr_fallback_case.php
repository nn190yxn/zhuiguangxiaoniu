<?php
declare(strict_types=1);

require '/www/wwwroot/122.51.223.46/api/config.php';
require '/www/wwwroot/122.51.223.46/api/ai-runtime.php';

$file = $argv[1] ?? '';
if ($file === '') {
    fwrite(STDERR, "missing file\n");
    exit(1);
}

$imagePath = '/www/wwwroot/122.51.223.46/wp-content/uploads/ocr-cache/' . basename($file);
$imageUrl = 'http://122.51.223.46/wp-content/uploads/ocr-cache/' . basename($file);

if (!is_file($imagePath)) {
    fwrite(STDERR, "not found\n");
    exit(2);
}

$ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
$mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
$dataUrl = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($imagePath));

$prompt = '请从这张儿童体测报告中提取以下数据，并以JSON格式返回，只返回JSON不要其他文字。请特别注意逐项提取测试值和图片上的原始评级文字，不要遗漏身体形态和体能项目的 rating 字段。';

$result = ai_ocr_fitness_image($dataUrl, $imageUrl, $prompt);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
