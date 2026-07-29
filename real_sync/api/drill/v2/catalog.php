<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillEmployeeApiService.php';
$context = drillV2Bootstrap(['GET']);
try { drillV2Success((new DrillEmployeeApiService(getDB()))->catalog(isset($_GET['domain_id']) ? (int) $_GET['domain_id'] : null, isset($_GET['stage_id']) ? (int) $_GET['stage_id'] : null, isset($_GET['difficulty']) ? trim((string) $_GET['difficulty']) : null)); } catch (Throwable $error) { error_log('Drill v2 catalog failed: ' . $error->getMessage()); drillV2Error(500, '训练目录加载失败', [], 500); }
