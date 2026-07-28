<?php

declare(strict_types=1);

final class DrillPlanPolicy
{
    private const PLAN_TYPES = ['focused_practice', 'comprehensive_certification'];
    private const TARGET_TYPES = ['position', 'store', 'staff', 'growth_stage'];
    private const EVALUATION_CONTEXTS = ['ai_roleplay', 'training_demo', 'real_call_review'];

    public static function assertPermission(array $permissions): void
    {
        if (!in_array('drill.plan_publish', $permissions, true)) {
            throw new DomainException('当前操作缺少训练计划发布权限。');
        }
    }

    public static function assertDefinition(array $plan, array $items, array $scopes): void
    {
        $code = trim((string) ($plan['plan_code'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code)) {
            throw new DomainException('训练计划编码无效。');
        }
        if (trim((string) ($plan['name'] ?? '')) === '' || !in_array($plan['plan_type'] ?? null, self::PLAN_TYPES, true)) {
            throw new DomainException('训练计划名称或类型无效。');
        }
        $retentionDays = (int) ($plan['recording_retention_days'] ?? 180);
        if ($retentionDays <= 0) {
            throw new DomainException('录音保留天数必须为正数。');
        }
        self::assertPassPolicy($plan['pass_policy'] ?? []);
        self::assertPrerequisitePolicy($plan['prerequisite_policy'] ?? []);
        self::assertItems($items, (string) $plan['plan_type']);
        self::normalizeScopes($scopes);
    }

    public static function assertPassPolicy(array $policy): void
    {
        $score = (float) ($policy['minimum_score'] ?? -1);
        if ($score < 0 || $score > 100) {
            throw new DomainException('计划通过分数必须在 0 到 100 之间。');
        }
        if (isset($policy['maximum_failed_attempts']) && (int) $policy['maximum_failed_attempts'] < 0) {
            throw new DomainException('最大失败次数不能小于 0。');
        }
    }

    public static function assertPrerequisitePolicy(array $policy): void
    {
        $keys = [];
        foreach ($policy['conditions'] ?? [] as $condition) {
            $type = (string) ($condition['type'] ?? '');
            if (!in_array($type, ['assignment_passed', 'mastery_score', 'growth_stage'], true)) {
                throw new DomainException('计划前置条件类型无效。');
            }
            $key = trim((string) ($condition['key'] ?? ''));
            if ($key === '' || isset($keys[$key])) {
                throw new DomainException('计划前置条件缺少稳定键。');
            }
            $keys[$key] = true;
            if ($type === 'mastery_score') {
                $score = (float) ($condition['minimum_score'] ?? -1);
                if (
                    $score < 0
                    || $score > 100
                    || !in_array($condition['scope_type'] ?? null, ['required_section', 'full_process'], true)
                    || (int) ($condition['rubric_version_id'] ?? 0) <= 0
                ) {
                    throw new DomainException('前置掌握度分数必须在 0 到 100 之间。');
                }
            }
            if ($type === 'growth_stage' && trim((string) ($condition['expected'] ?? '')) === '') {
                throw new DomainException('成长阶段前置条件缺少期望阶段。');
            }
        }
    }

    public static function assertItems(array $items, string $planType): void
    {
        if ($items === []) {
            throw new DomainException('训练计划至少包含一个场景。');
        }
        if ($planType === 'focused_practice' && count($items) !== 1) {
            throw new DomainException('定向练习计划只能编排一个场景。');
        }
        $orders = [];
        $scenarios = [];
        foreach ($items as $item) {
            $scenarioId = (int) ($item['scenario_version_id'] ?? 0);
            $rubricId = (int) ($item['rubric_version_id'] ?? 0);
            $sortOrder = (int) ($item['sort_order'] ?? 0);
            $context = (string) ($item['evaluation_context'] ?? 'ai_roleplay');
            if ($scenarioId <= 0 || $rubricId <= 0 || $sortOrder <= 0 || isset($orders[$sortOrder]) || isset($scenarios[$scenarioId])) {
                throw new DomainException('计划场景版本、评分规则、排序必须有效且唯一。');
            }
            if (!in_array($context, self::EVALUATION_CONTEXTS, true)) {
                throw new DomainException('计划场景评估上下文无效。');
            }
            if (isset($item['pass_policy'])) {
                if (!is_array($item['pass_policy'])) {
                    throw new DomainException('计划场景通过规则必须为结构化对象。');
                }
                self::assertPassPolicy($item['pass_policy']);
            }
            foreach ($item['material_version_ids'] ?? [] as $materialVersionId) {
                if ((int) $materialVersionId <= 0) {
                    throw new DomainException('计划参考资料版本 ID 无效。');
                }
            }
            $orders[$sortOrder] = true;
            $scenarios[$scenarioId] = true;
        }
        $ordered = array_keys($orders);
        sort($ordered, SORT_NUMERIC);
        if ($ordered !== range(1, count($items))) {
            throw new DomainException('计划场景排序必须从 1 开始连续递增。');
        }
    }

    public static function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        $hasInclude = false;
        foreach ($scopes as $scope) {
            $type = (string) ($scope['target_type'] ?? '');
            $key = trim((string) ($scope['target_key'] ?? ''));
            $mode = (string) ($scope['include_mode'] ?? 'include');
            if (!in_array($type, self::TARGET_TYPES, true) || $key === '' || !in_array($mode, ['include', 'exclude'], true)) {
                throw new DomainException('计划发布目标范围无效。');
            }
            $identity = $mode . ':' . $type . ':' . $key;
            $normalized[$identity] = [
                'target_type' => $type,
                'target_key' => $key,
                'include_mode' => $mode,
                'source_ref' => $scope['source_ref'] ?? null,
            ];
            $hasInclude = $hasInclude || $mode === 'include';
        }
        if (!$hasInclude) {
            throw new DomainException('计划发布范围至少包含一条 include 规则。');
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    public static function resolveTargets(array $staffCandidates, array $scopes): array
    {
        $scopes = self::normalizeScopes($scopes);
        $included = [];
        $excluded = [];
        foreach ($staffCandidates as $candidate) {
            if (($candidate['active'] ?? false) !== true) {
                continue;
            }
            $staffId = (int) ($candidate['staff_id'] ?? 0);
            if ($staffId <= 0) {
                continue;
            }
            foreach ($scopes as $scope) {
                if (!self::candidateMatches($candidate, $scope['target_type'], $scope['target_key'])) {
                    continue;
                }
                if ($scope['include_mode'] === 'exclude') {
                    $excluded[$staffId] = true;
                } else {
                    $included[$staffId] = true;
                }
            }
        }
        $resolved = array_keys(array_diff_key($included, $excluded));
        sort($resolved, SORT_NUMERIC);
        return $resolved;
    }

    public static function assertReviewers(array $reviewers): void
    {
        if ($reviewers === []) {
            throw new DomainException('训练计划发布至少指定一名有效复核人。');
        }
        $seen = [];
        foreach ($reviewers as $reviewer) {
            $staffId = (int) ($reviewer['staff_id'] ?? 0);
            if ($staffId <= 0 || isset($seen[$staffId]) || ($reviewer['active'] ?? false) !== true || ($reviewer['can_review'] ?? false) !== true) {
                throw new DomainException('复核人必须是具备演练复核权限的有效员工。');
            }
            $seen[$staffId] = true;
        }
    }

    public static function assertPublicationWindow(DateTimeImmutable $startsAt, DateTimeImmutable $dueAt): void
    {
        if ($dueAt < $startsAt) {
            throw new DomainException('训练任务截止时间必须晚于开始时间。');
        }
    }

    public static function publicationRequestHash(
        int $planId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $dueAt,
        array $reviewerStaffIds,
        array $scopes,
        string $planDefinitionHash
    ): string {
        $reviewerStaffIds = array_values(array_unique(array_map('intval', $reviewerStaffIds)));
        sort($reviewerStaffIds, SORT_NUMERIC);
        return self::snapshotHash([
            'plan_id' => $planId,
            'starts_at' => $startsAt->format(DATE_ATOM),
            'due_at' => $dueAt->format(DATE_ATOM),
            'reviewer_staff_ids' => $reviewerStaffIds,
            'target_scopes' => self::normalizeScopes($scopes),
            'plan_definition_hash' => $planDefinitionHash,
        ]);
    }

    public static function evaluatePrerequisites(array $policy, array $facts): array
    {
        self::assertPrerequisitePolicy($policy);
        $results = [];
        foreach ($policy['conditions'] ?? [] as $condition) {
            $key = (string) $condition['key'];
            $actual = $facts[$key] ?? null;
            $passed = match ($condition['type']) {
                'assignment_passed' => $actual === true,
                'mastery_score' => is_numeric($actual) && (float) $actual >= (float) $condition['minimum_score'],
                'growth_stage' => (string) $actual === (string) ($condition['expected'] ?? ''),
            };
            $results[] = ['key' => $key, 'passed' => $passed, 'actual' => $actual];
        }
        return ['eligible' => !in_array(false, array_column($results, 'passed'), true), 'conditions' => $results];
    }

    public static function snapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode(self::canonicalize($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function candidateMatches(array $candidate, string $type, string $key): bool
    {
        return match ($type) {
            'staff' => (string) ($candidate['employee_no'] ?? '') === $key,
            'growth_stage' => (string) ($candidate['growth_stage'] ?? '') === $key,
            'position' => in_array($key, $candidate['position_codes'] ?? [], true),
            'store' => in_array($key, $candidate['store_codes'] ?? [], true),
        };
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
