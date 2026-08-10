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
        $operationWhere = "l.module = 'recruitment'";
        $operationParams = [];
        if (empty($scope['can_view_all'])) {
            [$scopedRequirementSql, $scopedRequirementParams] = $this->permissions->requirementWhereClause($scope, 'scoped_requirement');
            $operationWhere .= ' AND EXISTS (SELECT 1 FROM recruitment_requirements scoped_requirement WHERE ' . $scopedRequirementSql . ' AND ('
                . "(l.target_type = 'recruitment_requirement' AND CAST(l.target_id AS UNSIGNED) = scoped_requirement.id)"
                . " OR (l.target_type = 'recruitment_resume_batch' AND EXISTS (SELECT 1 FROM recruitment_resume_batches scoped_batch WHERE scoped_batch.id = CAST(l.target_id AS UNSIGNED) AND scoped_batch.requirement_id = scoped_requirement.id))"
                . " OR (l.target_type = 'recruitment_application' AND EXISTS (SELECT 1 FROM recruitment_applications scoped_application WHERE scoped_application.id = CAST(l.target_id AS UNSIGNED) AND scoped_application.requirement_id = scoped_requirement.id))"
                . " OR (l.target_type = 'recruitment_candidate' AND EXISTS (SELECT 1 FROM recruitment_applications scoped_candidate_application WHERE scoped_candidate_application.candidate_id = CAST(l.target_id AS UNSIGNED) AND scoped_candidate_application.requirement_id = scoped_requirement.id))"
                . " OR (l.target_type = 'recruitment_resume_document' AND EXISTS (SELECT 1 FROM recruitment_resume_documents scoped_document WHERE scoped_document.id = CAST(l.target_id AS UNSIGNED) AND scoped_document.batch_id IN (SELECT scoped_document_batch.id FROM recruitment_resume_batches scoped_document_batch WHERE scoped_document_batch.requirement_id = scoped_requirement.id)))"
                . " OR (l.target_type = 'recruitment_resume_file' AND EXISTS (SELECT 1 FROM recruitment_resume_files scoped_file JOIN recruitment_resume_batches scoped_file_batch ON scoped_file_batch.id = scoped_file.batch_id WHERE scoped_file.id = CAST(l.target_id AS UNSIGNED) AND scoped_file_batch.requirement_id = scoped_requirement.id))"
                . " OR (l.target_type = 'recruitment_export_job' AND EXISTS (SELECT 1 FROM recruitment_export_jobs scoped_export LEFT JOIN recruitment_resume_batches scoped_export_batch ON scoped_export_batch.id = scoped_export.batch_id WHERE scoped_export.id = CAST(l.target_id AS UNSIGNED) AND (scoped_export.requirement_id = scoped_requirement.id OR scoped_export_batch.requirement_id = scoped_requirement.id)))"
                . '))';
            $operationParams = $scopedRequirementParams;
        }
        $operations = $this->pdo->prepare("SELECT l.id, l.operator_staff_id, l.action, l.target_type, l.target_id, l.before_json, l.after_json, l.created_at FROM admin_operation_logs l WHERE {$operationWhere} ORDER BY l.id DESC LIMIT 100");
        $operations->execute($operationParams);
        $aiParams = [];
        $aiWhere = '1 = 1';
        if (empty($scope['can_view_all'])) {
            [$aiScopeSql, $aiScopeParams] = $this->permissions->requirementWhereClause($scope, 'ai_requirement');
            $aiWhere = $aiScopeSql;
            $aiParams = $aiScopeParams;
        }
        $ai = $this->pdo->prepare("SELECT ai.run_type, ai.status, ai.error_code, COUNT(*) AS total FROM recruitment_ai_runs ai JOIN recruitment_processing_versions ai_version ON ai_version.id = ai.processing_version_id JOIN recruitment_requirements ai_requirement ON ai_requirement.id = ai_version.requirement_id WHERE {$aiWhere} GROUP BY ai.run_type, ai.status, ai.error_code ORDER BY ai.run_type, ai.status, ai.error_code");
        $ai->execute($aiParams);
        return [
            'summary' => $summary,
            'decision_timeline' => $timeline->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'operation_logs' => $operations->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'ai_quality' => $ai->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'adjustment_alert_threshold' => 0.2,
        ];
    }
}
