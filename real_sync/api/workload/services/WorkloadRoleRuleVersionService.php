<?php
declare(strict_types=1);

final class WorkloadRoleRuleVersionException extends RuntimeException {
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int {
        return $this->statusCode;
    }
}

final class WorkloadRoleRuleVersionService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function activeForDate(string $roleCode, string $businessDate): array {
        $roleCode = $this->normalizeRole($roleCode);
        $this->assertEnabledRole($roleCode);
        $businessDate = $this->normalizeDate($businessDate);
        $stmt = $this->pdo->prepare(
            "SELECT id, version_code, role_code, template_id, minimum_positive_metrics, requires_daily_report, effective_from, "
            . "effective_to, status, description, created_by_staff_id, created_at, updated_at "
            . "FROM workload_role_rule_versions "
            . "WHERE role_code = ? AND status IN ('active', 'scheduled') AND effective_from <= ? "
            . "AND (effective_to IS NULL OR effective_to >= ?) "
            . "ORDER BY effective_from DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$roleCode, $businessDate, $businessDate]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new WorkloadRoleRuleVersionException('当前业务日期没有已生效的岗位项目规则', 409);
        }
        return $this->hydrateVersion($version);
    }

    public function forReport(int $reportId): array {
        if ($reportId <= 0) {
            throw new WorkloadRoleRuleVersionException('日报 ID 无效');
        }
        $stmt = $this->pdo->prepare(
            'SELECT report.report_date, report.role_code, report.rule_version_id, version.id, '
            . 'version.version_code, version.role_code AS version_role_code, version.template_id, '
            . 'version.minimum_positive_metrics, version.requires_daily_report, version.effective_from, version.effective_to, '
            . 'version.status, version.description, version.created_by_staff_id, version.created_at, version.updated_at '
            . 'FROM workload_daily_reports report '
            . 'LEFT JOIN workload_role_rule_versions version ON version.id = report.rule_version_id '
            . 'WHERE report.id = ? LIMIT 1'
        );
        $stmt->execute([$reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new WorkloadRoleRuleVersionException('日报不存在', 404);
        }
        if ((int) ($row['rule_version_id'] ?? 0) <= 0) {
            return $this->activeForDate((string) $row['role_code'], (string) $row['report_date']);
        }
        if ((int) ($row['id'] ?? 0) <= 0) {
            throw new WorkloadRoleRuleVersionException('日报绑定的岗位项目规则版本不存在', 500);
        }
        $row['role_code'] = $row['version_role_code'];
        return $this->hydrateVersion($row);
    }

    public function validateValues(array $values, array $version, bool $submitting, array $evidenceCounts = []): void {
        $rules = $version['metric_rules'] ?? [];
        foreach ($values as $metricCode => $numericValue) {
            if (!isset($rules[$metricCode])) {
                throw new WorkloadRoleRuleVersionException('当前规则版本不支持指标：' . $metricCode);
            }
            $value = (float) $numericValue;
            if (!is_finite($value)) {
                throw new WorkloadRoleRuleVersionException('指标值必须是有限数字：' . $metricCode);
            }
            $rule = $rules[$metricCode];
            if ($rule['min_value'] !== null && $value < $rule['min_value']) {
                throw new WorkloadRoleRuleVersionException('指标值不能小于规则最小值：' . $metricCode);
            }
            if ($rule['max_value'] !== null && $value > $rule['max_value']) {
                throw new WorkloadRoleRuleVersionException('指标值超过规则最大值：' . $metricCode);
            }
        }
        if (!$submitting) {
            return;
        }

        $positiveCount = count(array_filter($values, static fn($value): bool => (float) $value > 0));
        if ($positiveCount < (int) $version['minimum_positive_metrics']) {
            throw new WorkloadRoleRuleVersionException(
                sprintf('请至少填写 %d 个正数工作量指标后再提交', (int) $version['minimum_positive_metrics'])
            );
        }
        foreach ($rules as $metricCode => $rule) {
            if ($rule['is_required'] && !array_key_exists($metricCode, $values)) {
                throw new WorkloadRoleRuleVersionException('缺少岗位必填指标：' . $metricCode);
            }
            if (!array_key_exists($metricCode, $values)) {
                continue;
            }
            $value = (float) $values[$metricCode];
            if ($rule['is_required'] && !$rule['allow_zero'] && $value <= 0) {
                throw new WorkloadRoleRuleVersionException('岗位必填指标必须为正数：' . $metricCode);
            }
            if (!$rule['need_evidence'] || $value <= 0) {
                continue;
            }
            $count = (int) ($evidenceCounts[$metricCode] ?? 0);
            if ($count < $rule['min_evidence_count']) {
                throw new WorkloadRoleRuleVersionException(
                    sprintf('%s 至少需要上传 %d 张凭证图片', $rule['metric_name'], $rule['min_evidence_count'])
                );
            }
            if ($count > $rule['max_evidence_count']) {
                throw new WorkloadRoleRuleVersionException(
                    sprintf('%s 最多只能上传 %d 张凭证图片', $rule['metric_name'], $rule['max_evidence_count'])
                );
            }
        }
    }

    private function hydrateVersion(array $version): array {
        $id = (int) ($version['id'] ?? 0);
        $roleCode = $this->normalizeRole((string) ($version['role_code'] ?? ''));
        if ($id <= 0) {
            throw new WorkloadRoleRuleVersionException('日报绑定的岗位项目规则版本不存在', 500);
        }
        $stmt = $this->pdo->prepare(
            'SELECT rule.metric_code, rule.metric_name_snapshot, rule.unit_snapshot, rule.value_type_snapshot, '
            . 'rule.is_required, rule.allow_zero, rule.min_value, rule.max_value, '
            . 'rule.need_evidence, rule.min_evidence_count, rule.max_evidence_count, rule.audit_mode, '
            . 'rule.statistic_direction, rule.target_value, rule.sort_order, metric.id AS metric_id, '
            . 'metric.metric_name, metric.unit, metric.value_type, metric.is_system_calculated, metric.default_value '
            . 'FROM workload_role_metric_rules rule '
            . 'LEFT JOIN metric_definitions metric ON metric.metric_code = rule.metric_code AND metric.role_code = ? '
            . 'WHERE rule.rule_version_id = ? '
            . 'ORDER BY rule.sort_order, rule.id'
        );
        $stmt->execute([$roleCode, $id]);
        $metricRules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rule) {
            $metricCode = (string) $rule['metric_code'];
            $maxEvidenceCount = min(10, max(1, (int) $rule['max_evidence_count']));
            $metricRules[$metricCode] = [
                'metric_code' => $metricCode,
                'metric_id' => (int) ($rule['metric_id'] ?? 0),
                'metric_name' => (string) ($rule['metric_name_snapshot'] ?? $rule['metric_name'] ?? $metricCode),
                'unit' => (string) ($rule['unit_snapshot'] ?? $rule['unit'] ?? ''),
                'value_type' => (string) ($rule['value_type_snapshot'] ?? $rule['value_type'] ?? 'number'),
                'is_system_calculated' => (int) $rule['is_system_calculated'] === 1,
                'default_value' => $rule['default_value'] !== null ? (float) $rule['default_value'] : null,
                'is_required' => (int) $rule['is_required'] === 1,
                'allow_zero' => (int) $rule['allow_zero'] === 1,
                'min_value' => $rule['min_value'] !== null ? (float) $rule['min_value'] : null,
                'max_value' => $rule['max_value'] !== null ? (float) $rule['max_value'] : null,
                'need_evidence' => (int) $rule['need_evidence'] === 1,
                'min_evidence_count' => min($maxEvidenceCount, max(0, (int) $rule['min_evidence_count'])),
                'max_evidence_count' => $maxEvidenceCount,
                'audit_mode' => (string) $rule['audit_mode'],
                'statistic_direction' => (string) $rule['statistic_direction'],
                'target_value' => $rule['target_value'] !== null ? (float) $rule['target_value'] : null,
                'sort_order' => (int) $rule['sort_order'],
            ];
        }
        if ($metricRules === []) {
            throw new WorkloadRoleRuleVersionException('岗位项目规则版本未配置任何有效指标', 500);
        }
        return [
            'id' => $id,
            'version_code' => (string) $version['version_code'],
            'role_code' => $roleCode,
            'template_id' => isset($version['template_id']) ? (int) $version['template_id'] : null,
            'minimum_positive_metrics' => (int) $version['minimum_positive_metrics'],
            'requires_daily_report' => (int) ($version['requires_daily_report'] ?? 1) === 1,
            'effective_from' => (string) $version['effective_from'],
            'effective_to' => isset($version['effective_to']) ? (string) $version['effective_to'] : null,
            'status' => (string) $version['status'],
            'description' => (string) ($version['description'] ?? ''),
            'metric_rules' => $metricRules,
        ];
    }

    private function normalizeRole(string $roleCode): string {
        $roleCode = strtolower(trim($roleCode));
        if ($roleCode === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $roleCode)) {
            throw new WorkloadRoleRuleVersionException('岗位编码格式无效');
        }
        return $roleCode;
    }

    private function assertEnabledRole(string $roleCode): void {
        $stmt = $this->pdo->prepare('SELECT 1 FROM organization_positions WHERE position_code = ? AND status = 1 LIMIT 1');
        $stmt->execute([$roleCode]);
        if (!$stmt->fetchColumn()) {
            throw new WorkloadRoleRuleVersionException('岗位不存在或未启用');
        }
    }

    private function normalizeDate(string $businessDate): string {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $businessDate) {
            throw new WorkloadRoleRuleVersionException('业务日期格式无效');
        }
        return $businessDate;
    }
}
