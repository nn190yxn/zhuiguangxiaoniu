<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/drill/v2/services/DrillNewSignContentImporter.php';

$actorStaffId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($actorStaffId === false) {
    fwrite(STDERR, "Usage: php scripts/import_drill_new_sign_content.php <actor_staff_id>\n");
    exit(2);
}

try {
    $result = (new DrillNewSignContentImporter(getDB()))->import((int) $actorStaffId);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
