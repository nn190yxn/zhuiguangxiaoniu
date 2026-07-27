<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'page' => $root . '/mini-program/pages/workload/index.js',
    'app' => $root . '/mini-program/app.js',
    'api' => $root . '/mini-program/utils/api.js',
];

foreach ($files as $name => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing mini-program {$name} file\n");
        exit(1);
    }
}

$pageCode = (string)file_get_contents($files['page']);
$appCode = (string)file_get_contents($files['app']);
$apiCode = (string)file_get_contents($files['api']);
$code = $pageCode . "\n" . $appCode . "\n" . $apiCode;
$failures = [];

if (strpos($pageCode, 'app.uploadFile') === false) {
    $failures[] = 'workload page does not use app.uploadFile';
}
if (strpos($appCode, 'api.uploadFile') === false) {
    $failures[] = 'app does not delegate uploadFile to api utility';
}
if (strpos($apiCode, 'wx.uploadFile') === false) {
    $failures[] = 'api utility does not use wx.uploadFile';
}
if (strpos($code, "name: 'image_file'") === false && strpos($code, 'name: "image_file"') === false) {
    $failures[] = 'missing multipart field image_file';
}
if (strpos($code, 'image_data') !== false) {
    $failures[] = 'legacy image_data upload is present';
}
if (strpos($code, 'readFileAsDataUrl') !== false) {
    $failures[] = 'legacy base64 reader is present';
}

if ($failures) {
    fwrite(STDERR, "mini-program workload upload check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "mini-program workload upload check passed\n";
