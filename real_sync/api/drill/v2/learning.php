<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillLearningService.php';
require_once __DIR__ . '/services/DrillEmployeeApiService.php';
$context = drillV2Bootstrap(['GET', 'POST']);
$input = drillV2Input();
if ($input === []) { $input = $_GET; }
try {
    $service = new DrillEmployeeApiService(getDB());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = getDB();
        $result = drillV2RunIdempotent($pdo, $context, 'drill.learning.record_progress', $input, fn(): array => (new DrillLearningService($pdo))->recordProgress((int) $context['staff_id'], (int) ($input['domain_id'] ?? 0), (int) ($input['learning_resource_version_id'] ?? 0), (float) ($input['progress_percent'] ?? 0), isset($input['recommendation_id']) ? (int) $input['recommendation_id'] : null));
        drillV2Success($result, 'success', 202);
    }
    drillV2Success($service->learning((int) $context['staff_id'], $input));
} catch (DrillIdempotencyException $error) { drillV2Error($error->statusCode(), $error->getMessage(), [], $error->statusCode()); } catch (DomainException|InvalidArgumentException $error) { drillV2Error(400, $error->getMessage(), [], 400); } catch (Throwable $error) { error_log('Drill v2 learning failed: ' . $error->getMessage()); drillV2Error(500, '学习数据处理失败', [], 500); }
