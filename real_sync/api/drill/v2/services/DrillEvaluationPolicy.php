<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillContentPolicy.php';

final class DrillEvaluationPolicy
{
    public static function assertRoute(string $domainCode, string $context, string $rubricCode): void
    {
        if ($domainCode === DrillContentPolicy::NEW_SIGN_DOMAIN && DrillContentPolicy::rubricCodeForContext($domainCode, $context) !== $rubricCode) {
            throw new DomainException('评分上下文与锁定评分规则不匹配。');
        }
    }

    public static function score(array $rubric, array $ai): array
    {
        $dimensions = (array) ($rubric['dimensions'] ?? []);
        $policy = (array) ($rubric['score_policy'] ?? []);
        $mode = (string) ($rubric['mode'] ?? 'capability');
        $max = (float) ($rubric['max_score'] ?? 100);
        if ($dimensions === [] || $max <= 0) {
            throw new DomainException('评分规则快照不完整。');
        }
        $rows = [];
        $total = 0.0;
        foreach ($dimensions as $dimension) {
            $code = (string) ($dimension['code'] ?? '');
            $weight = (float) ($dimension['weight'] ?? 0);
            $source = (array) (($ai['dimension_scores'] ?? [])[$code] ?? []);
            $capability = self::bounded($source['capability_score'] ?? $source['score'] ?? 0, $weight);
            $script = self::bounded($source['script_match_score'] ?? $source['score'] ?? 0, $weight);
            $score = match ($mode) {
                'script_match' => $script,
                'hybrid' => ($capability * (float) ($policy['capability_weight'] ?? 0.5)) + ($script * (float) ($policy['script_match_weight'] ?? 0.5)),
                default => $capability,
            };
            $rows[$code] = ['score' => round($score, 2), 'max_score' => $weight, 'evidence_status' => (string) ($source['evidence_status'] ?? ($source === [] ? 'insufficient_evidence' : 'supported'))];
            $total += $score;
        }
        return ['total_score' => round(min($max, max(0, $total)), 2), 'dimension_scores' => $rows, 'critical_results' => self::criticalResults($rubric, $ai, $rows)];
    }

    public static function scoreableSegments(array $segments, string $subjectKey, string $context): array
    {
        $selected = array_values(array_filter($segments, static fn(array $segment): bool =>
            (string) ($segment['speaker_key'] ?? '') === $subjectKey
            && ($context !== 'training_demo' || !(bool) ($segment['is_coach_supplement'] ?? false))
        ));
        if ($selected === []) {
            throw new DomainException('评分对象没有可用的已确认转写分段。');
        }
        return $selected;
    }

    public static function validateAiEvaluation(array $payload, array $segments, float $maxScore): void
    {
        if (!isset($payload['dimension_scores']) || !is_array($payload['dimension_scores']) || !isset($payload['critical_results']) || !is_array($payload['critical_results'])) {
            throw new DomainException('AI 评分结构不完整。');
        }
        if (isset($payload['total_score']) && (!is_numeric($payload['total_score']) || (float) $payload['total_score'] < 0 || (float) $payload['total_score'] > $maxScore)) {
            throw new DomainException('AI 总分超出评分规则边界。');
        }
        $segmentIds = array_fill_keys(array_map(static fn(array $segment): int => (int) $segment['id'], $segments), true);
        foreach ((array) ($payload['evidence'] ?? []) as $evidence) {
            if (!isset($segmentIds[(int) ($evidence['segment_id'] ?? 0)])) {
                throw new DomainException('AI 评分证据无法定位到评分输入分段。');
            }
        }
    }

    public static function readiness(array $scores, float $total): array
    {
        // Legacy contracts call the FAB conversion dimension 'fab_value'.
        $thresholds = ['needs_discovery' => 10, 'fab_conversion' => 14, 'trial_close' => 6, 'objection_handling' => 10, 'pricing_negotiation' => 7];
        $blocks = [];
        foreach ($thresholds as $code => $minimum) {
            $score = (float) (($scores[$code]['score'] ?? 0));
            $max = (float) (($scores[$code]['max_score'] ?? $minimum));
            if ($score < $minimum || $score < $max * 0.5) {
                $blocks[] = $code;
            }
        }
        return ['status' => $blocks === [] && $total >= 70 ? 'ready' : 'not_ready', 'blocking_dimensions' => $blocks, 'rule_version' => 'new_sign_readiness_v1'];
    }

    private static function criticalResults(array $rubric, array $ai, array $scores): array
    {
        $results = [];
        foreach ((array) ($rubric['critical_items'] ?? []) as $item) {
            $code = (string) ($item['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $value = (array) (($ai['critical_results'] ?? [])[$code] ?? []);
            $dimension = (string) ($item['dimension_code'] ?? '');
            $minimum = (float) ($item['minimum_score'] ?? 0);
            $results[$code] = ['passed' => array_key_exists('passed', $value) ? (bool) $value['passed'] : (float) ($scores[$dimension]['score'] ?? 0) >= $minimum, 'status' => (string) ($value['status'] ?? 'supported')];
        }
        return $results;
    }

    private static function bounded(mixed $value, float $maximum): float
    {
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > $maximum) {
            throw new DomainException('AI 维度分数超出评分规则边界。');
        }
        return (float) $value;
    }
}
