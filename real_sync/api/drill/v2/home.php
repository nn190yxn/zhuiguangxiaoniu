<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillEmployeeApiService.php';
$context = drillV2Bootstrap(['GET']);
try { drillV2Success((new DrillEmployeeApiService(getDB()))->home((int) $context['staff_id'])); } catch (Throwable $error) { error_log('Drill v2 home failed: ' . $error->getMessage()); drillV2Error(500, '演练首页加载失败', [], 500); }
