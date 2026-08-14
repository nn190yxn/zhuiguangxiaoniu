<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadDailySettlementService.php';
require_once __DIR__ . '/services/WorkloadConversionResultService.php';
require_once __DIR__ . '/services/WorkloadPenaltyService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit('CLI only');
}

$businessDate = $argv[1] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$pdo = workloadDb();
$pdo->beginTransaction();
try {
    $reportIds = $pdo->prepare("SELECT id FROM workload_daily_reports WHERE report_date = ? AND submit_status = 'submitted' FOR UPDATE");
    $reportIds->execute([$businessDate]);
    $conversionService = new WorkloadConversionResultService($pdo);
    foreach ($reportIds->fetchAll(PDO::FETCH_COLUMN) ?: [] as $reportId) {
        $conversionService->refreshReport((int) $reportId);
    }
    $settlements = (new WorkloadDailySettlementService($pdo))->refreshDate($businessDate);
    $pdo->commit();
    fwrite(STDOUT, json_encode([
        'business_date' => $businessDate,
        'settlement_count' => count($settlements),
        'penalty_count' => count(array_filter($settlements, static fn(array $settlement): bool => $settlement['penalty'] !== null)),
        'settlements' => $settlements,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
