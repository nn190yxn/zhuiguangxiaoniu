<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadAnalyticsQueryService.php';

final class WorkloadOperatingFunnelService {
    private const SALES_STAGES = [
        'sales_resources',
        'sales_actual_visit',
        'sales_actual_arrive',
        'sales_deal_count',
        'sales_new_revenue',
    ];

    private PDO $pdo;
    private WorkloadAnalyticsQueryService $analytics;
    private WorkloadMetricVersionService $metricVersion;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->analytics = new WorkloadAnalyticsQueryService($pdo);
        $this->metricVersion = new WorkloadMetricVersionService($pdo);
    }

    public function operatingFunnel(array $input, array $context): array {
        $queryInput = $input;
        unset(
            $queryInput['metric_code'],
            $queryInput['metric_codes'],
            $queryInput['project'],
            $queryInput['project_code']
        );
        $factsResult = $this->analytics->facts($queryInput, $context);
        $filters = $factsResult['filters'];
        $aggregates = $this->aggregateMap($factsResult['rows']);
        $version = $this->relationVersion($filters['date_to']);
        $relations = $this->relationRows($version['id']);
        $relationResults = array_map(
            fn(array $relation): array => $this->relationResult($relation, $aggregates),
            $relations
        );
        $metadata = $this->metricVersion->responseMetadata($filters, $filters['sources']);

        return array_merge($metadata, [
            'data_cutoff_at' => $metadata['generated_at'],
            'permission_scope' => $factsResult['permission_scope'],
            'relation_version' => $version,
            'sales_funnel' => [
                'role_code' => 'sales',
                'stages' => $this->salesStages($aggregates),
                'conversion_rates' => $this->relationsByGroup($relationResults, 'sales_funnel'),
            ],
            'coach_plan_completion' => [
                'role_code' => 'coach',
                'rates' => $this->relationsByGroup($relationResults, 'coach_plan_completion'),
            ],
            'configured_conversions' => $this->relationsByGroup($relationResults, 'configured_conversion'),
        ]);
    }

    private function aggregateMap(array $facts): array {
        $rows = $this->analytics->aggregateByMetric($facts);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['metric_code']] = $row;
        }
        return $result;
    }

    private function relationVersion(string $businessDate): array {
        $stmt = $this->pdo->prepare(
            "SELECT id, version_code, effective_from, effective_to, status, description, created_at "
            . "FROM workload_metric_relation_versions WHERE status = 'active' AND effective_from <= ? "
            . "AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$businessDate, $businessDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new WorkloadAnalyticsQueryException('统计周期没有已生效的项目关系版本', 409);
        }
        return [
            'id' => (int) $row['id'],
            'version_code' => (string) $row['version_code'],
            'effective_from' => (string) $row['effective_from'],
            'effective_to' => $row['effective_to'] !== null ? (string) $row['effective_to'] : null,
            'status' => (string) $row['status'],
            'description' => (string) ($row['description'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function relationRows(int $versionId): array {
        $stmt = $this->pdo->prepare(
            'SELECT relation.relation_code, relation.relation_name, relation.relation_group, '
            . 'relation.role_code, relation.numerator_metric_code, relation.denominator_metric_code, '
            . 'numerator.metric_name AS numerator_metric_name, numerator.unit AS numerator_unit, '
            . 'denominator.metric_name AS denominator_metric_name, denominator.unit AS denominator_unit '
            . 'FROM workload_metric_relations relation '
            . 'LEFT JOIN metric_definitions numerator ON numerator.metric_code = relation.numerator_metric_code '
            . 'LEFT JOIN metric_definitions denominator ON denominator.metric_code = relation.denominator_metric_code '
            . 'WHERE relation.relation_version_id = ? AND relation.enabled = 1 '
            . 'ORDER BY relation.relation_group, relation.sort_order, relation.id'
        );
        $stmt->execute([$versionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function salesStages(array $aggregates): array {
        $catalog = $this->metricCatalog(self::SALES_STAGES);
        $rows = [];
        foreach (self::SALES_STAGES as $metricCode) {
            $rows[] = $this->metricResult($metricCode, $catalog[$metricCode] ?? [], $aggregates[$metricCode] ?? []);
        }
        return $rows;
    }

    private function metricCatalog(array $metricCodes): array {
        $stmt = $this->pdo->prepare(
            'SELECT metric_code, metric_name, unit FROM metric_definitions WHERE metric_code IN ('
            . implode(',', array_fill(0, count($metricCodes), '?')) . ')'
        );
        $stmt->execute($metricCodes);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(string) $row['metric_code']] = $row;
        }
        return $result;
    }

    private function metricResult(string $metricCode, array $catalog, array $aggregate): array {
        return [
            'metric_code' => $metricCode,
            'metric_name' => (string) ($catalog['metric_name'] ?? $aggregate['metric_name'] ?? ''),
            'unit' => (string) ($catalog['unit'] ?? $aggregate['unit'] ?? ''),
            'raw_value' => round((float) ($aggregate['raw_value'] ?? 0), 2),
            'pending_value' => round((float) ($aggregate['pending_value'] ?? 0), 2),
            'effective_value' => round((float) ($aggregate['effective_value'] ?? 0), 2),
            'rejected_value' => round((float) ($aggregate['rejected_value'] ?? 0), 2),
            'sample_size' => (int) ($aggregate['sample_size'] ?? 0),
            'low_sample' => (bool) ($aggregate['low_sample'] ?? true),
            'drilldown' => $this->drilldown($metricCode),
        ];
    }

    private function relationResult(array $relation, array $aggregates): array {
        $numeratorCode = (string) $relation['numerator_metric_code'];
        $denominatorCode = (string) $relation['denominator_metric_code'];
        $numerator = $aggregates[$numeratorCode] ?? [];
        $denominator = $aggregates[$denominatorCode] ?? [];
        $effectiveNumerator = round((float) ($numerator['effective_value'] ?? 0), 2);
        $effectiveDenominator = round((float) ($denominator['effective_value'] ?? 0), 2);
        $rawNumerator = round((float) ($numerator['raw_value'] ?? 0), 2);
        $rawDenominator = round((float) ($denominator['raw_value'] ?? 0), 2);

        return [
            'relation_code' => (string) $relation['relation_code'],
            'relation_name' => (string) $relation['relation_name'],
            'relation_group' => (string) $relation['relation_group'],
            'role_code' => (string) $relation['role_code'],
            'numerator_metric' => $this->relationMetric($relation, 'numerator', $numerator),
            'denominator_metric' => $this->relationMetric($relation, 'denominator', $denominator),
            'effective_rate' => $this->ratio($effectiveNumerator, $effectiveDenominator),
            'raw_rate' => $this->ratio($rawNumerator, $rawDenominator),
            'sample_size' => min(
                (int) ($numerator['sample_size'] ?? 0),
                (int) ($denominator['sample_size'] ?? 0)
            ),
            'low_sample' => (bool) ($numerator['low_sample'] ?? true)
                || (bool) ($denominator['low_sample'] ?? true),
            'has_pending_review' => round((float) ($numerator['pending_value'] ?? 0), 2) > 0
                || round((float) ($denominator['pending_value'] ?? 0), 2) > 0,
        ];
    }

    private function relationMetric(array $relation, string $side, array $aggregate): array {
        return [
            'metric_code' => (string) $relation[$side . '_metric_code'],
            'metric_name' => (string) ($relation[$side . '_metric_name'] ?? ''),
            'unit' => (string) ($relation[$side . '_unit'] ?? ''),
            'raw_value' => round((float) ($aggregate['raw_value'] ?? 0), 2),
            'pending_value' => round((float) ($aggregate['pending_value'] ?? 0), 2),
            'effective_value' => round((float) ($aggregate['effective_value'] ?? 0), 2),
            'rejected_value' => round((float) ($aggregate['rejected_value'] ?? 0), 2),
            'drilldown' => $this->drilldown((string) $relation[$side . '_metric_code']),
        ];
    }

    private function drilldown(string $metricCode): array {
        return [
            'endpoint' => '/api/workload/analytics/metric-detail.php',
            'params' => ['metric_code' => $metricCode],
        ];
    }

    private function ratio(float $numerator, float $denominator): array {
        $state = 'comparable';
        if ($denominator <= 0) {
            $state = $numerator > 0 ? 'new' : 'empty';
        }
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'value' => $denominator > 0 ? round($numerator / $denominator, 4) : 0.0,
            'state' => $state,
        ];
    }

    private function relationsByGroup(array $relations, string $group): array {
        return array_values(array_filter(
            $relations,
            static fn(array $row): bool => $row['relation_group'] === $group
        ));
    }
}
