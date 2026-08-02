<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/drill/v2/services/DrillGovernanceService.php';
require_once dirname(__DIR__) . '/api/kernel/bootstrap.php';
require_once dirname(__DIR__) . '/api/platform/JobQueue.php';

$dryRun = !in_array('--apply', $argv, true);
$db = getDB();
if ($dryRun) {
    $service = new DrillGovernanceService($db);
    $result = $service->expireAudio(0, true);
    $result['monitor'] = $service->monitor();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

platformRequireMigrationReadiness($db, ['202607310010', '202607310012']);
$slot = gmdate('YmdH');
$db->beginTransaction();
try {
    $queue = new PlatformJobQueueService(new PlatformPdoJobQueueStore($db));
    $job = $queue->enqueue(
        'drill.governance.expire_audio',
        'drill_governance',
        'audio_expiry',
        hash('sha256', 'drill.governance.expire_audio:' . $slot),
        ['actor_staff_id' => 0, 'schedule_slot' => $slot],
        5,
        3
    );
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
echo json_encode(['queued' => true, 'job_id' => (int)$job['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
