<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 3) . '/drill/v2/services/DrillGovernanceService.php';

try {
    [$context] = drillAdminV2Bootstrap('drill.analytics_all');
    $input = drillV2Input();
    $service = new DrillGovernanceService(getDB());
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        drillV2Success(['monitor' => $service->monitor(), 'retry_queue' => $service->retryQueue((int) ($_GET['limit'] ?? 100))]);
    }
    $result = drillV2RunIdempotent(getDB(), $context, 'drill.governance.' . (string) ($input['action'] ?? 'unknown'), $input, function () use ($service, $input, $context): array {
        return match ((string) ($input['action'] ?? '')) {
            'expire_audio' => $service->expireAudio((int) $context['staff_id'], (bool) ($input['dry_run'] ?? true)),
            default => throw new InvalidArgumentException('不支持的演练治理操作。'),
        };
    });
    drillV2Success($result, 'success', 202);
} catch (DrillIdempotencyException $error) {
    drillV2Error($error->statusCode(), $error->getMessage(), [], $error->statusCode());
} catch (DomainException|InvalidArgumentException $error) {
    drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) {
    error_log('Drill governance failed: ' . $error->getMessage());
    drillV2Error(500, '演练治理处理失败', [], 500);
}
