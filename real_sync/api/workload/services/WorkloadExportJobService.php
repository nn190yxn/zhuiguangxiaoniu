<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadPermissionScopeService.php';
require_once dirname(__DIR__) . '/platform/WorkloadPlatformFileAdapter.php';

final class WorkloadExportJobException extends RuntimeException {
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int {
        return $this->statusCode;
    }
}

final class WorkloadExportJobService {
    public const SYNCHRONOUS_ROW_LIMIT = 20000;

    private PDO $pdo;
    private WorkloadPermissionScopeService $permissions;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->permissions = new WorkloadPermissionScopeService($pdo);
    }

    public function create(string $exportType, array $filters, array $context, array $scope, ?int $metricVersionId): array {
        $staffId = (int) ($context['staff_id'] ?? 0);
        if ($staffId <= 0 || empty($scope['can_export'])) {
            throw new WorkloadExportJobException('当前账号无导出权限', 403);
        }
        $jobKey = $this->uuid();
        $stmt = $this->pdo->prepare(
            "INSERT INTO workload_export_jobs (job_key, export_type, requested_by_staff_id, filters_json, "
            . "scope_hash, metric_version_id, status, expires_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 7 DAY))"
        );
        $stmt->execute([
            $jobKey,
            $exportType,
            $staffId,
            json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->scopeHash($scope),
            $metricVersionId,
        ]);
        return ['job_id' => $jobKey, 'status' => 'pending', 'row_limit' => self::SYNCHRONOUS_ROW_LIMIT];
    }

    public function status(string $jobKey, array $context): array {
        $job = $this->job($jobKey);
        $scope = $this->assertCurrentAccess($job, $context);
        return [
            'job_id' => (string) $job['job_key'],
            'export_type' => (string) $job['export_type'],
            'status' => (string) $job['status'],
            'row_count' => (int) $job['row_count'],
            'expires_at' => $job['expires_at'],
            'error_message' => (string) ($job['error_message'] ?? ''),
            'permission_scope' => $scope,
            'download_ready' => (string) $job['status'] === 'completed' && !$this->expired($job),
        ];
    }

    public function download(string $jobKey, array $context): array {
        $job = $this->job($jobKey);
        $this->assertCurrentAccess($job, $context);
        if ((string) $job['status'] !== 'completed' || $this->expired($job)) {
            throw new WorkloadExportJobException('导出文件尚不可下载', 409);
        }
        try {
            $download = WorkloadPlatformFileAdapter::prepareDownload($job, [
                $this->exportDirectory(),
                $this->legacyExportDirectory(),
            ]);
        } catch (RuntimeException) {
            throw new WorkloadExportJobException('导出文件路径无效', 500);
        }
        unset($download['policy']);
        return $download;
    }

    public function claimNext(): ?array {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query(
                "SELECT * FROM workload_export_jobs WHERE status = 'pending' ORDER BY created_at, id LIMIT 1 FOR UPDATE"
            );
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $this->pdo->commit();
                return null;
            }
            $update = $this->pdo->prepare(
                "UPDATE workload_export_jobs SET status = 'running', started_at = NOW(), error_message = NULL WHERE id = ?"
            );
            $update->execute([(int) $job['id']]);
            $this->pdo->commit();
            $job['status'] = 'running';
            return $job;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function workerContext(array $job): array {
        $stmt = $this->pdo->prepare('SELECT id AS staff_id, store_id, role FROM staffs WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $job['requested_by_staff_id']]);
        $context = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$context) {
            throw new WorkloadExportJobException('导出发起员工不存在', 403);
        }
        $context['staff_id'] = (int) $context['staff_id'];
        $context['store_id'] = (int) ($context['store_id'] ?? 0);
        $context['permissions'] = [];
        $scope = $this->permissions->resolve($context);
        if (!hash_equals((string) $job['scope_hash'], $this->scopeHash($scope))) {
            throw new WorkloadExportJobException('导出权限范围已变化', 403);
        }
        return $context;
    }

    public function complete(int $jobId, string $filePath, int $rowCount): void {
        $stmt = $this->pdo->prepare(
            "UPDATE workload_export_jobs SET status = 'completed', file_path = ?, row_count = ?, completed_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$filePath, $rowCount, $jobId]);
    }

    public function fail(int $jobId, Throwable $error): void {
        $stmt = $this->pdo->prepare(
            "UPDATE workload_export_jobs SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?"
        );
        $stmt->execute([mb_substr($error->getMessage(), 0, 500), $jobId]);
    }

    public function exportDirectory(): string {
        $root = trim((string)(getenv('PLATFORM_PRIVATE_FILE_ROOT') ?: ''));
        $root = $root !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : dirname(__DIR__, 3) . '/.private/platform-files';
        return $root . '/workload-exports';
    }

    private function legacyExportDirectory(): string {
        return dirname(__DIR__, 3) . '/data/workload-exports';
    }

    private function job(string $jobKey): array {
        if (!preg_match('/^[a-f0-9-]{36}$/', $jobKey)) {
            throw new WorkloadExportJobException('导出任务 ID 无效');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM workload_export_jobs WHERE job_key = ? LIMIT 1');
        $stmt->execute([$jobKey]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) throw new WorkloadExportJobException('导出任务不存在', 404);
        return $job;
    }

    private function assertCurrentAccess(array $job, array $context): array {
        if ((int) ($context['staff_id'] ?? 0) !== (int) $job['requested_by_staff_id']) {
            throw new WorkloadExportJobException('无权访问该导出任务', 403);
        }
        $scope = $this->permissions->resolve($context);
        if (!hash_equals((string) $job['scope_hash'], $this->scopeHash($scope))) {
            throw new WorkloadExportJobException('导出权限范围已变化', 403);
        }
        return $scope;
    }

    private function scopeHash(array $scope): string {
        ksort($scope);
        return hash('sha256', json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function expired(array $job): bool {
        return !empty($job['expires_at']) && strtotime((string) $job['expires_at']) <= time();
    }

    private function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
