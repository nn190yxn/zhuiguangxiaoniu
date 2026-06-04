<?php
declare(strict_types=1);

require '/www/wwwroot/122.51.223.46/api/config.php';
require '/www/wwwroot/122.51.223.46/api/ai-runtime.php';

$imagePath = '/www/wwwroot/122.51.223.46/wp-content/uploads/ocr-cache/ocr-20260508-134637-a416f38a8fe6.jpg';
$imageDataUrl = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($imagePath));
$imageUrl = 'http://122.51.223.46/wp-content/uploads/ocr-cache/ocr-20260508-134637-a416f38a8fe6.jpg';
$prompt = '请提取体测数据并返回 JSON';

try {
    $result = ai_ocr_fitness_image($imageDataUrl, $imageUrl, $prompt);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    echo 'ERROR: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
