<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillContentVersionStateMachine.php';

final class DrillContentPolicy
{
    public const NEW_SIGN_DOMAIN = 'new_signing';
    public const RENEWAL_DOMAIN = 'renewal';

    public static function assertPermission(array $permissions, string $required = 'drill.content_manage'): void
    {
        if (!in_array($required, $permissions, true)) {
            throw new DomainException('当前操作缺少演练内容管理权限。');
        }
    }

    public static function assertOrderedStages(array $stages): void
    {
        if ($stages === []) {
            throw new DomainException('流程版本至少包含一个板块。');
        }

        $codes = [];
        $orders = [];
        $orderValues = [];
        foreach ($stages as $stage) {
            $code = trim((string) ($stage['stage_code'] ?? ''));
            $order = (int) ($stage['sort_order'] ?? 0);
            if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code)) {
                throw new DomainException('板块编码无效。');
            }
            if ($order <= 0 || isset($codes[$code]) || isset($orders[$order])) {
                throw new DomainException('板块编码和排序必须为正数且保持唯一。');
            }
            $codes[$code] = true;
            $orders[$order] = true;
            $orderValues[] = $order;
        }

        sort($orderValues, SORT_NUMERIC);
        if ($orderValues !== range(1, count($orderValues))) {
            throw new DomainException('板块排序必须从 1 开始连续递增。');
        }
    }

    public static function normalizePersona(array $allowedValues, array $selection): array
    {
        if ($selection === []) {
            throw new DomainException('客户画像至少包含一个受控维度。');
        }

        $normalized = [];
        foreach ($selection as $dimensionCode => $valueCode) {
            $dimensionCode = trim((string) $dimensionCode);
            $valueCode = trim((string) $valueCode);
            $values = $allowedValues[$dimensionCode] ?? [];
            if (!in_array($valueCode, $values, true)) {
                throw new DomainException(sprintf('画像值 %s.%s 不在当前训练域白名单中。', $dimensionCode, $valueCode));
            }
            $normalized[$dimensionCode] = $valueCode;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    public static function assertScenarioPayload(array $payload): void
    {
        foreach (['title', 'customer_profile', 'objectives', 'key_actions', 'standard_expressions', 'risk_expressions', 'prompt_policy'] as $field) {
            $value = $payload[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                throw new DomainException('场景草稿缺少字段：' . $field);
            }
        }
        foreach (['customer_profile', 'objectives', 'key_actions', 'standard_expressions', 'risk_expressions', 'prompt_policy'] as $field) {
            if (!is_array($payload[$field])) {
                throw new DomainException('场景结构字段必须为对象或数组：' . $field);
            }
        }
    }

    public static function assertRubricConfig(array $config): void
    {
        $dimensions = $config['dimensions'] ?? [];
        $maxScore = (float) ($config['max_score'] ?? 0);
        if (!is_array($dimensions) || $dimensions === [] || $maxScore <= 0) {
            throw new DomainException('评分规则必须包含评分维度和正数满分。');
        }

        $codes = [];
        $weightTotal = 0.0;
        foreach ($dimensions as $dimension) {
            $code = trim((string) ($dimension['code'] ?? ''));
            $weight = (float) ($dimension['weight'] ?? 0);
            if ($code === '' || isset($codes[$code]) || $weight <= 0) {
                throw new DomainException('评分维度编码必须唯一且权重大于 0。');
            }
            foreach (['key_actions', 'standard_expressions', 'evidence_requirements', 'calibration_anchors'] as $field) {
                if (!isset($dimension[$field]) || !is_array($dimension[$field]) || $dimension[$field] === []) {
                    throw new DomainException(sprintf('评分维度 %s 缺少 %s。', $code, $field));
                }
            }
            $codes[$code] = true;
            $weightTotal += $weight;
        }
        if (abs($weightTotal - $maxScore) > 0.0001) {
            throw new DomainException('评分维度权重之和必须等于规则满分。');
        }

        $mode = (string) ($config['mode'] ?? '');
        $scorePolicy = $config['score_policy'] ?? [];
        if ($mode === 'hybrid') {
            $capabilityWeight = (float) ($scorePolicy['capability_weight'] ?? -1);
            $scriptWeight = (float) ($scorePolicy['script_match_weight'] ?? -1);
            if ($capabilityWeight < 0 || $scriptWeight < 0 || abs($capabilityWeight + $scriptWeight - 1.0) > 0.0001) {
                throw new DomainException('混合评分的能力动作与话术匹配权重之和必须为 1。');
            }
        }
    }

    public static function assertDimensionMappings(array $dimensions, array $mappings, array $stageCodes): void
    {
        $dimensionCodes = array_fill_keys(array_map(static fn(array $item): string => (string) $item['code'], $dimensions), true);
        $allowedStages = array_fill_keys($stageCodes, true);
        $mappedDimensions = [];
        foreach ($mappings as $mapping) {
            $dimensionCode = (string) ($mapping['dimension_code'] ?? '');
            $stageCode = (string) ($mapping['stage_code'] ?? '');
            if (!isset($dimensionCodes[$dimensionCode]) || !isset($allowedStages[$stageCode])) {
                throw new DomainException('评分维度映射引用了规则外维度或训练域外板块。');
            }
            $mappedDimensions[$dimensionCode] = true;
        }
        if (count($mappedDimensions) !== count($dimensionCodes)) {
            throw new DomainException('每个评分维度必须关联至少一个流程板块。');
        }
    }

    public static function rubricCodeForContext(string $domainCode, string $evaluationContext): string
    {
        if ($domainCode !== self::NEW_SIGN_DOMAIN) {
            throw new DomainException('新签评分规则只能用于新签训练域。');
        }
        return match ($evaluationContext) {
            'real_call_review' => 'new_sign_real_call_v1',
            'ai_roleplay', 'training_demo' => 'new_sign_training_demo_v1',
            default => throw new DomainException('评分上下文没有可用的新签评分规则。'),
        };
    }

    public static function assertHumanReviewedCandidate(string $sourceType, ?int $reviewedBy, ?string $reviewedAt): void
    {
        if ($sourceType === 'ai_candidate' && (($reviewedBy ?? 0) <= 0 || trim((string) $reviewedAt) === '')) {
            throw new DomainException('AI 候选场景必须完成人工审核后才能发布。');
        }
    }

    public static function referencePreflight(array $material, DateTimeImmutable|string $at, array $openIssues = []): array
    {
        $at = is_string($at) ? new DateTimeImmutable($at) : $at;
        $failures = [];
        if (($material['authorization_status'] ?? null) !== 'authorized' || trim((string) ($material['authorization_reference'] ?? '')) === '') {
            $failures[] = 'authorization';
        }
        try {
            $effectiveFrom = new DateTimeImmutable((string) ($material['effective_from'] ?? ''));
            $effectiveUntil = new DateTimeImmutable((string) ($material['effective_until'] ?? ''));
            if ($at < $effectiveFrom || $at >= $effectiveUntil) {
                $failures[] = 'validity';
            }
        } catch (Throwable) {
            $failures[] = 'validity';
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string) ($material['content_hash'] ?? ''))) {
            $failures[] = 'content_hash';
        }
        foreach ($openIssues as $issue) {
            if (($issue['status'] ?? 'open') === 'open' && ($issue['severity'] ?? 'blocking') === 'blocking') {
                $failures[] = 'open_review_issue';
                break;
            }
        }
        return array_values(array_unique($failures));
    }

    public static function publishedCatalog(array $scenarios): array
    {
        return array_values(array_filter($scenarios, static fn(array $scenario): bool =>
            ($scenario['scenario_status'] ?? null) === 'active'
            && ($scenario['version_status'] ?? null) === DrillContentVersionStateMachine::STATUS_PUBLISHED
        ));
    }
}
