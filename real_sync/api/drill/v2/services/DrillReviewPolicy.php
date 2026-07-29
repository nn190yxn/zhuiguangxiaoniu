<?php

declare(strict_types=1);

final class DrillReviewPolicy
{
    public static function passResult(float $score, array $criticalResults, array $passPolicy): array
    {
        $minimumScore = (float) ($passPolicy['minimum_score'] ?? $passPolicy['pass_score'] ?? 60);
        if ($minimumScore < 0 || $minimumScore > 100) {
            throw new DomainException('通过总分阈值必须在 0 到 100 之间。');
        }
        $failed = [];
        foreach ($criticalResults as $code => $result) {
            if (($result['passed'] ?? false) !== true) {
                $failed[] = (string) $code;
            }
        }
        return [
            'passed' => $score >= $minimumScore && $failed === [],
            'minimum_score' => $minimumScore,
            'failed_critical_items' => $failed,
        ];
    }

    public static function assertReviewDecision(string $decision, float $aiScore, float $finalScore, array $criticalResults, array $passPolicy, string $adjustmentReason): array
    {
        if (!in_array($decision, ['passed', 'retry', 'coaching_required'], true)) {
            throw new DomainException('复核结论无效。');
        }
        if ($finalScore < 0 || $finalScore > 100) {
            throw new DomainException('人工最终分数必须在 0 到 100 之间。');
        }
        if (round($aiScore, 2) !== round($finalScore, 2) && trim($adjustmentReason) === '') {
            throw new DomainException('人工改分必须填写调整原因。');
        }
        $result = self::passResult($finalScore, $criticalResults, $passPolicy);
        if ($decision === 'passed' && !$result['passed']) {
            throw new DomainException('认证通过需要满足总分阈值及全部关键项。');
        }
        return $result;
    }
}
