<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$root = realpath(__DIR__ . '/..');
$reportPath = $argv[1] ?? '';
$archiveRoot = $argv[2] ?? '';

if (!$root) {
    fwrite(STDERR, "Root not found\n");
    exit(1);
}
if ($reportPath === '' || !is_file($reportPath)) {
    fwrite(STDERR, "Candidate report is required\n");
    exit(1);
}
if ($archiveRoot === '') {
    fwrite(STDERR, "Archive root is required\n");
    exit(1);
}

$report = json_decode((string) file_get_contents($reportPath), true);
if (!is_array($report) || !isset($report['candidates']) || !is_array($report['candidates'])) {
    fwrite(STDERR, "Invalid candidate report\n");
    exit(1);
}

if (!is_dir($archiveRoot) && !mkdir($archiveRoot, 0755, true)) {
    fwrite(STDERR, "Failed to create archive root\n");
    exit(1);
}

$moved = [];
$skipped = [];
foreach ($report['candidates'] as $relative) {
    $relative = ltrim((string) $relative, '/');
    if ($relative === '' || str_contains($relative, '..')) {
        $skipped[] = ['path' => $relative, 'reason' => 'invalid_path'];
        continue;
    }

    $source = $root . '/' . $relative;
    if (!is_file($source)) {
        $skipped[] = ['path' => $relative, 'reason' => 'missing'];
        continue;
    }

    $target = rtrim($archiveRoot, '/') . '/' . $relative;
    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        $skipped[] = ['path' => $relative, 'reason' => 'mkdir_failed'];
        continue;
    }
    if (is_file($target)) {
        $target .= '.archived-' . date('His');
    }
    if (!rename($source, $target)) {
        $skipped[] = ['path' => $relative, 'reason' => 'rename_failed'];
        continue;
    }
    $moved[] = ['from' => $relative, 'to' => str_replace(rtrim($archiveRoot, '/') . '/', '', $target)];
}

$result = [
    'root' => $root,
    'archive_root' => $archiveRoot,
    'moved_count' => count($moved),
    'skipped_count' => count($skipped),
    'moved' => $moved,
    'skipped' => $skipped,
];

$manifest = rtrim($archiveRoot, '/') . '/archive-manifest.json';
file_put_contents($manifest, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
