<?php

declare(strict_types=1);

final class ResumeArchiveExtractor
{
    private const DEFAULT_MAX_ARCHIVE_BYTES = 100 * 1024 * 1024;
    private const DEFAULT_MAX_ENTRIES = 500;
    private const DEFAULT_MAX_EXPANDED_BYTES = 2 * 1024 * 1024 * 1024;
    private const DEFAULT_MAX_ENTRY_BYTES = 20 * 1024 * 1024;
    private const DEFAULT_MAX_COMPRESSION_RATIO = 100;
    private const DEFAULT_TIMEOUT_SECONDS = 30;
    private const ARCHIVE_TYPES = [
        'zip' => ['zip'],
        'rar' => ['rar', 'rar5'],
        '7z' => ['7z'],
    ];
    private const ALLOWED_FILES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    private string $binary;
    private int $maxArchiveBytes;
    private int $maxEntries;
    private int $maxExpandedBytes;
    private int $maxEntryBytes;
    private int $maxCompressionRatio;
    private int $timeoutSeconds;

    public function __construct(?string $binary = null)
    {
        $this->binary = trim((string) ($binary ?? getenv('RECRUITMENT_ARCHIVE_7Z_BINARY') ?: '/usr/bin/7z'));
        $this->maxArchiveBytes = $this->configuredLimit('RECRUITMENT_ARCHIVE_MAX_BYTES', self::DEFAULT_MAX_ARCHIVE_BYTES);
        $this->maxEntries = $this->configuredLimit('RECRUITMENT_ARCHIVE_MAX_ENTRIES', self::DEFAULT_MAX_ENTRIES);
        $this->maxExpandedBytes = $this->configuredLimit('RECRUITMENT_ARCHIVE_MAX_EXPANDED_BYTES', self::DEFAULT_MAX_EXPANDED_BYTES);
        $this->maxEntryBytes = $this->configuredLimit('RECRUITMENT_ARCHIVE_MAX_ENTRY_BYTES', self::DEFAULT_MAX_ENTRY_BYTES);
        $this->maxCompressionRatio = $this->configuredLimit('RECRUITMENT_ARCHIVE_MAX_COMPRESSION_RATIO', self::DEFAULT_MAX_COMPRESSION_RATIO);
        $this->timeoutSeconds = $this->configuredLimit('RECRUITMENT_ARCHIVE_TIMEOUT_SECONDS', self::DEFAULT_TIMEOUT_SECONDS);
    }

    public static function isArchiveName(string $name): bool
    {
        return isset(self::ARCHIVE_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))]);
    }

    public function extract(string $archivePath, string $containerOriginalName): array
    {
        $this->assertArchiveSource($archivePath, $containerOriginalName);
        $workspace = $this->createWorkspace();
        try {
            $listing = $this->run([$this->binary, 'l', '-slt', '-sccUTF-8', '--', $archivePath]);
            [$archiveType, $records] = $this->parseListing($listing);
            $extension = strtolower(pathinfo($containerOriginalName, PATHINFO_EXTENSION));
            if (!in_array($archiveType, self::ARCHIVE_TYPES[$extension], true)) {
                $this->fail('resume_archive_type_mismatch', '压缩包内容与扩展名不一致');
            }
            $entries = $this->validateRecords($records, (int) filesize($archivePath));
            $this->run([$this->binary, 'x', '-y', '-bd', '-bb0', '-sccUTF-8', '-o' . $workspace, '--', $archivePath]);
            return [
                'workspace' => $workspace,
                'archive_type' => $archiveType,
                'entries' => $this->verifyExtractedEntries($workspace, $entries, $containerOriginalName),
            ];
        } catch (Throwable $error) {
            $this->cleanup($workspace);
            throw $error;
        }
    }

    public function cleanup(string $workspace): void
    {
        if ($workspace === '' || !is_dir($workspace) || !str_starts_with(basename($workspace), 'recruitment-archive-')) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($workspace);
    }

    private function assertArchiveSource(string $path, string $name): void
    {
        if ($this->binary === '' || !is_executable($this->binary)) {
            $this->fail('resume_archive_tool_unavailable', '压缩包解包能力尚未配置', 503);
        }
        if (!is_file($path) || !is_readable($path) || !self::isArchiveName($name)) {
            $this->fail('resume_archive_invalid', '压缩包来源无效');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > $this->maxArchiveBytes) {
            $this->fail('resume_archive_limit_exceeded', '压缩包大小超过 100MB 限制', 413);
        }
    }

    private function parseListing(string $listing): array
    {
        $listing = str_replace("\r\n", "\n", $listing);
        $separator = "----------\n";
        $offset = strpos($listing, $separator);
        if ($offset === false) {
            $this->fail('resume_archive_invalid', '无法读取压缩包目录');
        }
        $header = substr($listing, 0, $offset);
        if (preg_match('/^Type = ([A-Za-z0-9]+)$/m', $header, $match) !== 1) {
            $this->fail('resume_archive_invalid', '无法识别压缩包实际类型');
        }
        $records = [];
        foreach (preg_split('/\n\s*\n/', trim(substr($listing, $offset + strlen($separator)))) ?: [] as $block) {
            $record = [];
            foreach (preg_split('/\n/', trim($block)) ?: [] as $line) {
                $parts = explode(' = ', $line, 2);
                if (count($parts) === 2) {
                    $record[trim($parts[0])] = trim($parts[1]);
                }
            }
            if (isset($record['Path'])) {
                $records[] = $record;
            }
        }
        return [strtolower($match[1]), $records];
    }

    private function validateRecords(array $records, int $archiveBytes): array
    {
        if ($records === [] || count($records) > $this->maxEntries) {
            $this->fail('resume_archive_limit_exceeded', '压缩包条目数量超过限制', 413);
        }
        $entries = [];
        $seenPaths = [];
        $totalBytes = 0;
        foreach ($records as $record) {
            $path = $this->normalizedPath((string) $record['Path']);
            $isDirectory = ($record['Folder'] ?? '-') === '+';
            if ($this->isSpecialEntry($record) || ($record['Encrypted'] ?? '-') === '+') {
                $this->fail('resume_archive_entry_unsafe', '压缩包包含特殊文件条目');
            }
            if ($isDirectory) {
                continue;
            }
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (self::isArchiveName($path)) {
                $this->fail('resume_archive_entry_unsafe', '压缩包包含嵌套压缩包');
            }
            if (!isset(self::ALLOWED_FILES[$extension])) {
                $this->fail('resume_archive_entry_unsupported', '压缩包包含不受支持的文件类型');
            }
            if (isset($seenPaths[$path])) {
                $this->fail('resume_archive_entry_unsafe', '压缩包包含重复条目路径');
            }
            $seenPaths[$path] = true;
            $size = filter_var($record['Size'] ?? null, FILTER_VALIDATE_INT);
            if ($size === false || $size < 1 || $size > $this->maxEntryBytes) {
                $this->fail('resume_archive_limit_exceeded', '压缩包单条目大小超过限制', 413);
            }
            $totalBytes += $size;
            if ($totalBytes > $this->maxExpandedBytes) {
                $this->fail('resume_archive_limit_exceeded', '压缩包展开总量超过限制', 413);
            }
            $entries[] = ['relative_path' => $path, 'byte_size' => $size, 'extension' => $extension];
        }
        if ($entries === [] || $totalBytes > max(1, $archiveBytes) * $this->maxCompressionRatio) {
            $this->fail('resume_archive_limit_exceeded', '压缩包压缩比超过限制', 413);
        }
        return $entries;
    }

    private function verifyExtractedEntries(string $workspace, array $entries, string $containerName): array
    {
        $realRoot = realpath($workspace);
        if ($realRoot === false) {
            $this->fail('resume_archive_invalid', '压缩包隔离目录无效');
        }
        $verified = [];
        foreach ($entries as $entry) {
            $path = $workspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['relative_path']);
            $realPath = realpath($path);
            $stat = $realPath === false ? false : lstat($realPath);
            if ($realPath === false || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR) || is_link($path) || !is_file($realPath) || $stat === false) {
                $this->fail('resume_archive_entry_unsafe', '压缩包条目未安全展开');
            }
            $size = filesize($realPath);
            if ($size !== $entry['byte_size'] || $size < 1 || $size > $this->maxEntryBytes) {
                $this->fail('resume_archive_limit_exceeded', '压缩包条目大小校验失败', 413);
            }
            $mime = $this->detectMime($realPath);
            if (!in_array($mime, self::ALLOWED_FILES[$entry['extension']], true)) {
                $this->fail('resume_archive_entry_unsupported', '压缩包条目内容与扩展名不一致');
            }
            $sha256 = hash_file('sha256', $realPath);
            if (!is_string($sha256)) {
                $this->fail('resume_archive_invalid', '压缩包条目摘要计算失败');
            }
            $verified[] = [
                'name' => basename($entry['relative_path']),
                'type' => $mime,
                'tmp_name' => $realPath,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) $size,
                'sha256' => $sha256,
                'container_original_name' => mb_substr(basename($containerName), 0, 255, 'UTF-8'),
                'archive_relative_path' => $entry['relative_path'],
                '_trusted_local' => true,
            ];
        }
        return $verified;
    }

    private function normalizedPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || strlen($path) > 1024 || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            $this->fail('resume_archive_entry_unsafe', '压缩包条目路径无效');
        }
        $segments = explode('/', $path);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            $this->fail('resume_archive_entry_unsafe', '压缩包条目路径无效');
        }
        return implode('/', $segments);
    }

    private function isSpecialEntry(array $record): bool
    {
        foreach (['Symbolic Link', 'Hard Link'] as $field) {
            if (trim((string) ($record[$field] ?? '')) !== '') {
                return true;
            }
        }
        $mode = trim((string) ($record['Mode'] ?? ''));
        return $mode !== '' && preg_match('/^[^d-]*[lbcps]/i', $mode) === 1;
    }

    private function run(array $command): string
    {
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $this->fail('resume_archive_tool_unavailable', '无法启动压缩包解包程序', 503);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeoutSeconds;
        $exitCode = null;
        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process);
                    $this->fail('resume_archive_timeout', '压缩包处理超时', 504);
                }
                usleep(20000);
            }
        } finally {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedCode = proc_close($process);
            if ($exitCode === null || $exitCode < 0) {
                $exitCode = $closedCode;
            }
        }
        if ($exitCode !== 0) {
            $this->fail('resume_archive_invalid', '压缩包读取或展开失败');
        }
        return $stdout;
    }

    private function createWorkspace(): string
    {
        $workspace = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'recruitment-archive-' . bin2hex(random_bytes(12));
        if (!mkdir($workspace, 0700) || !is_dir($workspace)) {
            $this->fail('resume_archive_workspace_failed', '无法创建压缩包隔离目录', 500);
        }
        return $workspace;
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            $this->fail('resume_archive_mime_unavailable', '服务器缺少文件类型检测能力', 500);
        }
        try {
            return strtolower(trim((string) finfo_file($finfo, $path)));
        } finally {
            finfo_close($finfo);
        }
    }

    private function configuredLimit(string $name, int $maximum): int
    {
        $configured = filter_var(getenv($name), FILTER_VALIDATE_INT);
        return $configured !== false && $configured > 0 ? min($configured, $maximum) : $maximum;
    }

    private function fail(string $code, string $message, int $status = 422): never
    {
        throw new RecruitmentAdminException($message, $status, ['code' => $code]);
    }
}
