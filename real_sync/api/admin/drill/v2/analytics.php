<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 3) . '/drill/v2/services/DrillAnalyticsService.php';

try {
    [$context, , $staff] = drillAdminV2Bootstrap('drill.analytics_all', ['GET']);
    drillV2Success((new DrillAnalyticsService(getDB()))->summary($_GET, $context, $staff));
} catch (DomainException|InvalidArgumentException $error) {
    drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) {
    error_log('Drill analytics failed: ' . $error->getMessage());
    drillV2Error(500, '演练统计处理失败', [], 500);
}
