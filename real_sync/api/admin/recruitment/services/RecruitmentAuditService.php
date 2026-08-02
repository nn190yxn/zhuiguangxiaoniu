<?php

declare(strict_types=1);

final class RecruitmentAuditService
{
    private PDO $pdo;
    private RecruitmentPermissionService $permissions;

    public function __construct(PDO $pdo, RecruitmentPermissionService $permissions)
    {
        $this->pdo = $pdo;
        $this->permissions = $permissions;
    }

    public function dashboard(array $scope, array $query): array
    {
        [$scopeSql, $params] = $this->permissions->requirementWhereClause($scope, 'requirement');
        $where = [$scopeSql];
        $requirementId = (int) ($query['requirement_id'] ?? 0);
        if ($requirementId > 0) {
            $where[] = 'application.requirement_id = ?';
            $params[] = $requirementId;
        }
        $from = ' FROM recruitment_applications application JOIN recruitment_requirements requirement ON requirement.id = application.requirement_id JOIN recruitment_resume_documents document ON document.id = application.document_id JOIN recruitment_candidates candidate ON candidate.id = application.candidate_id WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total, SUM(application.information_status = 'needs_confirmation') AS needs_confirmation, SUM(application.information_status = 'missing_contact') AS missing_contact, SUM(document.status = 'failed') AS parse_failed, SUM(application.system_grade = 'A') AS grade_a, SUM(application.system_grade = 'B') AS grade_b, SUM(application.system_grade = 'C') AS grade_c, SUM(application.manual_grade IS NOT NULL AND application.manual_grade <> application.system_grade) AS adjusted, SUM(candidate.duplicate_status <> 'unique') AS duplicate_count" . $from
        );
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = max(0, (int) ($summary['total'] ?? 0));
        $summary = array_map('intval', $summary);
        $summary['field_confirmation_rate'] = $total > 0 ? round($summary['needs_confirmation'] / $total, 4) : 0.0;
        $summary['parse_failure_rate'] = $total > 0 ? round($summary['parse_failed'] / $total, 4) : 0.0;
        $summary['manual_adjustment_rate'] = $total > 0 ? round($summary['adjusted'] / $total, 4) : 0.0;
        $summary['duplicate_rate'] = $total > 0 ? round($summary['duplicate_count'] / $total, 4) : 0.0;
        $summary['rule_review_recommended'] = $summary['manual_adjustment_rate'] >= 0.2;

        $timeline = $this->pdo->prepare(
            'SELECT application.id AS application_id, candidate.name, requirement.requirement_no, application.system_grade, application.manual_grade, application.effective_grade, application.total_score, application.contact_status, application.updated_at '
            . $from . ' ORDER BY application.updated_at DESC, application.id DESC LIMIT 100'
        );
        $timeline->execute($params);
        $operations = $this->pdo->prepare("SELECT id, operator_staff_id, action, target_type, target_id, before_json, after_json, created_at FROM admin_operation_logs WHERE module = 'recruitment' ORDER BY id DESC LIMIT 100");
        $operations->execute();
        $ai = $this->pdo->query("SELECT run_type, status, error_code, COUNT(*) AS total FROM recruitment_ai_runs GROUP BY run_type, status, error_code ORDER BY run_type, status, error_code");
        return [
            'summary' => $summary,
            'decision_timeline' => $timeline->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'operation_logs' => $operations->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'ai_quality' => $ai->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'adjustment_alert_threshold' => 0.2,
        ];
    }
}
