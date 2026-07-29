<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/drill/v2/services/DrillGovernanceService.php';

$dryRun = !in_array('--apply', $argv, true);
$service = new DrillGovernanceService(getDB());
$result = $service->expireAudio(0, $dryRun);
$result['monitor'] = $service->monitor();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
