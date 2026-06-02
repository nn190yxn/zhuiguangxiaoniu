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

$targets = [
    $root . '/api',
    $root . '/scripts',
    $root,
];

$authPatterns = [
    'getCurrentUserId',
    'getJwtCurrentUser',
    'appRequireStaffContext',
    'appRequireViewStore',
    'appRequireOperateStaff',
    'adminRequire',
    'requireAdmin',
    'checkAdmin',
    "PHP_SAPI !== 'cli'",
    'PHP_SAPI !=',
    'php_sapi_name()',
    "SCRIPT_FILENAME",
    'realpath((string)',
    "exit('Forbidden')",
];

$riskPatterns = [
    'UPDATE ',
    'INSERT ',
    'DELETE ',
    'DROP ',
    'ALTER ',
    'TRUNCATE ',
    'reset',
    'password',
    'import',
    'fix',
    'repair',
    'create',
    'migrate',
    'file_put_contents',
    'unlink',
    'rename(',
    'chmod',
];

$files = [];
foreach ($targets as $target) {
    if (is_file($target) && str_ends_with($target, '.php')) {
        $files[$target] = true;
        continue;
    }
    if (!is_dir($target)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php')) {
            continue;
        }
        if (strpos($path, '/wp-') !== false || strpos($path, '/wp-content/') !== false || strpos($path, '/wp-includes/') !== false || strpos($path, '/wp-admin/') !== false) {
            continue;
        }
        $files[$path] = true;
    }
}

$findings = [];
foreach (array_keys($files) as $path) {
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        continue;
    }
    $hasAuth = false;
    foreach ($authPatterns as $pattern) {
        if (stripos($content, $pattern) !== false) {
            $hasAuth = true;
            break;
        }
    }
    $hits = [];
    foreach ($riskPatterns as $pattern) {
        if (stripos($content, $pattern) !== false) {
            $hits[] = $pattern;
        }
    }
    if (!$hasAuth && $hits) {
        $findings[] = [
            'path' => str_replace($root . '/', '', $path),
            'risk_hits' => array_values(array_unique($hits)),
            'size' => filesize($path),
        ];
    }
}

usort($findings, fn($a, $b) => strcmp($a['path'], $b['path']));

echo json_encode([
    'root' => $root,
    'scanned_files' => count($files),
    'high_risk_no_auth_count' => count($findings),
    'findings' => $findings,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
