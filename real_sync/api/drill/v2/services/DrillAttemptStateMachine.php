<?php

declare(strict_types=1);

final class DrillAttemptStateMachine
{
    private const TRANSITIONS = [
        'created' => ['start' => 'active', 'fail' => 'failed'],
        'active' => ['begin_turn' => 'turn_finalizing', 'pause' => 'paused', 'end' => 'evaluating', 'fail' => 'failed'],
        'paused' => ['resume' => 'active', 'end' => 'evaluating', 'fail' => 'failed'],
        'turn_finalizing' => ['complete_turn' => 'active', 'fail' => 'failed'],
        'evaluating' => ['evaluation_ready' => 'evaluated', 'require_speaker_confirmation' => 'speaker_confirmation_required', 'fail' => 'failed'],
        'speaker_confirmation_required' => ['confirm_speakers' => 'evaluating', 'fail' => 'failed'],
        'evaluated' => ['complete' => 'completed', 'fail' => 'failed'],
        'completed' => [],
        'failed' => [],
    ];

    public static function transition(string $status, string $event): string
    {
        $next = self::TRANSITIONS[$status][$event] ?? null;
        if ($next === null) {
            throw new DomainException(sprintf('演练实例不能从 %s 执行 %s。', $status, $event));
        }
        return $next;
    }

    public static function isEndReplay(string $status): bool
    {
        return in_array($status, ['evaluating', 'speaker_confirmation_required', 'evaluated', 'completed', 'failed'], true);
    }
}
