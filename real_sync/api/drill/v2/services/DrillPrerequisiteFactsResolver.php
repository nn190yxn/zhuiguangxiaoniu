<?php

declare(strict_types=1);

final class DrillPrerequisiteFactsResolver
{
    public function __construct(private PDO $pdo, private mixed $provider = null)
    {
        if ($provider !== null && !is_callable($provider)) {
            throw new InvalidArgumentException('前置条件事实提供器必须可调用。');
        }
    }

    public function resolve(int $staffId, int $domainId, array $policy): array
    {
        if ($this->provider !== null) {
            return (array) ($this->provider)($staffId, $domainId, $policy);
        }

        $facts = [];
        foreach ($policy['conditions'] ?? [] as $condition) {
            $key = (string) $condition['key'];
            $facts[$key] = match ($condition['type']) {
                'assignment_passed' => $this->hasPassedAssignment($staffId, $domainId, $key),
                'mastery_score' => $this->masteryScore($staffId, $domainId, $condition),
                'growth_stage' => $this->growthStage($staffId),
            };
        }
        return $facts;
    }

    private function hasPassedAssignment(int $staffId, int $domainId, string $planCode): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM drill_assignments assignment "
            . 'INNER JOIN drill_plan_publications publication ON publication.id = assignment.publication_id '
            . 'INNER JOIN drill_plans plan ON plan.id = publication.plan_id '
            . "WHERE assignment.staff_id = ? AND plan.domain_id = ? AND plan.plan_code = ? AND assignment.status = 'passed' LIMIT 1"
        );
        $stmt->execute([$staffId, $domainId, $planCode]);
        return (bool) $stmt->fetchColumn();
    }

    private function masteryScore(int $staffId, int $domainId, array $condition): ?float
    {
        $stmt = $this->pdo->prepare(
            'SELECT effective_best_score FROM drill_mastery_scores '
            . 'WHERE staff_id = ? AND domain_id = ? AND scope_type = ? AND scope_key = ? AND rubric_version_id = ? LIMIT 1'
        );
        $stmt->execute([
            $staffId,
            $domainId,
            $condition['scope_type'],
            $condition['key'],
            (int) $condition['rubric_version_id'],
        ]);
        $score = $stmt->fetchColumn();
        return $score === false || $score === null ? null : (float) $score;
    }

    private function growthStage(int $staffId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT stage FROM staffs WHERE id = ? AND status = 1 AND lifecycle_status = 'active' LIMIT 1");
        $stmt->execute([$staffId]);
        $stage = $stmt->fetchColumn();
        return $stage === false ? null : (string) $stage;
    }
}
