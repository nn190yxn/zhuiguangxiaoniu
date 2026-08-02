<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$command = $argv[1] ?? 'status';
$allowedCommands = ['apply', 'status', 'compatibility', 'readiness', 'verify', 'rollback-plan'];
if (!in_array($command, $allowedCommands, true)) {
    fwrite(STDERR, "Usage: php scripts/migrate.php [apply|status|compatibility|readiness|verify|rollback-plan] [--dry-run]\n");
    exit(2);
}

require_once __DIR__ . '/../database/ExpandMigrateContractValidator.php';
if ($command !== 'compatibility') {
    require_once __DIR__ . '/../api/config.php';
    require_once __DIR__ . '/../database/MigrationRunner.php';
    require_once __DIR__ . '/../database/MigrationReadiness.php';
}

try {
    $catalog = require __DIR__ . '/../database/migration_catalog.php';
    $compatibility = new ExpandMigrateContractValidator($catalog, __DIR__ . '/../database/migrations');
    if ($command === 'compatibility') {
        $result = $compatibility->validate();
    } else {
        $db = getDB();
        $runner = new MigrationRunner($db, __DIR__ . '/../database/migrations', $catalog);
    }
    if ($command === 'apply') {
        $contractResult = $compatibility->validate();
        if (!$contractResult['compatible']) {
            throw new RuntimeException('Migration compatibility validation failed');
        }
        $result = $runner->apply(in_array('--dry-run', $argv, true));
    } elseif ($command === 'readiness') {
        $contractResult = $compatibility->validate();
        $result = ['ready' => $contractResult['compatible'], 'compatibility' => $contractResult, 'issues' => $contractResult['issues']];
        if ($result['ready']) {
            $readiness = new MigrationReadiness(new PdoMigrationReadinessDatabase($db), $catalog);
            $structureResult = $readiness->check();
            $result['ready'] = $structureResult['ready'];
            $result['checked_versions'] = $structureResult['checked_versions'];
            $result['issues'] = array_merge($result['issues'], $structureResult['issues']);
            if ($result['ready']) {
                $dataResult = $readiness->verifyData();
                $result['ready'] = $dataResult['ready'];
                $result['issues'] = array_merge($result['issues'], $dataResult['issues']);
            }
        }
    } elseif ($command === 'compatibility') {
        // The static compatibility gate intentionally runs without a database connection.
    } elseif ($command === 'verify') {
        $result = $runner->verify();
    } elseif ($command === 'rollback-plan') {
        $result = $runner->rollbackPlan();
    } else {
        $result = ['migrations' => $runner->status()];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    if (($command === 'verify' && empty($result['ok']))
        || ($command === 'compatibility' && empty($result['compatible']))
        || ($command === 'readiness' && empty($result['ready']))) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
