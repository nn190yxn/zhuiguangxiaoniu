<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../database/MigrationReplayVerifier.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['dry-run', 'verify', 'rollback-plan'], true)) {
    fwrite(STDERR, "Usage: php scripts/migration-replay.php [dry-run|verify|rollback-plan] [--stdin | --since=DATETIME [--until=DATETIME] [--limit=N]]\n");
    exit(2);
}

try {
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if ($argument === '--stdin') {
            $options['stdin'] = true;
            continue;
        }
        if (preg_match('/^--(since|until|limit)=(.+)$/', $argument, $matches)) {
            $options[$matches[1]] = $matches[2];
            continue;
        }
        throw new InvalidArgumentException('Unknown migration replay option: ' . $argument);
    }

    if ($options['stdin'] ?? false) {
        $input = stream_get_contents(STDIN);
        $evidence = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($evidence)) {
            throw new InvalidArgumentException('Replay evidence must be a JSON object');
        }
    } else {
        if (!isset($options['since'])) {
            throw new InvalidArgumentException('Database evidence collection requires --since');
        }
        $since = new DateTimeImmutable((string)$options['since']);
        $until = isset($options['until']) ? new DateTimeImmutable((string)$options['until']) : new DateTimeImmutable('now');
        $limit = isset($options['limit']) ? filter_var($options['limit'], FILTER_VALIDATE_INT) : 10000;
        if ($limit === false || $limit < 1 || $limit > 10000) {
            throw new InvalidArgumentException('Evidence limit must be between 1 and 10000');
        }
        require_once __DIR__ . '/../api/config.php';
        $evidence = (new PdoMigrationReplayEvidenceSource(getDB()))->collect($since, $until, $limit);
    }

    $verifier = new MigrationReplayVerifier();
    $result = match ($mode) {
        'dry-run' => $verifier->dryRun($evidence),
        'verify' => $verifier->verify($evidence),
        'rollback-plan' => $verifier->rollbackPlan($evidence),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (!$result['ok']) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
