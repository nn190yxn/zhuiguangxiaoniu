<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/mini-program/pages/workload/index.js';

if (!is_file($file)) {
    fwrite(STDERR, "missing workload mini-program file\n");
    exit(1);
}

$code = (string)file_get_contents($file);
$failures = [];

if (strpos($code, 'wx.uploadFile') === false) {
    $failures[] = 'missing wx.uploadFile';
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
