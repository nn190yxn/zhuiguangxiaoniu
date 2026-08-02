<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/JobDispatcher.php';
require_once dirname(__DIR__, 2) . '/wecom/_common.php';

final class WecomJobHandler implements PlatformJobHandler
{
    public function __construct(private PDO $db)
    {
    }

    public function handle(PlatformJobExecutionContext $context, array $payload): array
    {
        $rootDepartmentId = (int)($payload['root_department_id'] ?? 0);
        if ($rootDepartmentId < 1) {
            throw new PlatformJobPermanentFailure('invalid_wecom_root_department');
        }

        try {
            $context->assertCurrent();
            $result = wecomSyncMembers($this->db, [
                'root_department_id' => $rootDepartmentId,
                'require_non_empty_users' => true,
            ]);
            $context->heartbeatIfDue();
            $context->assertCurrent();
            $logId = wecomWriteSyncLog($this->db, [
                'sync_type' => 'members',
                'status' => 'success',
                'departments_total' => $result['departments_total'],
                'users_total' => $result['users_total'],
                'matched_total' => $result['matched_total'],
                'updated_total' => $result['updated_total'],
                'unbound_total' => $result['unbound_total'],
                'deactivated_total' => $result['deactivated_total'],
                'payload' => $result + ['platform_job_id' => $context->lease()->jobId],
            ]);
            return ['log_id' => $logId] + $result;
        } catch (PlatformJobLeaseLost|PlatformJobPermanentFailure $error) {
            throw $error;
        } catch (Throwable $error) {
            try {
                wecomWriteSyncLog($this->db, [
                    'sync_type' => 'members',
                    'status' => 'failed',
                    'error_message' => $error->getMessage(),
                    'payload' => ['platform_job_id' => $context->lease()->jobId],
                ]);
            } catch (Throwable $logError) {
                error_log('[wecom.job] Failed to write sync log: ' . $logError->getMessage());
            }
            if (str_contains($error->getMessage(), '配置未完成')) {
                throw new PlatformJobPermanentFailure('wecom_configuration_missing', 0, $error);
            }
            throw new PlatformJobTransientFailure($error->getMessage(), 0, $error);
        }
    }
}
