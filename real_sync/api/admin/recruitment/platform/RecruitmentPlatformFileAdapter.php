<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/platform/PrivateFileStorage.php';
require_once dirname(__DIR__, 3) . '/platform/FileAssetService.php';

final class RecruitmentPlatformFileAdapter
{
    private PlatformPrivateFileStorage $storage;
    private PlatformFileAssetService $assets;
    private string $legacyRoot;

    public function __construct(private PDO $pdo, ?string $legacyRoot = null)
    {
        $this->storage = new PlatformPrivateFileStorage();
        $this->assets = new PlatformFileAssetService(new PlatformPdoFileAssetStore($pdo));
        $configured = trim((string) ($legacyRoot ?? getenv('RECRUITMENT_RESUME_STORAGE_ROOT') ?: ''));
        $this->legacyRoot = $configured !== '' ? rtrim($configured, DIRECTORY_SEPARATOR) : dirname(__DIR__, 5) . '/.private/recruitment-resumes';
    }

    public function storeUploadedFile(array $file, int $batchId, int $operatorStaffId, array $allowedMimeTypes, int $maxBytes): array
    {
        $this->assertSchema();
        $stored = $this->storage->storeUploadedFile($file, [
            'namespace' => 'recruitment/resumes',
            'allowed_mime_types' => $allowedMimeTypes,
            'max_bytes' => $maxBytes,
        ]);
        $asset = $this->assets->register([
            ...$stored,
            'asset_class' => PlatformFileAssetPolicy::SENSITIVE_SOURCE,
            'purpose_code' => 'recruitment.resume.original',
            'owner_type' => 'staff',
            'owner_id' => (string) $operatorStaffId,
            'business_object_type' => 'recruitment_resume_batch',
            'business_object_id' => (string) $batchId,
            'retention_policy_code' => 'recruitment-raw-resume-365d',
            'retention_until' => (new DateTimeImmutable('+365 days'))->format(DATE_ATOM),
            'created_by_type' => 'staff',
            'created_by_id' => (string) $operatorStaffId,
        ]);
        return $stored + ['platform_asset_id' => (int) $asset['id']];
    }

    public function prepareDownload(array $file, array $actor, string $requestId, array $scopes = []): array
    {
        $assetId = (int) ($file['platform_asset_id'] ?? 0);
        if ($assetId <= 0) {
            return $this->prepareLegacyDownload($file);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM platform_file_assets WHERE id = ? LIMIT 1');
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset) {
            throw new RecruitmentAdminException('简历文件资产不存在', 404);
        }
        $grants = $this->pdo->prepare('SELECT * FROM platform_file_access_grants WHERE asset_id = ?');
        $grants->execute([$assetId]);
        $decision = $this->assets->authorize($asset, $actor + ['scopes' => $scopes], 'download', $grants->fetchAll(PDO::FETCH_ASSOC) ?: [], [
            'request_id' => $requestId,
            'access_reason' => 'recruitment.resume_original_view',
        ]);
        if (!$decision['allowed']) {
            throw new RecruitmentAdminException('无权下载该简历原件', 403);
        }
        return $this->storage->prepareDownload([(string) $asset['storage_key']], (string) $asset['mime_type'], (string) $asset['original_name']);
    }

    public function stream(array $download): void
    {
        $storage = isset($download['legacy']) ? new PlatformPrivateFileStorage($this->legacyRoot) : $this->storage;
        $storage->stream($download, true);
    }

    private function prepareLegacyDownload(array $file): array
    {
        $legacyStorage = new PlatformPrivateFileStorage($this->legacyRoot);
        return $legacyStorage->prepareDownload([(string) $file['storage_key']], (string) $file['mime_type'], (string) $file['original_name']) + ['legacy' => true];
    }

    private function assertSchema(): void
    {
        foreach (['platform_file_assets', 'platform_file_access_grants', 'platform_file_access_events'] as $table) {
            if (!adminTableExists($this->pdo, $table)) {
                throw new RecruitmentAdminException('平台私有文件结构尚未就绪', 503, ['code' => 'schema_not_ready']);
            }
        }
    }
}
