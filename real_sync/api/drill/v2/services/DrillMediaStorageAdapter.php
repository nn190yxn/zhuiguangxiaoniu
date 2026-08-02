<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/platform/PrivateFileStorage.php';

final class DrillMediaStorageAdapter
{
    public function __construct(private PlatformPrivateFileStorage $storage)
    {
    }

    public static function create(?string $storageRoot = null): self
    {
        $configuredRoot = trim((string) ($storageRoot ?? getenv('DRILL_MEDIA_STORAGE_ROOT') ?: ''));
        $root = $configuredRoot !== '' ? $configuredRoot : dirname(__DIR__, 4) . '/.private/drill-media';
        return new self(new PlatformPrivateFileStorage($root));
    }

    public function createAssetMarker(array $metadata, ?DateTimeImmutable $now = null): string
    {
        $stored = $this->storage->storeBytes(
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'drill/audio/assets',
            'json',
            $now
        );
        return (string) $stored['storage_key'];
    }

    public function storeChunk(string $content, ?DateTimeImmutable $now = null): string
    {
        $stored = $this->storage->storeBytes($content, 'drill/audio/chunks', 'part', $now);
        return (string) $stored['storage_key'];
    }

    public function prepareDownload(array $asset, array $chunks): array
    {
        usort($chunks, static fn(array $left, array $right): int => (int) $left['chunk_no'] <=> (int) $right['chunk_no']);
        $keys = array_values(array_filter(array_map(
            static fn(array $chunk): string => (string) ($chunk['storage_path'] ?? ''),
            $chunks
        )));
        return $this->storage->prepareDownload(
            $keys,
            (string) $asset['mime_type'],
            'drill-audio-' . (int) $asset['id'] . '.' . $this->extension((string) $asset['mime_type'])
        );
    }

    public function stream(array $download, bool $emitHeaders = true): void
    {
        $this->storage->stream($download, $emitHeaders);
    }

    public function cleanup(array $asset, array $chunks): array
    {
        $keys = [(string) ($asset['storage_path'] ?? '')];
        foreach ($chunks as $chunk) {
            $keys[] = (string) ($chunk['storage_path'] ?? '');
        }
        $results = [];
        foreach (array_values(array_filter(array_unique($keys))) as $storageKey) {
            $results[] = ['storage_key_sha256' => hash('sha256', $storageKey), 'status' => $this->storage->delete($storageKey)];
        }
        return [
            'object_count' => count($results),
            'deleted_count' => count(array_filter($results, static fn(array $result): bool => $result['status'] === 'deleted')),
            'missing_count' => count(array_filter($results, static fn(array $result): bool => $result['status'] === 'missing')),
            'results' => $results,
        ];
    }

    private function extension(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'audio/aac' => 'aac',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            default => 'webm',
        };
    }
}
