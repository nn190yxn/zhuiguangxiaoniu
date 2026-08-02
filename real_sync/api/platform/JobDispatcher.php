<?php
declare(strict_types=1);

require_once __DIR__ . '/JobRunner.php';

interface PlatformJobHandler
{
    public function handle(PlatformJobExecutionContext $context, array $payload): array;
}

final class PlatformJobTransientFailure extends RuntimeException {}
final class PlatformJobPermanentFailure extends RuntimeException {}
final class PlatformJobAmbiguousFailure extends RuntimeException {}

final class PlatformJobExecutionContext
{
    public function __construct(private PlatformJobRunner $runner, private PlatformJobLease $currentLease)
    {
    }

    public function lease(): PlatformJobLease
    {
        return $this->currentLease;
    }

    public function assertCurrent(): void
    {
        $this->runner->assertCurrent($this->currentLease);
    }

    public function heartbeatIfDue(): void
    {
        if ($this->runner->heartbeatDue($this->currentLease)) {
            $this->currentLease = $this->runner->heartbeat($this->currentLease);
        }
    }
}

final class PlatformJobDispatcher
{
    /** @param array<string, PlatformJobHandler|callable> $handlers */
    public function __construct(
        private PlatformJobRunner $runner,
        private array $handlers = [],
        private ?Closure $deadLetter = null
    ) {
    }

    public function run(int $maxJobs): array
    {
        if ($maxJobs < 1 || $maxJobs > 100) {
            throw new InvalidArgumentException('maxJobs 必须在 1 到 100 之间');
        }
        $summary = ['claimed' => 0, 'succeeded' => 0, 'retried' => 0, 'dead_lettered' => 0, 'items' => []];
        for ($index = 0; $index < $maxJobs; $index++) {
            $lease = $this->runner->claim();
            if ($lease === null) {
                break;
            }
            $summary['claimed']++;
            $context = new PlatformJobExecutionContext($this->runner, $lease);
            try {
                $handler = $this->handlers[$lease->jobType] ?? null;
                if ($handler === null) {
                    throw new PlatformJobPermanentFailure('unknown_job_type');
                }
                $context->assertCurrent();
                $result = $handler instanceof PlatformJobHandler
                    ? $handler->handle($context, $lease->payload)
                    : $handler($context, $lease->payload);
                $context->assertCurrent();
                $this->runner->complete($context->lease(), $result);
                $summary['succeeded']++;
                $summary['items'][] = ['job_id' => $lease->jobId, 'job_type' => $lease->jobType, 'status' => 'succeeded'];
            } catch (PlatformJobPermanentFailure|PlatformJobAmbiguousFailure $error) {
                $code = $error instanceof PlatformJobAmbiguousFailure ? 'ambiguous_external_result' : 'permanent_failure';
                $this->deadLetter($context->lease(), $code, $error->getMessage());
                $summary['dead_lettered']++;
                $summary['items'][] = ['job_id' => $lease->jobId, 'job_type' => $lease->jobType, 'status' => 'dead_letter', 'failure_class' => $error instanceof PlatformJobAmbiguousFailure ? 'ambiguous' : 'permanent'];
            } catch (PlatformJobTransientFailure $error) {
                $decision = $this->runner->fail($context->lease(), 'transient_failure', $error->getMessage());
                $summary[$decision['action'] === 'retry' ? 'retried' : 'dead_lettered']++;
                $summary['items'][] = ['job_id' => $lease->jobId, 'job_type' => $lease->jobType, 'status' => $decision['action'], 'failure_class' => 'transient'];
            } catch (PlatformJobLeaseLost $error) {
                throw $error;
            } catch (Throwable $error) {
                $decision = $this->runner->fail($context->lease(), 'transient_exception', self::summary($error));
                $summary[$decision['action'] === 'retry' ? 'retried' : 'dead_lettered']++;
                $summary['items'][] = ['job_id' => $lease->jobId, 'job_type' => $lease->jobType, 'status' => $decision['action'], 'failure_class' => 'transient'];
            }
        }
        return $summary;
    }

    private function deadLetter(PlatformJobLease $lease, string $code, string $summary): void
    {
        if ($this->deadLetter === null) {
            throw new LogicException('dead-letter store is required');
        }
        ($this->deadLetter)($lease, $code, self::summary(new RuntimeException($summary)));
    }

    private static function summary(Throwable $error): string
    {
        $message = trim($error->getMessage());
        $message = $message === '' ? get_class($error) : $message;
        return function_exists('mb_substr') ? mb_substr($message, 0, 1000) : substr($message, 0, 1000);
    }
}
