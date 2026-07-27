<?php
declare(strict_types=1);

final class WorkloadAnalyticsCacheService {
    public const DEFAULT_TTL_SECONDS = 60;
    private const MAX_ENTRY_BYTES = 5242880;

    private string $directory;
    private int $ttlSeconds;

    public function __construct(?string $directory = null, int $ttlSeconds = self::DEFAULT_TTL_SECONDS) {
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('缓存有效期必须大于零');
        }
        $this->directory = $directory ?? dirname(__DIR__, 3) . '/data/workload-analytics-cache';
        $this->ttlSeconds = $ttlSeconds;
    }

    public function key(
        string $namespace,
        array $filters,
        array $permissionScope,
        string $metricVersion
    ): string {
        $namespace = trim($namespace);
        $metricVersion = trim($metricVersion);
        if ($namespace === '' || $metricVersion === '') {
            throw new InvalidArgumentException('缓存命名空间和统计口径版本不能为空');
        }
        $payload = [
            'namespace' => $namespace,
            'date_from' => (string) ($filters['date_from'] ?? ''),
            'date_to' => (string) ($filters['date_to'] ?? ''),
            'permission_scope' => $permissionScope,
            'sources' => $filters['sources'] ?? [],
            'store_ids' => $filters['store_ids'] ?? [],
            'role_codes' => $filters['role_codes'] ?? [],
            'staff_ids' => $filters['staff_ids'] ?? [],
            'metric_codes' => $filters['metric_codes'] ?? [],
            'report_statuses' => $filters['report_statuses'] ?? [],
            'audit_statuses' => $filters['audit_statuses'] ?? [],
            'metric_version' => $metricVersion,
        ];
        return hash('sha256', $this->encode($this->canonicalize($payload)));
    }

    public function get(string $key): mixed {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $entry = json_decode((string) @file_get_contents($path), true);
        if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) <= time()) {
            @unlink($path);
            return null;
        }
        return $entry['value'] ?? null;
    }

    public function put(string $key, mixed $value, array $dependencies): bool {
        $entry = $this->encode([
            'expires_at' => time() + $this->ttlSeconds,
            'dependencies' => $this->canonicalize($dependencies),
            'value' => $value,
        ]);
        if (strlen($entry) > self::MAX_ENTRY_BYTES) {
            return false;
        }
        $this->ensureDirectory();
        $path = $this->path($key);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $entry, LOCK_EX) === false) {
            return false;
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }
        return true;
    }

    public function dependencies(array $filters, array $permissionScope, string $metricVersion): array {
        return [
            'date_from' => (string) ($filters['date_from'] ?? ''),
            'date_to' => (string) ($filters['date_to'] ?? ''),
            'store_ids' => $this->effectiveIds($filters['store_ids'] ?? [], $permissionScope, 'store_ids', 'stores'),
            'staff_ids' => $this->effectiveIds($filters['staff_ids'] ?? [], $permissionScope, 'staff_id', 'staff'),
            'role_codes' => array_values($filters['role_codes'] ?? []),
            'metric_codes' => array_values($filters['metric_codes'] ?? []),
            'sources' => array_values($filters['sources'] ?? []),
            'metric_version' => $metricVersion,
        ];
    }

    public function invalidate(array $change): int {
        if (!is_dir($this->directory)) {
            return 0;
        }
        $invalidated = 0;
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $entry = json_decode((string) @file_get_contents($path), true);
            if (!is_array($entry) || $this->affected($entry['dependencies'] ?? [], $change)) {
                if (@unlink($path)) {
                    $invalidated++;
                }
            }
        }
        return $invalidated;
    }

    private function affected(array $dependencies, array $change): bool {
        $changeFrom = (string) ($change['date_from'] ?? $change['date'] ?? '');
        $changeTo = (string) ($change['date_to'] ?? $change['date'] ?? $changeFrom);
        $cacheFrom = (string) ($dependencies['date_from'] ?? '');
        $cacheTo = (string) ($dependencies['date_to'] ?? '');
        if ($changeFrom !== '' && $changeTo !== '' && $cacheFrom !== '' && $cacheTo !== '') {
            if ($changeTo < $cacheFrom || $changeFrom > $cacheTo) {
                return false;
            }
        }
        if (!$this->dimensionMatches($dependencies, $change, 'store_ids', 'store_id')) return false;
        if (!$this->dimensionMatches($dependencies, $change, 'staff_ids', 'staff_id')) return false;
        if (!$this->dimensionMatches($dependencies, $change, 'role_codes', 'role_code')) return false;
        if (!$this->dimensionMatches($dependencies, $change, 'metric_codes', 'metric_code')) return false;
        if (!$this->dimensionMatches($dependencies, $change, 'sources', 'source')) return false;
        if (isset($change['metric_version']) && (string) $change['metric_version'] !== '') {
            return hash_equals((string) ($dependencies['metric_version'] ?? ''), (string) $change['metric_version']);
        }
        return true;
    }

    private function dimensionMatches(array $dependencies, array $change, string $plural, string $singular): bool {
        $cached = array_map('strval', $dependencies[$plural] ?? []);
        $changed = $change[$plural] ?? (array_key_exists($singular, $change) ? [$change[$singular]] : []);
        $changed = array_map('strval', is_array($changed) ? $changed : [$changed]);
        return $cached === [] || $changed === [] || array_intersect($cached, $changed) !== [];
    }

    private function effectiveIds(array $requested, array $scope, string $scopeField, string $scopeType): array {
        $requested = array_values($requested);
        if (($scope['scope_type'] ?? '') !== $scopeType) {
            return $requested;
        }
        $allowed = $scopeField === 'staff_id'
            ? [(int) ($scope[$scopeField] ?? 0)]
            : array_values($scope[$scopeField] ?? []);
        if ($requested === []) {
            return $allowed;
        }
        $intersection = array_values(array_intersect(array_map('strval', $requested), array_map('strval', $allowed)));
        return $intersection === [] ? ['__empty_scope__'] : $intersection;
    }

    private function canonicalize(array $value): array {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }
        unset($item);
        if (array_is_list($value)) {
            usort($value, static fn(mixed $left, mixed $right): int => strcmp((string) $left, (string) $right));
        } else {
            ksort($value);
        }
        return $value;
    }

    private function encode(array $value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function ensureDirectory(): void {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('无法创建工作量统计缓存目录');
        }
    }

    private function path(string $key): string {
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
            throw new InvalidArgumentException('缓存键格式无效');
        }
        return $this->directory . '/' . $key . '.json';
    }
}
