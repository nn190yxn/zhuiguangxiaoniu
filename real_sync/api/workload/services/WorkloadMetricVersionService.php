<?php
declare(strict_types=1);

final class WorkloadMetricVersionException extends RuntimeException {}

final class WorkloadMetricVersionService {
    private PDO $pdo;
    private ?array $currentVersion = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function current(): array {
        if ($this->currentVersion !== null) {
            return $this->currentVersion;
        }
        $stmt = $this->pdo->query(
            'SELECT id, version_code, effective_at, source_policy_json, obligation_policy_json, '
            . 'effective_value_policy_json, description, created_by_staff_id, created_at, '
            . 'CURRENT_TIMESTAMP AS generated_at '
            . 'FROM workload_metric_versions '
            . 'WHERE effective_at <= CURRENT_TIMESTAMP '
            . 'ORDER BY effective_at DESC, id DESC LIMIT 1'
        );
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new WorkloadMetricVersionException('当前时间没有已生效的工作量统计口径版本');
        }
        $this->currentVersion = $this->normalizeVersion($version);
        return $this->currentVersion;
    }

    public function responseMetadata(array $filters = [], array $sourceScope = []): array {
        $version = $this->current();
        return [
            'metric_version' => $version['version_code'],
            'metric_version_id' => $version['id'],
            'generated_at' => $version['generated_at'],
            'filters' => $filters,
            'source_scope' => array_values($sourceScope),
            'metric_policy' => [
                'source' => $version['source_policy'],
                'obligation' => $version['obligation_policy'],
                'effective_value' => $version['effective_value_policy'],
                'description' => $version['description'],
            ],
        ];
    }

    public function cacheKey(string $namespace, array $filters, array $permissionScope): string {
        $namespace = trim($namespace);
        if ($namespace === '') {
            throw new InvalidArgumentException('缓存命名空间不能为空');
        }
        $payload = [
            'metric_version' => $this->current()['version_code'],
            'filters' => $this->sortRecursively($filters),
            'permission_scope' => $this->sortRecursively($permissionScope),
        ];
        return 'workload:' . $namespace . ':' . hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    public function exportMetadata(array $filters, array $sourceScope): array {
        return $this->responseMetadata($filters, $sourceScope);
    }

    public function auditContext(): array {
        $version = $this->current();
        return [
            'metric_version' => $version['version_code'],
            'metric_version_id' => $version['id'],
        ];
    }

    private function normalizeVersion(array $version): array {
        $code = trim((string) ($version['version_code'] ?? ''));
        if ($code === '' || strlen($code) > 32) {
            throw new WorkloadMetricVersionException('工作量统计口径版本编码无效');
        }
        return [
            'id' => (int) $version['id'],
            'version_code' => $code,
            'effective_at' => (string) $version['effective_at'],
            'source_policy' => $this->decodePolicy((string) $version['source_policy_json'], '来源'),
            'obligation_policy' => $this->decodePolicy((string) $version['obligation_policy_json'], '义务'),
            'effective_value_policy' => $this->decodePolicy((string) $version['effective_value_policy_json'], '有效值'),
            'description' => (string) ($version['description'] ?? ''),
            'created_by_staff_id' => isset($version['created_by_staff_id']) ? (int) $version['created_by_staff_id'] : null,
            'created_at' => (string) ($version['created_at'] ?? ''),
            'generated_at' => (string) ($version['generated_at'] ?? ''),
        ];
    }

    private function decodePolicy(string $json, string $label): array {
        try {
            $policy = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new WorkloadMetricVersionException($label . '口径策略 JSON 无效', 0, $error);
        }
        if (!is_array($policy)) {
            throw new WorkloadMetricVersionException($label . '口径策略必须是对象');
        }
        return $policy;
    }

    private function sortRecursively(array $value): array {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursively($item);
            }
        }
        unset($item);
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }
}
