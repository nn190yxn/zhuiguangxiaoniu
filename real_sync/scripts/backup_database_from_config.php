<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$backupDir = $argv[1] ?? '';
if ($backupDir === '' || !is_dir($backupDir)) {
    fwrite(STDERR, "Backup directory is required\n");
    exit(1);
}

require_once __DIR__ . '/../api/config.php';

$constants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
foreach ($constants as $constant) {
    if (!defined($constant)) {
        fwrite(STDERR, "Missing {$constant}\n");
        exit(1);
    }
}

$target = rtrim($backupDir, '/') . '/database-' . DB_NAME . '-notablespaces.sql.gz';
$cmd = sprintf(
    'MYSQL_PWD=%s mysqldump --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4 -h %s -u %s %s | gzip > %s',
    escapeshellarg((string) DB_PASSWORD),
    escapeshellarg((string) DB_HOST),
    escapeshellarg((string) DB_USER),
    escapeshellarg((string) DB_NAME),
    escapeshellarg($target)
);

exec($cmd, $output, $code);
if ($code !== 0 || !is_file($target) || filesize($target) <= 0) {
    fwrite(STDERR, "Database backup failed\n");
    exit(1);
}

echo $target . PHP_EOL;
