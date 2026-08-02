<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/platform/FileAssetService.php';

final class WorkloadPlatformFileAdapter
{
    public static function policy(): array
    {
        return [
            'asset_class' => PlatformFileAssetPolicy::TEMPORARY_EXPORT,
            ...PlatformFileAssetPolicy::for(PlatformFileAssetPolicy::TEMPORARY_EXPORT),
        ];
    }

    public static function prepareDownload(array $job, array $exportDirectories): array
    {
        if ((string)($job['status'] ?? '') !== 'completed') {
            throw new RuntimeException('workload_export_not_completed');
        }
        $expiresAt = trim((string)($job['expires_at'] ?? ''));
        if ($expiresAt === '' || strtotime($expiresAt) <= time()) {
            throw new RuntimeException('workload_export_expired');
        }
        $filePath = realpath((string)($job['file_path'] ?? ''));
        $allowed = false;
        foreach ($exportDirectories as $exportDirectory) {
            $base = realpath((string)$exportDirectory);
            if ($base !== false && $filePath !== false && str_starts_with($filePath, $base . DIRECTORY_SEPARATOR)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed || $filePath === false) {
            throw new RuntimeException('workload_export_path_invalid');
        }
        return [
            'path' => $filePath,
            'filename' => basename($filePath),
            'row_count' => (int)($job['row_count'] ?? 0),
            'policy' => self::policy(),
        ];
    }
}
