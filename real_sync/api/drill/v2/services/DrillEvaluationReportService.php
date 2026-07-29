<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillEvaluationPolicy.php';

final class DrillEvaluationReportService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(int $attemptId, int $evaluationId, string $context, array $evaluation, array $ai, array $references, DateTimeImmutable $now): array
    {
        $total = (float) $evaluation['total_score'];
        $grade = $total >= 85 ? 'excellent' : ($total >= 70 ? 'good' : ($total >= 60 ? 'qualified' : 'unqualified'));
        $readiness = in_array($context, ['ai_roleplay', 'training_demo'], true) ? DrillEvaluationPolicy::readiness($evaluation['dimension_scores'], $total) : ['status' => 'not_applicable', 'blocking_dimensions' => [], 'rule_version' => null];
        $report = [
            'overall_conclusion' => (string) ($ai['overall_conclusion'] ?? ''),
            'strengths' => array_values((array) ($ai['strengths'] ?? [])),
            'priority_improvements' => array_values((array) ($ai['priority_improvements'] ?? [])),
            'dimension_scores' => $evaluation['dimension_scores'],
            'critical_results' => $evaluation['critical_results'],
            'evidence_status' => (string) ($ai['evidence_status'] ?? 'supported'),
            'training_extension' => (array) ($ai['training_extension'] ?? []),
        ];
        $stmt = $this->pdo->prepare("INSERT INTO drill_evaluation_reports (attempt_id, evaluation_id, evaluation_context, evaluation_grade, readiness_status, readiness_rule_version, readiness_details_json, report_json, reference_snapshot_json, status, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?)");
        $stmt->execute([$attemptId, $evaluationId, $context, $grade, $readiness['status'], $readiness['rule_version'], $this->json($readiness), $this->json($report), $this->json($references), $now->format('Y-m-d H:i:s')]);
        $reportId = (int) $this->pdo->lastInsertId();
        $action = $this->pdo->prepare("INSERT INTO drill_report_action_items (report_id, dimension_code, action_text, success_criteria, due_at, retest_method, learning_resource_id, learning_resource_version, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'assigned')");
        $actions = (array) ($ai['smart_actions'] ?? []);
        if ($actions === []) {
            throw new DomainException('结构化报告缺少 SMART 训练任务。');
        }
        foreach ($actions as $item) {
            foreach (['dimension_code', 'action_text', 'success_criteria', 'retest_method', 'learning_resource_version'] as $field) {
                if (trim((string) ($item[$field] ?? '')) === '') {
                    throw new DomainException('SMART 训练任务字段不完整。');
                }
            }
            if ((int) ($item['learning_resource_id'] ?? 0) <= 0) {
                throw new DomainException('SMART 训练任务缺少已发布学习资源。');
            }
            $action->execute([$reportId, (string) ($item['dimension_code'] ?? ''), trim((string) ($item['action_text'] ?? '')), trim((string) ($item['success_criteria'] ?? '')), (new DateTimeImmutable((string) ($item['due_at'] ?? $now->modify('+7 days')->format('Y-m-d H:i:s'))))->format('Y-m-d H:i:s'), trim((string) ($item['retest_method'] ?? '再次完成同场景演练')), (int) $item['learning_resource_id'], (string) $item['learning_resource_version']]);
        }
        return ['report_id' => $reportId, 'evaluation_grade' => $grade, 'readiness' => $readiness];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
