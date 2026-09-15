<?php
declare(strict_types=1);

final class WorkloadConversionRuleException extends RuntimeException {}

final class WorkloadConversionRuleService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function activeForDate(string $roleCode, string $businessDate): array {
        $roleCode = strtolower(trim($roleCode));
        if ($roleCode === '') {
            throw new WorkloadConversionRuleException('岗位编码不能为空');
        }
        self::assertDate($businessDate);
        $stmt = $this->pdo->prepare(
            "SELECT id, version_code, role_code, source_role_rule_version_id, effective_from, effective_to, status, description "
            . "FROM workload_conversion_rule_versions "
            . "WHERE role_code = ? AND status IN ('active', 'scheduled') AND effective_from <= ? "
            . "AND (effective_to IS NULL OR effective_to >= ?) "
            . "ORDER BY effective_from DESC, id DESC"
        );
        $stmt->execute([$roleCode, $businessDate, $businessDate]);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($versions === []) {
            throw new WorkloadConversionRuleException('当前业务日期没有已生效的换算规则版本');
        }
        if (count($versions) > 1) {
            throw new WorkloadConversionRuleException(
                '换算规则生效区间冲突：' . implode(',', array_column($versions, 'version_code'))
            );
        }

        return $this->hydrateVersion($versions[0]);
    }

    public function rulesForVersion(int $versionId): array {
        if ($versionId <= 0) {
            throw new WorkloadConversionRuleException('换算规则版本 ID 无效');
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, rule_version_id, rule_code, metric_codes_json, conversion_mode, threshold_value, '
            . 'points_per_match, daily_cap_points, tiers_json, evidence_types_json, requires_all_metrics, is_required_check '
            . 'FROM workload_conversion_rules WHERE rule_version_id = ? ORDER BY id'
        );
        $stmt->execute([$versionId]);
        return array_map([self::class, 'normalizeRule'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function snapshotForResult(int $reportId, int $conversionRuleId): array {
        if ($reportId <= 0 || $conversionRuleId <= 0) {
            throw new WorkloadConversionRuleException('日报或换算规则 ID 无效');
        }
        $stmt = $this->pdo->prepare(
            'SELECT rule_snapshot_json FROM workload_report_conversion_results '
            . 'WHERE report_id = ? AND conversion_rule_id = ? LIMIT 1'
        );
        $stmt->execute([$reportId, $conversionRuleId]);
        $snapshot = $stmt->fetchColumn();
        if (!is_string($snapshot) || $snapshot === '') {
            throw new WorkloadConversionRuleException('日报换算规则快照不存在');
        }
        return self::normalizeRule($snapshot);
    }

    public static function evaluate(array $rule, array $values, array $evidenceCounts = [], string $auditStatus = 'approved'): array {
        $rule = self::normalizeRule($rule);
        $metricValues = [];
        foreach ($rule['metric_codes'] as $metricCode) {
            $metricValues[$metricCode] = self::numericValues($values[$metricCode] ?? null);
        }
        $rawValue = round(array_sum(array_merge(...array_values($metricValues))), 4);
        $hasMetrics = self::hasRequiredMetrics($metricValues, $rule['requires_all_metrics']);
        $hasEvidence = self::hasRequiredEvidence($rule['evidence_types'], $evidenceCounts);
        $points = 0.0;

        if ($rule['conversion_mode'] === 'threshold') {
            $points = $hasMetrics && $hasEvidence && $rawValue >= $rule['threshold_value'] ? $rule['points_per_match'] : 0.0;
        } elseif ($rule['conversion_mode'] === 'step') {
            $points = $hasMetrics && $hasEvidence ? floor($rawValue / $rule['threshold_value']) * $rule['points_per_match'] : 0.0;
        } elseif ($rule['conversion_mode'] === 'tier' && $hasMetrics && $hasEvidence) {
            foreach (array_merge(...array_values($metricValues)) as $value) {
                $points += self::tierPoints($value, $rule['tiers']);
            }
        } elseif ($rule['conversion_mode'] === 'composite') {
            $componentPoints = array_map(
                static fn(array $values): float => floor(array_sum($values) / $rule['threshold_value']) * $rule['points_per_match'],
                $metricValues
            );
            $allComponentsMatch = count(array_filter($componentPoints, static fn(float $value): bool => $value > 0)) === count($componentPoints);
            $points = $hasMetrics && $hasEvidence && (!$rule['requires_all_metrics'] || $allComponentsMatch)
                ? array_sum($componentPoints)
                : 0.0;
        } elseif ($rule['conversion_mode'] === 'required_check') {
            $hasMetrics = $hasMetrics && $hasEvidence;
        }

        if ($rule['daily_cap_points'] !== null) {
            $points = min($points, $rule['daily_cap_points']);
        }
        $points = round($points, 4);

        $state = $rule['conversion_mode'] === 'required_check'
            ? ($hasMetrics ? 'met' : ($hasEvidence ? 'not_met' : 'missing_evidence'))
            : ($points > 0 ? 'met' : ($hasEvidence ? 'not_met' : 'missing_evidence'));
        $pending = 0.0;
        $effective = 0.0;
        $rejected = 0.0;
        $auditStatus = strtolower(trim($auditStatus));
        if ($rule['conversion_mode'] === 'required_check' && $state === 'met') {
            if ($auditStatus === 'pending') {
                $state = 'pending_review';
            } elseif ($auditStatus === 'rejected') {
                $state = 'rejected';
            }
        } elseif ($points > 0) {
            if ($auditStatus === 'pending') {
                $pending = $points;
                $state = 'pending_review';
            } elseif ($auditStatus === 'rejected') {
                $rejected = $points;
                $state = 'rejected';
            } else {
                $effective = $points;
            }
        }

        return [
            'rule_id' => $rule['id'],
            'rule_snapshot' => $rule,
            'raw_value' => $rawValue,
            'pending_points' => $pending,
            'effective_points' => $effective,
            'rejected_points' => $rejected,
            'completion_state' => $state,
            'explanation' => self::explanation($rule, $state, $points),
        ];
    }

    public static function normalizeRule($rule): array {
        if (is_string($rule)) {
            try {
                $rule = json_decode($rule, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new WorkloadConversionRuleException('换算规则快照 JSON 无效', 0, $exception);
            }
        }
        if (!is_array($rule)) {
            throw new WorkloadConversionRuleException('换算规则必须是对象');
        }
        $mode = strtolower(trim((string) ($rule['conversion_mode'] ?? '')));
        if (!in_array($mode, ['threshold', 'step', 'tier', 'composite', 'required_check'], true)) {
            throw new WorkloadConversionRuleException('换算规则模式无效');
        }
        $metricCodes = self::decodeList($rule['metric_codes'] ?? $rule['metric_codes_json'] ?? [], '指标编码');
        if ($metricCodes === []) {
            throw new WorkloadConversionRuleException('换算规则缺少输入指标');
        }
        $threshold = self::nullableNumber($rule['threshold_value'] ?? null);
        $points = self::nullableNumber($rule['points_per_match'] ?? null);
        if (in_array($mode, ['threshold', 'step', 'composite'], true) && ($threshold === null || $threshold <= 0 || $points === null || $points < 0)) {
            throw new WorkloadConversionRuleException('换算规则缺少有效阈值或点数');
        }
        $tiers = self::decodeList($rule['tiers'] ?? $rule['tiers_json'] ?? [], '分段');
        if ($mode === 'tier' && $tiers === []) {
            throw new WorkloadConversionRuleException('分段换算规则缺少分段');
        }

        return [
            'id' => (int) ($rule['id'] ?? 0),
            'rule_version_id' => (int) ($rule['rule_version_id'] ?? 0),
            'rule_code' => trim((string) ($rule['rule_code'] ?? '')),
            'metric_codes' => array_values(array_unique(array_map(static fn($code): string => trim((string) $code), $metricCodes))),
            'conversion_mode' => $mode,
            'threshold_value' => $threshold,
            'points_per_match' => $points ?? 0.0,
            'daily_cap_points' => self::nullableNumber($rule['daily_cap_points'] ?? null),
            'tiers' => $tiers,
            'evidence_types' => self::decodeList($rule['evidence_types'] ?? $rule['evidence_types_json'] ?? [], '凭证类型'),
            'requires_all_metrics' => (bool) ($rule['requires_all_metrics'] ?? false),
            'is_required_check' => (bool) ($rule['is_required_check'] ?? false),
        ];
    }

    private function hydrateVersion(array $version): array {
        $version['id'] = (int) $version['id'];
        $version['rules'] = $this->rulesForVersion($version['id']);
        if ($version['rules'] === []) {
            throw new WorkloadConversionRuleException('换算规则版本未配置规则明细');
        }
        return $version;
    }

    private static function tierPoints(float $value, array $tiers): float {
        $matches = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            $min = self::nullableNumber($tier['min'] ?? $tier['minimum'] ?? null) ?? 0.0;
            $max = self::nullableNumber($tier['max'] ?? $tier['maximum'] ?? null);
            if ($value >= $min && ($max === null || $value <= $max)) {
                $matches[] = ['points' => self::nullableNumber($tier['points'] ?? null) ?? 0.0, 'priority' => (int) ($tier['priority'] ?? 0), 'min' => $min];
            }
        }
        usort($matches, static fn(array $left, array $right): int => [$right['priority'], $right['min']] <=> [$left['priority'], $left['min']]);
        return $matches[0]['points'] ?? 0.0;
    }

    private static function hasRequiredMetrics(array $metricValues, bool $requiresAll): bool {
        $filled = array_filter($metricValues, static fn(array $values): bool => $values !== [] && max($values) > 0);
        return $requiresAll ? count($filled) === count($metricValues) : $filled !== [];
    }

    private static function hasRequiredEvidence(array $types, array $evidenceCounts): bool {
        if ($types === []) {
            return true;
        }
        foreach ($types as $type) {
            if ((int) ($evidenceCounts[$type] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    private static function numericValues($value): array {
        $values = is_array($value) ? $value : [$value];
        $numbers = [];
        foreach ($values as $item) {
            if (is_numeric($item) && is_finite((float) $item)) {
                $numbers[] = (float) $item;
            }
        }
        return $numbers;
    }

    private static function decodeList($value, string $label): array {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new WorkloadConversionRuleException($label . ' JSON 无效', 0, $exception);
            }
        }
        if (!is_array($value)) {
            throw new WorkloadConversionRuleException($label . '必须是数组');
        }
        return $value;
    }

    private static function nullableNumber($value): ?float {
        return $value === null || $value === '' ? null : (is_numeric($value) ? (float) $value : null);
    }

    private static function assertDate(string $date): void {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new WorkloadConversionRuleException('业务日期无效');
        }
    }

    private static function explanation(array $rule, string $state, float $points): string {
        return sprintf('%s 使用 %s 规则，状态：%s，折算点数：%s', $rule['rule_code'], $rule['conversion_mode'], $state, $points);
    }
}
