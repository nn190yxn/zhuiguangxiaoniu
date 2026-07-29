<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillEmployeeApiService.php';
$context = drillV2Bootstrap(['GET']);
try { drillV2Success((new DrillEmployeeApiService(getDB()))->assignments((int) $context['staff_id'], isset($_GET['assignment_id']) ? (int) $_GET['assignment_id'] : null)); } catch (DomainException $error) { drillV2Error(404, $error->getMessage(), [], 404); } catch (Throwable $error) { error_log('Drill v2 assignments failed: ' . $error->getMessage()); drillV2Error(500, '必修任务加载失败', [], 500); }
