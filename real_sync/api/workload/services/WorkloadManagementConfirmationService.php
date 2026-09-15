<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadReportConversionResultService.php';

final class WorkloadManagementConfirmationService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function confirm(int $reportId, string $metricCode, array $context, string $comment = ''): array {
        if (!$this->pdo->inTransaction()) {
            throw new WorkloadAuditTaskException('负责人确认必须在事务中完成', 500);
        }
        $metricCode = trim($metricCode);
        $comment = mb_substr(trim($comment), 0, 255);
        $report = $this->authorizedReport($reportId, $metricCode, $context);
        $stmt = $this->pdo->prepare(
            'INSERT INTO workload_management_confirmations '
            . '(report_id, metric_code, confirmer_staff_id, confirmer_role_code, comment) VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE confirmer_staff_id = VALUES(confirmer_staff_id), '
            . 'confirmer_role_code = VALUES(confirmer_role_code), comment = VALUES(comment), confirmed_at = NOW(), '
            . 'revoked_at = NULL, revoked_by_staff_id = NULL, revoke_comment = \'\', updated_at = NOW()'
        );
        $stmt->execute([
            $reportId,
            $metricCode,
            (int) $context['staff_id'],
            (string) $context['role'],
            $comment,
        ]);
        (new WorkloadReportConversionResultService($this->pdo))->recalculate($reportId);
        return [
            'report_id' => $reportId,
            'metric_code' => $metricCode,
            'store_id' => (int) $report['store_id'],
            'staff_id' => (int) $report['staff_id'],
            'role_code' => (string) $report['role_code'],
            'confirmed' => true,
        ];
    }

    private function authorizedReport(int $reportId, string $metricCode, array $context): array {
        if ($reportId <= 0 || $metricCode === '' || (int) ($context['staff_id'] ?? 0) <= 0) {
            throw new WorkloadAuditTaskException('负责人确认参数无效');
        }
        $role = (string) ($context['role'] ?? '');
        if (!in_array($role, ['manager', 'operation', 'ceo', 'admin'], true)) {
            throw new WorkloadAuditTaskException('当前角色无权确认管理动作', 403);
        }
        $stmt = $this->pdo->prepare(
            'SELECT report.id, report.store_id, report.staff_id, report.role_code, report.submit_status '
            . 'FROM workload_daily_reports report '
            . 'JOIN workload_daily_report_values value ON value.report_id = report.id '
            . 'JOIN metric_definitions metric ON metric.id = value.metric_id '
            . 'WHERE report.id = ? AND metric.metric_code = ? AND value.numeric_value > 0 FOR UPDATE'
        );
        $stmt->execute([$reportId, $metricCode]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report || (string) $report['submit_status'] !== 'submitted') {
            throw new WorkloadAuditTaskException('日报未提交或不存在可确认的管理动作', 409);
        }
        if ($role === 'manager' && !appCanViewStore($context, (int) $report['store_id'])) {
            throw new WorkloadAuditTaskException('店长只能确认本门店管理动作', 403);
        }
        return $report;
    }
}
