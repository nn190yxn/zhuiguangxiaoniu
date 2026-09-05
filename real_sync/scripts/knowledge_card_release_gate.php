#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 1417 张知识卡统一上线前的只读报告。
 * 仓库包、分类审核报告和目标环境证据分别读取，不连接数据库或改变发布状态。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require_once __DIR__ . '/../api/knowledge/KnowledgeTaxonomy.php';

function readJsonObject(string $path, string $label, array &$inputFailures): ?array
{
    if ($path === '-' && $label === 'database evidence') {
        $contents = stream_get_contents(STDIN);
        if (trim((string)$contents) === '') {
            $inputFailures[] = 'database_evidence_missing';
            return null;
        }
    } elseif ($path !== '' && is_file($path)) {
        $contents = file_get_contents($path);
    } else {
        $inputFailures[] = str_replace(' ', '_', $label) . '_missing';
        return null;
    }

    $decoded = json_decode((string)$contents, true);
    if (!is_array($decoded)) {
        $inputFailures[] = str_replace(' ', '_', $label) . '_invalid';
        return null;
    }
    return $decoded;
}

function integerValue(array $source, string $key): ?int
{
    return isset($source[$key]) && is_int($source[$key]) ? $source[$key] : null;
}

function addCheck(array &$checks, string $name, bool $passed, array $detail, array $reasons = []): void
{
    $checks[] = [
        'name' => $name,
        'passed' => $passed,
        'detail' => $detail,
        'reasons' => $passed ? [] : array_values(array_unique($reasons)),
    ];
}

$packagePath = $argv[1] ?? '';
$expectedCount = isset($argv[2]) ? (int)$argv[2] : 1417;
$defaultReviewPath = preg_replace('/\.isolated-package\.json$/', '.taxonomy-review-report.json', $packagePath) ?: '';
$reviewPath = $argv[3] ?? $defaultReviewPath;
$databaseEvidencePath = $argv[4] ?? '';

if ($packagePath === '' || $expectedCount < 1 || !is_file($packagePath)) {
    fwrite(STDERR, "用法: knowledge_card_release_gate.php <isolated-package.json> [expected-count] [taxonomy-review-report.json] [release-evidence.json|-]\n");
    exit(2);
}

$inputFailures = [];
$package = readJsonObject($packagePath, 'package', $inputFailures);
if ($package === null) {
    fwrite(STDERR, "package is not valid JSON\n");
    exit(2);
}
$reviewReport = readJsonObject($reviewPath, 'review report', $inputFailures);
$databaseEvidence = readJsonObject($databaseEvidencePath, 'database evidence', $inputFailures);
$reviewInputFailures = array_values(array_filter(
    $inputFailures,
    fn(string $reason): bool => strpos($reason, 'review_report_') === 0
));
$databaseInputFailures = array_values(array_filter(
    $inputFailures,
    fn(string $reason): bool => strpos($reason, 'database_evidence_') === 0
));
$databaseState = is_array($databaseEvidence['knowledge_database'] ?? null)
    ? $databaseEvidence['knowledge_database']
    : $databaseEvidence;
$databaseState = is_array($databaseState) ? $databaseState : [];

$records = is_array($package['records'] ?? null) ? $package['records'] : [];
$reviewSummary = is_array($reviewReport['summary'] ?? null) ? $reviewReport['summary'] : [];
$taxonomyVersion = KnowledgeTaxonomy::mappingVersion();
$packageFailures = [];
$mappingFailureCount = 0;
$statusCounts = [];
$categoryCounts = ['professional' => 0, 'sales' => 0, 'unmapped' => 0];
$requiredMetadata = ['applicable_ages', 'setting', 'subjects', 'source_articles', 'target_roles', 'target_stages', 'difficulty'];

foreach ($records as $record) {
    $status = (string)($record['publication_status'] ?? 'missing');
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    $reasons = [];
    foreach (['item_code', 'title', 'content', 'content_type', 'risk_level', 'source_path', 'source_sha256', 'normalized_hash'] as $field) {
        if (!is_string($record[$field] ?? null) || trim($record[$field]) === '') {
            $reasons[] = 'missing_' . $field;
        }
    }
    $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
    foreach ($requiredMetadata as $field) {
        if (!array_key_exists($field, $metadata) || $metadata[$field] === null || $metadata[$field] === '' || $metadata[$field] === []) {
            $reasons[] = 'missing_metadata_' . $field;
        }
    }
    if (!array_key_exists('related_content', $metadata) || !is_array($metadata['related_content'])) {
        $reasons[] = 'missing_metadata_related_content';
    }

    $domainMapping = KnowledgeTaxonomy::mapDomain((string)($record['domain_code'] ?? ''));
    $primary = $domainMapping['primary_category'] ?? null;
    if (isset($categoryCounts[$primary])) {
        $categoryCounts[$primary]++;
    } else {
        $categoryCounts['unmapped']++;
        $reasons[] = 'primary_category_unmapped';
    }
    if (($record['domain_mapping_status'] ?? '') !== 'mapped' || $domainMapping === null) {
        $reasons[] = 'domain_mapping_pending';
        $mappingFailureCount++;
    }
    if ($status !== 'isolated') {
        $reasons[] = 'publication_boundary_violation';
    }
    if (($record['version_id'] ?? null) !== null) {
        $reasons[] = 'prepublished_version_id_present';
    }
    if ($reasons !== []) {
        $packageFailures[] = [
            'item_code' => (string)($record['item_code'] ?? ''),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }
}

$checks = [];
$packageRecordCount = integerValue($package, 'record_count');
$reviewRecordCount = integerValue($reviewSummary, 'record_count');
$databaseRecordCount = integerValue($databaseState, 'record_count');
$recordCountPassed = $packageRecordCount === $expectedCount
    && count($records) === $expectedCount
    && $reviewRecordCount === $expectedCount
    && $databaseRecordCount === $expectedCount;
addCheck($checks, 'record_count', $recordCountPassed, [
    'expected' => $expectedCount,
    'repository_declared' => $packageRecordCount,
    'repository_actual' => count($records),
    'review_report' => $reviewRecordCount,
    'target_database' => $databaseRecordCount,
], array_merge($reviewInputFailures, $databaseInputFailures, ['record_count_mismatch']));

$transitionalCount = integerValue($reviewSummary, 'transitional_count');
addCheck($checks, 'transitional_classification', $transitionalCount === 0, [
    'expected' => 0,
    'actual' => $transitionalCount,
    'category_code' => $reviewSummary['transitional_category_code'] ?? null,
], array_merge($reviewInputFailures, ['transitional_classification_present']));

$mappedCount = integerValue($reviewSummary, 'mapped_count');
$mappingGapCount = integerValue($reviewSummary, 'mapping_gap_count');
$databaseUnmappedCount = integerValue($databaseState, 'unmapped_count');
$mappingPassed = $mappingFailureCount === 0
    && $mappedCount === $expectedCount
    && $mappingGapCount === 0
    && $databaseUnmappedCount === 0
    && ($reviewReport['inputs']['taxonomy_mapping_version'] ?? null) === $taxonomyVersion
    && ($databaseState['taxonomy_mapping_version'] ?? null) === $taxonomyVersion;
addCheck($checks, 'mapping_integrity', $mappingPassed, [
    'taxonomy_mapping_version' => $taxonomyVersion,
    'review_mapping_version' => $reviewReport['inputs']['taxonomy_mapping_version'] ?? null,
    'database_mapping_version' => $databaseState['taxonomy_mapping_version'] ?? null,
    'mapped_count' => $mappedCount,
    'mapping_gap_count' => $mappingGapCount,
    'database_unmapped_count' => $databaseUnmappedCount,
    'package_mapping_failure_count' => $mappingFailureCount,
], array_merge($reviewInputFailures, $databaseInputFailures, ['domain_mapping_incomplete']));

addCheck($checks, 'package_integrity', $packageFailures === [], [
    'failure_count' => count($packageFailures),
], ['package_integrity_failed']);

$currentVersionCount = integerValue($databaseState, 'current_version_count');
addCheck($checks, 'current_versions', $currentVersionCount === $expectedCount, [
    'expected' => $expectedCount,
    'actual' => $currentVersionCount,
], array_merge($databaseInputFailures, ['current_version_count_mismatch']));

$reviewRecordCount = integerValue($databaseState, 'review_record_count');
$pendingReviewCount = integerValue($reviewSummary, 'manual_review_count');
$confirmedReviewCount = integerValue($reviewSummary['review_status_counts'] ?? [], 'confirmed');
$reviewRecordsPassed = $pendingReviewCount === 0
    && $confirmedReviewCount === $expectedCount
    && $reviewRecordCount === $expectedCount;
addCheck($checks, 'review_records', $reviewRecordsPassed, [
    'expected' => $expectedCount,
    'repository_pending' => $pendingReviewCount,
    'repository_confirmed' => $confirmedReviewCount,
    'target_database' => $reviewRecordCount,
], array_merge($reviewInputFailures, $databaseInputFailures, ['review_records_incomplete']));

$visibleCount = integerValue($databaseState, 'visible_count');
$visibilityPassed = ($databaseState['status'] ?? null) === 'passed' && $visibleCount === $expectedCount;
addCheck($checks, 'target_visibility', $visibilityPassed, [
    'expected' => $expectedCount,
    'actual' => $visibleCount,
    'database_status' => $databaseState['status'] ?? null,
], array_merge($databaseInputFailures, ['target_visible_count_mismatch']));

$report = [
    'schema_version' => 'knowledge-card-release-gate.v2',
    'package' => basename($packagePath),
    'review_report' => $reviewPath === '' ? null : basename($reviewPath),
    'database_evidence' => $databaseEvidencePath === '' ? null : ($databaseEvidencePath === '-' ? 'stdin' : basename($databaseEvidencePath)),
    'taxonomy_mapping_version' => $taxonomyVersion,
    'expected_count' => $expectedCount,
    'ready_for_unified_release' => array_reduce($checks, fn(bool $ready, array $check): bool => $ready && $check['passed'], true),
    'checks' => $checks,
    'counts' => $categoryCounts,
    'status_counts' => $statusCounts,
    'manual_review_count' => $pendingReviewCount,
    'package_failures' => $packageFailures,
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit($report['ready_for_unified_release'] ? 0 : 1);
