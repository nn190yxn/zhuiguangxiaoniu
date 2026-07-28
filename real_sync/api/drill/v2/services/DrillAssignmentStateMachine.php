<?php

declare(strict_types=1);

final class DrillAssignmentStateMachine
{
    private const TRANSITIONS = [
        'assigned' => ['start' => 'in_progress', 'cancel' => 'cancelled'],
        'in_progress' => ['submit' => 'ai_evaluating', 'cancel' => 'cancelled'],
        'ai_evaluating' => ['ai_pass' => 'awaiting_review', 'ai_fail' => 'retry_available', 'require_coaching' => 'coaching_required'],
        'awaiting_review' => ['approve' => 'passed', 'return' => 'retry_available', 'require_coaching' => 'coaching_required'],
        'retry_available' => ['retry' => 'in_progress', 'require_coaching' => 'coaching_required', 'cancel' => 'cancelled'],
        'coaching_required' => ['reopen' => 'in_progress', 'cancel' => 'cancelled'],
        'passed' => [],
        'cancelled' => [],
    ];

    public static function transition(string $status, string $event): string
    {
        $next = self::TRANSITIONS[$status][$event] ?? null;
        if ($next === null) {
            throw new DomainException(sprintf('员工训练任务不能从 %s 执行 %s。', $status, $event));
        }
        return $next;
    }

    public static function assertStartable(
        string $status,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $dueAt,
        DateTimeImmutable $now,
        bool $prerequisitesEligible
    ): void {
        if (!in_array($status, ['assigned', 'retry_available', 'coaching_required'], true)) {
            throw new DomainException('当前训练任务状态无法开始。');
        }
        if ($now < $startsAt || $now > $dueAt) {
            throw new DomainException('当前时间不在训练任务有效窗口内。');
        }
        if (!$prerequisitesEligible) {
            throw new DomainException('训练任务前置条件尚未满足。');
        }
    }
}
