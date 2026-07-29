<?php

declare(strict_types=1);

final class DrillGrowthPolicy
{
    public static function level(array $requiredScores, ?float $fullProcessScore): array
    {
        if ($requiredScores === [] || $fullProcessScore === null) return ['status' => 'reassessment_pending'];
        $sectionMinimum = min($requiredScores);
        $score = min($sectionMinimum, $fullProcessScore);
        $floor = $score >= 90 ? 90 : ($score >= 80 ? 80 : ($score >= 70 ? 70 : ($score >= 60 ? 60 : 0)));
        $codes = [0 => 'foundation', 60 => 'developing', 70 => 'proficient', 80 => 'advanced', 90 => 'expert'];
        return ['status' => 'current', 'level_code' => $codes[$floor], 'level_floor_score' => $floor, 'level_score' => $score, 'required_section_min_score' => $sectionMinimum, 'full_process_score' => $fullProcessScore, 'required_sections_passed' => count(array_filter($requiredScores, static fn(float $value): bool => $value >= $floor)), 'required_sections_total' => count($requiredScores), 'qualification_status' => count(array_filter($requiredScores, static fn(float $value): bool => $value >= $floor)) === count($requiredScores) && $fullProcessScore >= $floor ? 'qualified' : 'both_gap'];
    }
}
