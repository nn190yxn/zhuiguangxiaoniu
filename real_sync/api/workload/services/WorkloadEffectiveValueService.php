<?php
declare(strict_types=1);

final class WorkloadEffectiveValueService {
    public static function calculate(
        float $rawValue,
        string $auditMode = 'none',
        ?string $auditStatus = null,
        bool $taskExists = true
    ): array {
        $rawValue = round($rawValue, 2);
        $auditMode = strtolower(trim($auditMode));
        $auditStatus = strtolower(trim((string) $auditStatus));
        $isFullAudit = $auditMode === 'full';
        $isPending = $isFullAudit && $taskExists && $auditStatus === 'pending';
        $isApproved = $isFullAudit && $taskExists && $auditStatus === 'approved';
        $isRejected = $isFullAudit && $taskExists && $auditStatus === 'rejected';

        return [
            'raw_value' => $rawValue,
            'pending_value' => $isPending ? $rawValue : 0.0,
            'effective_value' => $isFullAudit ? ($isApproved ? $rawValue : 0.0) : $rawValue,
            'rejected_value' => $isRejected ? $rawValue : 0.0,
        ];
    }

    public static function sqlExpressions(
        string $rawExpression = 'v.numeric_value',
        string $auditModeExpression = "COALESCE(version_rules.audit_mode, rules.audit_mode, 'none')",
        string $auditStatusExpression = 't.audit_status',
        string $taskIdExpression = 't.id'
    ): array {
        $formulas = self::sqlFormulas($rawExpression, $auditModeExpression, $auditStatusExpression, $taskIdExpression);
        return [
            'raw_value' => $formulas['raw_value'] . ' AS raw_value',
            'pending_value' => $formulas['pending_value'] . ' AS pending_value',
            'effective_value' => $formulas['effective_value'] . ' AS effective_value',
            'rejected_value' => $formulas['rejected_value'] . ' AS rejected_value',
            'audit_mode' => "$auditModeExpression AS audit_mode",
            'audit_status' => "COALESCE($auditStatusExpression, '') AS audit_status",
        ];
    }

    public static function aggregateSqlExpressions(
        string $rawExpression = 'v.numeric_value',
        string $auditModeExpression = "COALESCE(version_rules.audit_mode, rules.audit_mode, 'none')",
        string $auditStatusExpression = 't.audit_status',
        string $taskIdExpression = 't.id'
    ): array {
        $formulas = self::sqlFormulas($rawExpression, $auditModeExpression, $auditStatusExpression, $taskIdExpression);
        return [
            'raw_value' => 'SUM(' . $formulas['raw_value'] . ') AS raw_value',
            'pending_value' => 'SUM(' . $formulas['pending_value'] . ') AS pending_value',
            'effective_value' => 'SUM(' . $formulas['effective_value'] . ') AS effective_value',
            'rejected_value' => 'SUM(' . $formulas['rejected_value'] . ') AS rejected_value',
        ];
    }

    public static function aggregate(array $rows): array {
        $totals = [
            'raw_value' => 0.0,
            'pending_value' => 0.0,
            'effective_value' => 0.0,
            'rejected_value' => 0.0,
        ];
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
        }
        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, 2);
        }
        return $totals;
    }

    private static function sqlFormulas(
        string $rawExpression,
        string $auditModeExpression,
        string $auditStatusExpression,
        string $taskIdExpression
    ): array {
        $fullAudit = "($auditModeExpression) = 'full'";
        $taskExists = "$taskIdExpression IS NOT NULL";
        return [
            'raw_value' => $rawExpression,
            'pending_value' => "CASE WHEN $fullAudit AND $taskExists AND $auditStatusExpression = 'pending' THEN $rawExpression ELSE 0 END",
            'effective_value' => "CASE WHEN $fullAudit THEN CASE WHEN $taskExists AND $auditStatusExpression = 'approved' THEN $rawExpression ELSE 0 END ELSE $rawExpression END",
            'rejected_value' => "CASE WHEN $fullAudit AND $taskExists AND $auditStatusExpression = 'rejected' THEN $rawExpression ELSE 0 END",
        ];
    }
}
