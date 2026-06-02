<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$root = realpath(__DIR__ . '/..');
if (!$root) {
    fwrite(STDERR, "Root not found\n");
    exit(1);
}

$allowedPrefixes = [
    'admin/',
    'api/',
    'mobile/',
    'training/',
    '新员工学习/',
    '制度标准/',
    '知识库/',
    '表格中心/',
];

$candidates = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = $file->getPathname();
    $relative = str_replace($root . '/', '', $path);
    if (str_starts_with($relative, '.git/') || str_starts_with($relative, '_archive/') || str_starts_with($relative, 'wp-')) {
        continue;
    }
    $base = basename($path);
    if (stripos($base, '.bak') === false && stripos($base, 'bak-') === false) {
        continue;
    }
    $inAllowedPrefix = false;
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            $inAllowedPrefix = true;
            break;
        }
    }
    if (!$inAllowedPrefix && substr_count($relative, '/') > 0) {
        continue;
    }
    $candidates[] = $relative;
}

sort($candidates);
echo json_encode(['count' => count($candidates), 'candidates' => $candidates], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
