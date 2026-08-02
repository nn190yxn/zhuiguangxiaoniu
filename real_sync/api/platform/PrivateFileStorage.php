<?php

declare(strict_types=1);

final class PlatformPrivateFileStorage
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $configuredRoot = trim((string) ($root ?? getenv('PLATFORM_PRIVATE_FILE_ROOT') ?: ''));
        $this->root = rtrim(
            $configuredRoot !== '' ? $configuredRoot : dirname(__DIR__, 2) . '/.private/platform-files',
            DIRECTORY_SEPARATOR
        );
        $this->ensureDirectory($this->root);
    }

    public function storeUploadedFile(array $file, array $options, ?DateTimeImmutable $now = null): array
    {
        $uploadError = filter_var($file['error'] ?? null, FILTER_VALIDATE_INT);
        if ($uploadError === false || $uploadError !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('file_upload_failed');
        }
        $temporaryPath = trim((string) ($file['tmp_name'] ?? ''));
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new InvalidArgumentException('file_upload_source_invalid');
        }
        return $this->storeFile([
            ...$options,
            'source_path' => $temporaryPath,
            'original_name' => (string) ($file['name'] ?? ''),
            'declared_mime_type' => (string) ($file['type'] ?? ''),
        ], $now);
    }

    public function storeFile(array $input, ?DateTimeImmutable $now = null): array
    {
        $sourcePath = $this->requiredString($input, 'source_path', 4096);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new InvalidArgumentException('file_source_invalid');
        }
        $originalName = $this->safeFilename($this->requiredString($input, 'original_name', 255));
        $declaredMimeType = strtolower($this->requiredString($input, 'declared_mime_type', 127));
        $allowedMimeTypes = array_values(array_unique(array_map(
            static fn(mixed $mime): string => strtolower(trim((string) $mime)),
            (array) ($input['allowed_mime_types'] ?? [])
        )));
        if ($allowedMimeTypes === [] || in_array('', $allowedMimeTypes, true)) {
            throw new InvalidArgumentException('file_allowed_mime_types_invalid');
        }
        $maxBytes = filter_var($input['max_bytes'] ?? null, FILTER_VALIDATE_INT);
        if ($maxBytes === false || $maxBytes < 1) {
            throw new InvalidArgumentException('file_max_bytes_invalid');
        }
        $byteSize = filesize($sourcePath);
        if ($byteSize === false || $byteSize < 1 || $byteSize > $maxBytes) {
            throw new InvalidArgumentException('file_byte_size_invalid');
        }
        $actualMimeType = $this->detectMimeType($sourcePath);
        if (!in_array($actualMimeType, $allowedMimeTypes, true)) {
            throw new InvalidArgumentException('file_actual_mime_not_allowed');
        }
        if ($actualMimeType !== $declaredMimeType) {
            throw new InvalidArgumentException('file_declared_mime_mismatch');
        }
        $sha256 = hash_file('sha256', $sourcePath);
        if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException('file_sha256_failed');
        }
        $expectedSha256 = strtolower(trim((string) ($input['expected_sha256'] ?? '')));
        if ($expectedSha256 !== '' && (!hash_equals($expectedSha256, $sha256))) {
            throw new InvalidArgumentException('file_sha256_mismatch');
        }

        $extension = $this->extensionForMime($actualMimeType, $originalName);
        $storageKey = $this->newStorageKey(
            $this->requiredString($input, 'namespace', 160),
            $extension,
            $now
        );
        $target = $this->pathForWrite($storageKey);
        $this->copyFile($sourcePath, $target);

        return [
            'original_name' => $originalName,
            'mime_type' => $actualMimeType,
            'byte_size' => (int) $byteSize,
            'sha256' => $sha256,
            'storage_driver' => 'local_private',
            'storage_key' => $storageKey,
        ];
    }

    public function storeBytes(
        string $content,
        string $namespace,
        string $extension = 'bin',
        ?DateTimeImmutable $now = null
    ): array {
        if ($content === '') {
            throw new InvalidArgumentException('file_content_empty');
        }
        $storageKey = $this->newStorageKey($namespace, $extension, $now);
        $path = $this->pathForWrite($storageKey);
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('file_storage_write_failed');
        }
        try {
            $written = fwrite($handle, $content);
            if ($written !== strlen($content) || !fflush($handle)) {
                throw new RuntimeException('file_storage_write_failed');
            }
        } finally {
            fclose($handle);
        }
        chmod($path, 0600);

        return [
            'storage_driver' => 'local_private',
            'storage_key' => $storageKey,
            'byte_size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ];
    }

    public function prepareDownload(array $storageKeys, string $mimeType, string $filename): array
    {
        if ($storageKeys === []) {
            throw new RuntimeException('file_download_empty');
        }
        $paths = [];
        $byteSize = 0;
        foreach ($storageKeys as $storageKey) {
            $path = $this->resolveForRead((string) $storageKey);
            $size = filesize($path);
            if ($size === false) {
                throw new RuntimeException('file_size_unavailable');
            }
            $paths[] = $path;
            $byteSize += $size;
        }

        return [
            'paths' => $paths,
            'mime_type' => strtolower($this->safeMimeType($mimeType)),
            'filename' => $this->safeFilename($filename),
            'byte_size' => $byteSize,
        ];
    }

    public function stream(array $download, bool $emitHeaders = false): void
    {
        $paths = (array) ($download['paths'] ?? []);
        if ($paths === []) {
            throw new InvalidArgumentException('file_download_plan_invalid');
        }
        if ($emitHeaders) {
            $filename = $this->safeFilename((string) ($download['filename'] ?? 'download'));
            header('Content-Type: ' . $this->safeMimeType((string) ($download['mime_type'] ?? 'application/octet-stream')));
            header('Content-Length: ' . (string) ((int) ($download['byte_size'] ?? 0)));
            header('Content-Disposition: inline; filename="' . addcslashes($filename, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
        }
        foreach ($paths as $path) {
            $handle = fopen((string) $path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('file_stream_open_failed');
            }
            try {
                if (fpassthru($handle) === false) {
                    throw new RuntimeException('file_stream_read_failed');
                }
            } finally {
                fclose($handle);
            }
        }
    }

    public function cleanupExpired(array $assets, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $results = [];
        foreach ($assets as $asset) {
            if (($asset['status'] ?? 'active') !== 'active') {
                continue;
            }
            $retentionUntil = trim((string) ($asset['retention_until'] ?? ''));
            if ($retentionUntil === '' || new DateTimeImmutable($retentionUntil, new DateTimeZone('UTC')) > $now) {
                continue;
            }
            $results[] = [
                'id' => (int) ($asset['id'] ?? 0),
                'status' => $this->delete((string) ($asset['storage_key'] ?? '')),
            ];
        }

        return [
            'eligible_count' => count($results),
            'deleted_count' => count(array_filter($results, static fn(array $result): bool => $result['status'] === 'deleted')),
            'missing_count' => count(array_filter($results, static fn(array $result): bool => $result['status'] === 'missing')),
            'results' => $results,
        ];
    }

    public function delete(string $storageKey): string
    {
        $this->assertStorageKey($storageKey);
        $path = $this->candidatePath($storageKey);
        if (!is_file($path)) {
            return 'missing';
        }
        $resolved = $this->resolveForRead($storageKey);
        if (!unlink($resolved)) {
            throw new RuntimeException('file_cleanup_failed');
        }
        return 'deleted';
    }

    public function exists(string $storageKey): bool
    {
        try {
            return is_file($this->resolveForRead($storageKey));
        } catch (RuntimeException) {
            return false;
        }
    }

    public function resolveForRead(string $storageKey): string
    {
        $this->assertStorageKey($storageKey);
        $root = realpath($this->root);
        $path = realpath($this->candidatePath($storageKey));
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException('file_not_found');
        }
        return $path;
    }

    private function newStorageKey(string $namespace, string $extension, ?DateTimeImmutable $now): string
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $namespace = $this->normalizeNamespace($namespace);
        $extension = strtolower(trim($extension));
        if (preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1) {
            throw new InvalidArgumentException('file_extension_invalid');
        }
        return $namespace . '/' . $now->setTimezone(new DateTimeZone('UTC'))->format('Y/m')
            . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
    }

    private function pathForWrite(string $storageKey): string
    {
        $this->assertStorageKey($storageKey);
        $path = $this->candidatePath($storageKey);
        $this->ensureDirectory(dirname($path));
        $root = realpath($this->root);
        $directory = realpath(dirname($path));
        if ($root === false || $directory === false || ($directory !== $root && !str_starts_with($directory, $root . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('file_storage_boundary_invalid');
        }
        return $path;
    }

    private function candidatePath(string $storageKey): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storageKey);
    }

    private function copyFile(string $source, string $target): void
    {
        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('file_storage_write_failed');
        }
        $completed = false;
        try {
            if (stream_copy_to_stream($input, $output) === false || !fflush($output)) {
                throw new RuntimeException('file_storage_write_failed');
            }
            $completed = true;
        } finally {
            fclose($input);
            fclose($output);
            if (!$completed && is_file($target)) {
                unlink($target);
            }
        }
        chmod($target, 0600);
    }

    private function detectMimeType(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);
        if (!is_string($mimeType) || $mimeType === '') {
            throw new RuntimeException('file_mime_detection_failed');
        }
        return strtolower($mimeType);
    }

    private function extensionForMime(string $mimeType, string $originalName): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/aac' => 'aac',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/webm', 'video/webm' => 'webm',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            default => $this->safeOriginalExtension($originalName),
        };
    }

    private function safeOriginalExtension(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1) {
            throw new InvalidArgumentException('file_extension_invalid');
        }
        return $extension;
    }

    private function normalizeNamespace(string $namespace): string
    {
        $namespace = trim($namespace, '/');
        if ($namespace === '' || strlen($namespace) > 160) {
            throw new InvalidArgumentException('file_namespace_invalid');
        }
        foreach (explode('/', $namespace) as $segment) {
            if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $segment) !== 1) {
                throw new InvalidArgumentException('file_namespace_invalid');
            }
        }
        return $namespace;
    }

    private function assertStorageKey(string $storageKey): void
    {
        if (
            $storageKey === ''
            || str_starts_with($storageKey, '/')
            || str_contains($storageKey, '\\')
            || str_contains($storageKey, '//')
            || preg_match('#(^|/)(?:\.|\.\.)(/|$)#', $storageKey) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $storageKey) === 1
        ) {
            throw new InvalidArgumentException('file_storage_key_invalid');
        }
    }

    private function safeFilename(string $filename): string
    {
        if (
            $filename === ''
            || $filename !== basename($filename)
            || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1
        ) {
            throw new InvalidArgumentException('file_original_name_invalid');
        }
        return $filename;
    }

    private function safeMimeType(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));
        if (preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $mimeType) !== 1) {
            throw new InvalidArgumentException('file_mime_type_invalid');
        }
        return $mimeType;
    }

    private function requiredString(array $input, string $field, int $maxLength): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException($field . '_invalid');
        }
        return $value;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('file_storage_directory_unavailable');
        }
        chmod($directory, 0700);
    }
}
