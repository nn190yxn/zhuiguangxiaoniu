<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillEmployeeApiService.php';
$context = drillV2Bootstrap(['GET']);
try { drillV2Success((new DrillEmployeeApiService(getDB()))->attemptStatus((int) $context['staff_id'], (int) ($_GET['attempt_id'] ?? 0))); } catch (DomainException $error) { drillV2Error(404, $error->getMessage(), [], 404); } catch (Throwable $error) { error_log('Drill v2 attempt status failed: ' . $error->getMessage()); drillV2Error(500, '演练状态加载失败', [], 500); }
