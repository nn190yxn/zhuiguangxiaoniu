<?php
declare(strict_types=1);

final class PlatformRetryPolicy
{
    public function __construct(
        private int $baseDelaySeconds = 30,
        private int $maxDelaySeconds = 3600
    ) {
        if ($baseDelaySeconds < 1 || $maxDelaySeconds < $baseDelaySeconds) {
            throw new InvalidArgumentException('重试延迟配置无效');
        }
    }

    public function decision(int $attemptCount, int $maxAttempts): array
    {
        if ($attemptCount < 1 || $maxAttempts < 1) {
            throw new InvalidArgumentException('任务尝试次数必须为正整数');
        }
        if ($attemptCount >= $maxAttempts) {
            return ['action' => 'dead_letter', 'delay_seconds' => null];
        }

        $exponent = min(30, $attemptCount - 1);
        $delay = min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** $exponent));
        return ['action' => 'retry', 'delay_seconds' => (int)$delay];
    }
}
