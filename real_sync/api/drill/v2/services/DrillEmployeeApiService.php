<?php

declare(strict_types=1);

final class DrillEmployeeApiService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function home(int $staffId): array
    {
        $counts = $this->pdo->prepare("SELECT status, COUNT(*) AS total FROM drill_assignments WHERE staff_id = ? GROUP BY status");
        $counts->execute([$staffId]);
        $assignments = array_fill_keys(['assigned', 'in_progress', 'awaiting_review', 'coaching_required', 'passed', 'retry_available'], 0);
        foreach ($counts->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $assignments[(string) $row['status']] = (int) $row['total'];
        }
        $attempts = $this->pdo->prepare("SELECT status, COUNT(*) AS total FROM drill_attempts WHERE staff_id = ? GROUP BY status");
        $attempts->execute([$staffId]);
        return [
            'assignments' => $assignments,
            'attempt_statuses' => $attempts->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'entries' => ['assignments', 'self_practice', 'free_chat'],
        ];
    }

    public function catalog(?int $domainId, ?int $stageId, ?string $difficulty): array
    {
        $sql = "SELECT domain.id AS domain_id, domain.domain_code, domain.name AS domain_name, stage.id AS stage_id, stage.stage_code, stage.name AS stage_name, stage.sort_order, scenario.id AS scenario_id, scenario.scenario_code, scenario.difficulty, version.id AS scenario_version_id, version.version_no, version.title, version.objectives_json, version.key_actions_json FROM drill_training_domains domain INNER JOIN drill_scenarios scenario ON scenario.domain_id = domain.id INNER JOIN drill_scenario_versions version ON version.scenario_id = scenario.id INNER JOIN drill_process_stages stage ON stage.id = scenario.stage_id WHERE domain.status = 'active' AND scenario.status = 'active' AND version.status = 'published'";
        $params = [];
        if ($domainId !== null) { $sql .= ' AND domain.id = ?'; $params[] = $domainId; }
        if ($stageId !== null) { $sql .= ' AND stage.id = ?'; $params[] = $stageId; }
        if ($difficulty !== null && $difficulty !== '') { $sql .= ' AND scenario.difficulty = ?'; $params[] = $difficulty; }
        $sql .= ' ORDER BY domain.id, stage.sort_order, scenario.id, version.version_no DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = $this->jsonRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], ['objectives_json' => 'objectives', 'key_actions_json' => 'key_actions']);
        $personaOptions = [];
        $domainIds = array_values(array_unique(array_map('intval', array_column($items, 'domain_id'))));
        if ($domainIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($domainIds), '?'));
            $persona = $this->pdo->prepare(
                "SELECT domain_id, dimension_code, dimension_name, value_code, name, description FROM drill_persona_dimensions WHERE domain_id IN ($placeholders) AND status = 'active' ORDER BY domain_id, dimension_code, sort_order, id"
            );
            $persona->execute($domainIds);
            foreach ($persona->fetchAll(PDO::FETCH_ASSOC) ?: [] as $option) {
                $domainKey = (string) (int) $option['domain_id'];
                $dimensionCode = (string) $option['dimension_code'];
                $personaOptions[$domainKey][$dimensionCode][] = [
                    'value_code' => (string) $option['value_code'],
                    'name' => (string) $option['name'],
                    'description' => (string) ($option['description'] ?? ''),
                ];
            }
        }
        return ['items' => $items, 'persona_options' => $personaOptions];
    }

    public function assignments(int $staffId, ?int $assignmentId): array
    {
        $sql = "SELECT assignment.id AS assignment_id, assignment.status, assignment.failed_attempts, assignment.current_attempt_id, current_attempt.status AS current_attempt_status, current_attempt.plan_item_id AS current_attempt_plan_item_id, current_attempt.status_version AS current_attempt_status_version, assignment.starts_at, assignment.due_at, assignment.status_version, plan.id AS plan_id, plan.name AS plan_name, plan.plan_type, plan.recording_retention_days, plan.minimum_client_version, domain.id AS domain_id, domain.domain_code FROM drill_assignments assignment INNER JOIN drill_plan_publications publication ON publication.id = assignment.publication_id INNER JOIN drill_plans plan ON plan.id = publication.plan_id INNER JOIN drill_training_domains domain ON domain.id = plan.domain_id LEFT JOIN drill_attempts current_attempt ON current_attempt.id = assignment.current_attempt_id AND current_attempt.assignment_id = assignment.id AND current_attempt.staff_id = assignment.staff_id WHERE assignment.staff_id = ?";
        $params = [$staffId];
        if ($assignmentId !== null) { $sql .= ' AND assignment.id = ?'; $params[] = $assignmentId; }
        $sql .= ' ORDER BY assignment.due_at ASC, assignment.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($assignmentId !== null && $items === []) { throw new DomainException('必修任务不存在或不属于当前员工。'); }
        foreach ($items as &$item) {
            $details = $this->pdo->prepare('SELECT item.id AS plan_item_id, item.sort_order, item.evaluation_context, scenario.title AS scenario_title, scenario.objectives_json, scenario.key_actions_json FROM drill_plan_items item INNER JOIN drill_scenario_versions scenario ON scenario.id = item.scenario_version_id WHERE item.plan_id = ? ORDER BY item.sort_order');
            $details->execute([(int) $item['plan_id']]);
            $item['items'] = $this->jsonRows($details->fetchAll(PDO::FETCH_ASSOC) ?: [], ['objectives_json' => 'objectives', 'key_actions_json' => 'key_actions']);
        }
        unset($item);
        return ['items' => $items];
    }

    public function attemptStatus(int $staffId, int $attemptId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, assignment_id, status, status_version, evaluation_context, last_completed_turn_no, current_stage_id, completed_at FROM drill_attempts WHERE id = ? AND staff_id = ?');
        $stmt->execute([$attemptId, $staffId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) { throw new DomainException('演练实例不存在或不属于当前员工。'); }
        $audio = $this->pdo->prepare('SELECT id AS audio_asset_id, status, consent_status, retention_until, expired_at FROM drill_audio_assets WHERE attempt_id = ? AND staff_id = ? ORDER BY id DESC');
        $audio->execute([$attemptId, $staffId]);
        $evaluations = $this->pdo->prepare('SELECT id AS evaluation_id, status, total_score, completed_at, failure_code FROM drill_evaluations WHERE attempt_id = ? ORDER BY id DESC');
        $evaluations->execute([$attemptId]);
        return ['attempt' => $attempt, 'audio_assets' => $audio->fetchAll(PDO::FETCH_ASSOC) ?: [], 'evaluations' => $evaluations->fetchAll(PDO::FETCH_ASSOC) ?: [], 'poll_after_seconds' => 2];
    }

    public function results(int $staffId, ?int $attemptId): array
    {
        $sql = "SELECT attempt.id AS attempt_id, attempt.status AS attempt_status, attempt.evaluation_context, attempt.started_at, attempt.completed_at, scenario.title AS scene, evaluation.id AS evaluation_id, evaluation.status AS evaluation_status, evaluation.failure_code, evaluation.total_score, evaluation.dimension_scores_json, evaluation.critical_results_json, evaluation.suggestions_json, report.id AS report_id, report.evaluation_grade, report.readiness_status, report.report_json, certification.id AS certification_id, certification.certified_at FROM drill_attempts attempt LEFT JOIN drill_scenario_versions scenario ON scenario.id = attempt.scenario_version_id LEFT JOIN drill_evaluations evaluation ON evaluation.id = (SELECT MAX(latest.id) FROM drill_evaluations latest WHERE latest.attempt_id = attempt.id) LEFT JOIN drill_evaluation_reports report ON report.id = (SELECT MAX(published.id) FROM drill_evaluation_reports published WHERE published.attempt_id = attempt.id AND published.evaluation_id = evaluation.id AND published.status = 'published') LEFT JOIN drill_certifications certification ON certification.id = (SELECT MAX(certified.id) FROM drill_certifications certified WHERE certified.attempt_id = attempt.id) WHERE attempt.staff_id = ?";
        $params = [$staffId];
        if ($attemptId !== null) { $sql .= ' AND attempt.id = ?'; $params[] = $attemptId; }
        $sql .= ' ORDER BY attempt.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = $this->jsonRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], ['dimension_scores_json' => 'dimension_scores', 'critical_results_json' => 'critical_results', 'suggestions_json' => 'suggestions', 'report_json' => 'report']);
        if ($attemptId !== null && $items === []) { throw new DomainException('演练结果不存在或不属于当前员工。'); }
        if ($attemptId !== null) {
            foreach ($items as &$item) {
                $evaluationId = (int) ($item['evaluation_id'] ?? 0);
                $recommendations = $this->pdo->prepare('SELECT recommendation.id, recommendation.criterion_code, recommendation.reason_snapshot_json, resource.title, resource.mobile_locator FROM drill_learning_recommendations recommendation INNER JOIN drill_learning_resource_versions resource ON resource.id = recommendation.learning_resource_version_id WHERE recommendation.staff_id = ? AND recommendation.attempt_id = ? AND recommendation.evaluation_id = ? ORDER BY recommendation.id');
                $recommendations->execute([$staffId, $attemptId, $evaluationId]);
                $item['learning_recommendations'] = $this->jsonRows($recommendations->fetchAll(PDO::FETCH_ASSOC), ['reason_snapshot_json' => 'reason']);
                $review = $this->pdo->prepare('SELECT id, status, decision, comment, ai_score, final_score, adjustment_reason, reviewed_at FROM drill_review_tasks WHERE attempt_id = ? AND evaluation_id = ? ORDER BY id DESC LIMIT 1');
                $review->execute([$attemptId, $evaluationId]);
                $item['review'] = $review->fetch(PDO::FETCH_ASSOC) ?: null;
                // Mastery is a current aggregate; only return rows that still reference this attempt.
                $growth = $this->pdo->prepare('SELECT scope_type, scope_key, latest_attempt_id, latest_score, effective_best_score, best_attempt_id FROM drill_mastery_scores WHERE staff_id = ? AND (latest_attempt_id = ? OR best_attempt_id = ?) ORDER BY id');
                $growth->execute([$staffId, $attemptId, $attemptId]);
                $item['growth'] = $growth->fetchAll(PDO::FETCH_ASSOC);
                $media = $this->pdo->prepare("SELECT id AS audio_asset_id, status, retention_until, expired_at FROM drill_audio_assets WHERE staff_id = ? AND attempt_id = ? AND asset_type <> 'text_input' ORDER BY id");
                $media->execute([$staffId, $attemptId]);
                $item['media'] = $media->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($item);
        }
        return ['items' => $items];
    }

    public function progress(int $staffId, ?int $domainId): array
    {
        $sql = "SELECT mastery.*, domain.domain_code, stage.stage_code, stage.name AS stage_name FROM drill_mastery_scores mastery INNER JOIN drill_training_domains domain ON domain.id = mastery.domain_id LEFT JOIN drill_process_stages stage ON mastery.scope_type = 'required_section' AND stage.id = CAST(mastery.scope_key AS UNSIGNED) WHERE mastery.staff_id = ?";
        $params = [$staffId];
        if ($domainId !== null) { $sql .= ' AND mastery.domain_id = ?'; $params[] = $domainId; }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY domain.id, mastery.scope_type, stage.sort_order');
        $stmt->execute($params);
        $levels = $this->pdo->prepare('SELECT * FROM drill_growth_level_snapshots WHERE staff_id = ?' . ($domainId !== null ? ' AND domain_id = ?' : '') . ' ORDER BY calculated_at DESC');
        $levels->execute($params);
        return ['mastery' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'growth_levels' => $levels->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    public function learning(int $staffId, array $input): array
    {
        $service = new DrillLearningService($this->pdo);
        $action = (string) ($input['action'] ?? 'list');
        if ($action === 'prepare') {
            return $service->preparationLearning($staffId, (int) ($input['domain_id'] ?? 0), (int) ($input['rubric_version_id'] ?? 0));
        }
        $stmt = $this->pdo->prepare('SELECT recommendation.*, resource.title, resource.mobile_locator, progress.status AS progress_status, progress.progress_percent FROM drill_learning_recommendations recommendation INNER JOIN drill_learning_resource_versions resource ON resource.id = recommendation.learning_resource_version_id LEFT JOIN drill_learning_progress progress ON progress.recommendation_id = recommendation.id AND progress.staff_id = recommendation.staff_id WHERE recommendation.staff_id = ? ORDER BY recommendation.created_at DESC');
        $stmt->execute([$staffId]);
        return ['recommendations' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    private function jsonRows(array $rows, array $fields): array
    {
        foreach ($rows as &$row) {
            foreach ($fields as $column => $key) {
                $row[$key] = isset($row[$column]) ? (json_decode((string) $row[$column], true) ?: []) : [];
                unset($row[$column]);
            }
        }
        unset($row);
        return $rows;
    }
}
