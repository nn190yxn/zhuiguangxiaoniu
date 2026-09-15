<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/JobDispatcher.php';
require_once dirname(__DIR__, 2) . '/drill/v2/services/DrillEvaluationService.php';

final class DrillEvaluationJobHandler implements PlatformJobHandler
{
    public function __construct(private PDO $db)
    {
    }

    public function handle(PlatformJobExecutionContext $context, array $payload): array
    {
        $attemptId = (int) ($payload['attempt_id'] ?? 0);
        $scoreSubjectId = (int) ($payload['score_subject_id'] ?? 0);
        if ($attemptId <= 0 || $scoreSubjectId <= 0) {
            throw new PlatformJobPermanentFailure('invalid_drill_evaluation_payload');
        }

        try {
            $context->assertCurrent();
            $result = (new DrillEvaluationService($this->db, DrillAiAdapter::fromProjectRuntime()))->evaluate(
                $attemptId,
                $scoreSubjectId,
                new DateTimeImmutable('now')
            );
            $context->heartbeatIfDue();
            $context->assertCurrent();
            return $result;
        } catch (DrillAiRetryableException $error) {
            throw new PlatformJobTransientFailure($error->getMessage(), 0, $error);
        }
    }
}
