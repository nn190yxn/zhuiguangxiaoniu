<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/../services/DrillQaService.php';

$context = drillV2Bootstrap(['GET']);
$input = drillV2Input();
$pdo = getDB();
try {
    $sessionId = (int) ($input['session_id'] ?? $_GET['session_id'] ?? 0);
    if ($sessionId <= 0) {
        drillV2Error(400, '缺少有效的 session_id');
    }
    drillV2Success((new DrillQaService($pdo, DrillAiAdapter::fromProjectRuntime()))->detail((int) $context['staff_id'], $sessionId));
} catch (DomainException|InvalidArgumentException $error) {
    drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) {
    error_log('Drill v2 Q&A detail failed: ' . $error->getMessage());
    drillV2Error(500, '明细加载失败', [], 500);
}
