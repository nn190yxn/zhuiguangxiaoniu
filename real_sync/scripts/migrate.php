<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../database/MigrationRunner.php';

$command = $argv[1] ?? 'status';
$allowedCommands = ['apply', 'status', 'verify', 'rollback-plan'];
if (!in_array($command, $allowedCommands, true)) {
    fwrite(STDERR, "Usage: php scripts/migrate.php [apply|status|verify|rollback-plan] [--dry-run]\n");
    exit(2);
}

try {
    $runner = new MigrationRunner(
        getDB(),
        __DIR__ . '/../database/migrations',
        require __DIR__ . '/../database/migration_manifest.php'
    );
    if ($command === 'apply') {
        $result = $runner->apply(in_array('--dry-run', $argv, true));
    } elseif ($command === 'verify') {
        $result = $runner->verify();
    } elseif ($command === 'rollback-plan') {
        $result = $runner->rollbackPlan();
    } else {
        $result = ['migrations' => $runner->status()];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    if ($command === 'verify' && empty($result['ok'])) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
