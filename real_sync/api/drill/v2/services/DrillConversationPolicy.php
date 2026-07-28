<?php

declare(strict_types=1);

final class DrillConversationPolicy
{
    private const PRACTICE_TYPES = ['required', 'self_practice', 'free_chat', 'stage_practice', 'full_process'];
    private const EVALUATION_CONTEXTS = ['ai_roleplay', 'training_demo', 'real_call_review'];

    public static function assertAttemptDefinition(string $practiceType, string $evaluationContext): void
    {
        if (!in_array($practiceType, self::PRACTICE_TYPES, true)) {
            throw new DomainException('演练类型无效。');
        }
        if (!in_array($evaluationContext, self::EVALUATION_CONTEXTS, true)) {
            throw new DomainException('演练评分上下文无效。');
        }
    }

    public static function nextTurnNumbers(int $lastCompletedTurnNo, int $maximumTurnNo): array
    {
        if ($lastCompletedTurnNo < 0 || $maximumTurnNo < 0 || $lastCompletedTurnNo !== $maximumTurnNo) {
            throw new DomainException('演练轮次状态存在未完成或并发写入。');
        }
        return [$maximumTurnNo + 1, $maximumTurnNo + 2];
    }

    public static function nextStage(array $progress): array
    {
        usort($progress, static fn(array $left, array $right): int => (int) $left['sort_order'] <=> (int) $right['sort_order']);
        $activeIndex = null;
        foreach ($progress as $index => $stage) {
            if (($stage['status'] ?? null) === 'active') {
                if ($activeIndex !== null) {
                    throw new DomainException('完整流程同时存在多个活动板块。');
                }
                $activeIndex = $index;
            }
        }
        if ($activeIndex === null) {
            throw new DomainException('完整流程缺少活动板块。');
        }
        $next = $progress[$activeIndex + 1] ?? null;
        if ($next === null || ($next['status'] ?? null) !== 'pending') {
            throw new DomainException('完整流程已到最后板块。');
        }
        return ['current' => $progress[$activeIndex], 'next' => $next];
    }
}
