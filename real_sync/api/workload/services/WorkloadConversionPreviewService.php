<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadConversionRuleService.php';

final class WorkloadConversionPreviewService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function preview(string $roleCode, string $versionCode): array {
        $roleCode = strtolower(trim($roleCode));
        $versionCode = trim($versionCode);
        if ($roleCode === '' || $versionCode === '') {
            throw new WorkloadConversionRuleException('岗位和草稿版本不能为空');
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, version_code, role_code, effective_from, effective_to, status, description '
            . 'FROM workload_conversion_rule_versions WHERE version_code = ? AND role_code = ? LIMIT 1'
        );
        $stmt->execute([$versionCode, $roleCode]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft || (string) $draft['status'] !== 'draft') {
            throw new WorkloadConversionRuleException('换算规则草稿不存在');
        }
        $service = new WorkloadConversionRuleService($this->pdo);
        $draftRules = $service->rulesForVersion((int) $draft['id']);
        $currentRules = [];
        try {
            $currentRules = $service->activeForDate($roleCode, (string) $draft['effective_from'])['rules'];
        } catch (WorkloadConversionRuleException) {
            // A new role can have a V4.0 draft before it has any active history.
        }
        $currentByCode = [];
        foreach ($currentRules as $rule) {
            $currentByCode[$rule['rule_code']] = $rule;
        }
        $changes = [];
        foreach ($draftRules as $rule) {
            $before = $currentByCode[$rule['rule_code']] ?? null;
            unset($currentByCode[$rule['rule_code']]);
            $changes[] = [
                'rule_code' => $rule['rule_code'],
                'change_type' => $before === null ? 'added' : ($this->sameRule($before, $rule) ? 'unchanged' : 'changed'),
                'before' => $before,
                'after' => $rule,
            ];
        }
        foreach ($currentByCode as $rule) {
            $changes[] = ['rule_code' => $rule['rule_code'], 'change_type' => 'removed', 'before' => $rule, 'after' => null];
        }
        return [
            'draft_version' => $draft,
            'current_rule_count' => count($currentRules),
            'draft_rule_count' => count($draftRules),
            'changes' => $changes,
        ];
    }

    private function sameRule(array $left, array $right): bool {
        foreach (['metric_codes', 'conversion_mode', 'threshold_value', 'points_per_match', 'daily_cap_points', 'tiers', 'evidence_types', 'requires_all_metrics', 'is_required_check'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
