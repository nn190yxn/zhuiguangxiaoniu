<?php
declare(strict_types=1);

require '/www/wwwroot/122.51.223.46/api/config.php';
require '/www/wwwroot/122.51.223.46/api/ai-runtime.php';

$dir = '/www/wwwroot/122.51.223.46/wp-content/uploads/ocr-cache';
$files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
    return $name !== '.' && $name !== '..' && $name !== 'index.html' && str_ends_with($name, '.jpg');
}));

usort($files, static function ($a, $b) use ($dir) {
    return filemtime($dir . '/' . $b) <=> filemtime($dir . '/' . $a);
});

$files = array_slice($files, 0, 8);

foreach ($files as $name) {
    $path = $dir . '/' . $name;
    $dataUrl = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($path));
    try {
        $ocrText = ai_baidu_ocr_text($dataUrl);
        $firstLines = implode(' | ', array_slice(preg_split('/\r?\n/', $ocrText) ?: [], 0, 8));
        echo "FILE: {$name}\n";
        echo "TEXT: {$firstLines}\n\n";
    } catch (Throwable $e) {
        echo "FILE: {$name}\n";
        echo 'ERROR: ' . $e->getMessage() . "\n\n";
    }
}
