<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 3) . '/drill/v2/services/DrillCutoverService.php';

try {
    [$context] = drillAdminV2Bootstrap('drill.migration_manage', ['POST']);
    $input = drillV2Input();
    $service = new DrillCutoverService(getDB());
    $result = drillV2RunIdempotent(getDB(), $context, 'drill.cutover.' . (string) ($input['action'] ?? 'unknown'), $input, function () use ($service, $input, $context): array {
        return match ((string) ($input['action'] ?? '')) {
            'preflight' => $service->preflight((string) ($input['surface'] ?? ''), (string) ($input['batch_key'] ?? ''), (int) $context['staff_id'], (array) ($input['target_scope'] ?? [])),
            'rollback_plan' => $service->rollbackPlan((int) ($input['batch_id'] ?? 0), (int) $context['staff_id']),
            default => throw new InvalidArgumentException('不支持的演练切换操作。'),
        };
    });
    drillV2Success($result, 'success', 202);
} catch (DrillIdempotencyException $error) {
    drillV2Error($error->statusCode(), $error->getMessage(), [], $error->statusCode());
} catch (DomainException|InvalidArgumentException $error) {
    drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) {
    error_log('Drill cutover failed: ' . $error->getMessage());
    drillV2Error(500, '演练切换预检失败', [], 500);
}
