<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';

final class PlatformStateVersion
{
    public static function assertExpected(int $currentVersion, ?int $expectedVersion, array $context = []): void
    {
        if ($currentVersion < 0) {
            throw new LogicException('Current state version must be non-negative');
        }
        if ($expectedVersion === null || $expectedVersion < 0) {
            throw new PlatformApiException(400, 'state_version_required', '请提供有效的状态版本');
        }
        if ($expectedVersion !== $currentVersion) {
            throw new PlatformApiException(409, 'version_conflict', '状态已更新，请刷新后重试', [
                ...PlatformSyncProtocol::versionConflict($currentVersion, $expectedVersion, $context),
            ]);
        }
    }

    public static function next(int $currentVersion): int
    {
        if ($currentVersion < 0 || $currentVersion === PHP_INT_MAX) {
            throw new LogicException('State version cannot be advanced');
        }
        return $currentVersion + 1;
    }

    public static function advance(int $currentVersion, ?int $expectedVersion, array $context = []): int
    {
        self::assertExpected($currentVersion, $expectedVersion, $context);
        return self::next($currentVersion);
    }
}
