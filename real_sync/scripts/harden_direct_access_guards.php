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
    'api/.env.local.php' => 'forbid',
    'api/admin/test.php' => 'forbid',
    'fix_layout.php' => 'forbid',
    'homepage_design.php' => 'forbid',
    'render_internal_pages.php' => 'forbid',
    'subpages.php' => 'forbid',
];

$siteCurrentApi = $root . '/site_current/api';
if (is_dir($siteCurrentApi)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($siteCurrentApi, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (str_ends_with($path, '.php')) {
            $targets[str_replace($root . '/', '', $path)] = 'forbid';
        }
    }
}

$guard = <<<'PHP'
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

PHP;

$changed = [];
$skipped = [];
foreach ($targets as $relative => $mode) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $skipped[] = $relative . ' missing';
        continue;
    }
    $content = file_get_contents($path);
    if (!is_string($content)) {
        $skipped[] = $relative . ' unreadable';
        continue;
    }
    if (strpos($content, "realpath((string)\$_SERVER['SCRIPT_FILENAME']) === __FILE__") !== false) {
        $skipped[] = $relative . ' already_guarded';
        continue;
    }
    if (!str_starts_with($content, '<?php')) {
        $skipped[] = $relative . ' not_php_open';
        continue;
    }
    $updated = preg_replace('/^<\?php\s*/', "<?php\n" . $guard, $content, 1);
    if (!is_string($updated) || $updated === $content) {
        $skipped[] = $relative . ' unchanged';
        continue;
    }
    file_put_contents($path, $updated, LOCK_EX);
    $changed[] = $relative;
}

echo json_encode(['changed' => $changed, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
