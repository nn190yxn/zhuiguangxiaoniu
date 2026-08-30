<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/../services/DrillQaService.php';

$context = drillV2Bootstrap(['GET']);
$pdo = getDB();
try {
    drillV2Success((new DrillQaService($pdo, DrillAiAdapter::fromProjectRuntime()))->history((int) $context['staff_id'], (int) ($_GET['limit'] ?? 20)));
} catch (Throwable $error) {
    error_log('Drill v2 Q&A history failed: ' . $error->getMessage());
    drillV2Error(500, '历史记录加载失败', [], 500);
}
