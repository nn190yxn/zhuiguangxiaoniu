<?php
declare(strict_types=1);

final class WorkloadSourcePolicyException extends RuntimeException {
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int {
        return $this->statusCode;
    }
}

final class WorkloadSourcePolicyService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function policy(string $sourceCode): array {
        $sourceCode = $this->normalizeSourceCode($sourceCode);
        $stmt = $this->pdo->prepare(
            'SELECT source_code, source_kind, included_by_default, description '
            . 'FROM workload_source_policies WHERE source_code = ? LIMIT 1'
        );
        $stmt->execute([$sourceCode]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$policy) {
            throw new WorkloadSourcePolicyException('日报来源未登记：' . $sourceCode);
        }
        $kind = (string) ($policy['source_kind'] ?? '');
        if (!in_array($kind, ['production', 'synthetic'], true)) {
            throw new WorkloadSourcePolicyException('日报来源策略类型无效：' . $sourceCode, 500);
        }
        return [
            'source_code' => (string) $policy['source_code'],
            'source_kind' => $kind,
            'included_by_default' => (int) $policy['included_by_default'] === 1,
            'description' => (string) ($policy['description'] ?? ''),
        ];
    }

    public function defaultIncludedSources(): array {
        $stmt = $this->pdo->query(
            'SELECT source_code FROM workload_source_policies '
            . 'WHERE included_by_default = 1 ORDER BY source_code ASC'
        );
        return array_values(array_map(
            static fn(array $row): string => (string) $row['source_code'],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        ));
    }

    public static function includedByDefaultCondition(string $reportAlias = 'r'): string {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $reportAlias)) {
            throw new InvalidArgumentException('日报表别名格式无效');
        }
        return 'EXISTS (SELECT 1 FROM workload_source_policies source_policy '
            . 'WHERE source_policy.source_code = ' . $reportAlias . '.source '
            . 'AND source_policy.included_by_default = 1)';
    }

    private function normalizeSourceCode(string $sourceCode): string {
        $sourceCode = strtolower(trim($sourceCode));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,15}$/', $sourceCode)) {
            throw new WorkloadSourcePolicyException('日报来源格式无效');
        }
        return $sourceCode;
    }
}
