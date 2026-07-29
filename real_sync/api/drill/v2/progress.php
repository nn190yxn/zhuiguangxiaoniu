<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillEmployeeApiService.php';
$context = drillV2Bootstrap(['GET']);
try { drillV2Success((new DrillEmployeeApiService(getDB()))->progress((int) $context['staff_id'], isset($_GET['domain_id']) ? (int) $_GET['domain_id'] : null)); } catch (Throwable $error) { error_log('Drill v2 progress failed: ' . $error->getMessage()); drillV2Error(500, '成长进度加载失败', [], 500); }
