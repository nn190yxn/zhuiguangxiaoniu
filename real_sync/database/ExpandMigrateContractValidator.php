<?php
declare(strict_types=1);

final class ExpandMigrateContractValidator
{
    private $sqlLoader;

    public function __construct(
        private array $catalog,
        private string $migrationRoot,
        ?callable $sqlLoader = null
    ) {
        $this->sqlLoader = $sqlLoader ?? static fn(string $path): string => (string)file_get_contents($path);
    }

    public function validate(array $versions = []): array
    {
        $versions = $this->versions($versions);
        $issues = [];
        foreach ($versions as $version) {
            $entry = $this->catalog[$version];
            $compatibility = $entry['compatibility'] ?? [];
            $this->validateContract($version, $compatibility, $issues);

            $path = rtrim($this->migrationRoot, '/') . '/' . basename((string)$entry['sql_file']);
            $sql = ($this->sqlLoader)($path);
            if (!hash_equals((string)$entry['sql_checksum'], hash('sha256', $sql))) {
                $issues[] = ['version' => $version, 'type' => 'checksum_mismatch'];
                continue;
            }
            $this->validateSql($version, $sql, $compatibility, $issues);
        }

        return [
            'compatible' => $issues === [],
            'checked_versions' => $versions,
            'issues' => $issues,
            'policy' => 'expand-migrate-contract',
        ];
    }

    private function validateContract(string $version, array $contract, array &$issues): void
    {
        foreach (['required_readers', 'required_writers'] as $field) {
            $versions = array_values(array_unique(array_map('strval', $contract[$field] ?? [])));
            if ($versions !== ['N', 'N-1']) {
                $issues[] = ['version' => $version, 'type' => 'invalid_' . $field];
            }
        }
        if (($contract['phase'] ?? '') !== 'expand') {
            $issues[] = ['version' => $version, 'type' => 'invalid_compatibility_phase'];
        }
        if (($contract['rollback_strategy'] ?? '') !== 'preserving') {
            $issues[] = ['version' => $version, 'type' => 'invalid_rollback_strategy'];
        }
        if (($contract['validation_status'] ?? '') !== 'validated_task_5_2') {
            $issues[] = ['version' => $version, 'type' => 'compatibility_not_validated'];
        }
        if (!is_array($contract['write_adapters'] ?? null) || !is_array($contract['state_changes'] ?? null)) {
            $issues[] = ['version' => $version, 'type' => 'invalid_compatibility_declaration'];
            return;
        }

        $targets = [];
        foreach ($contract['state_changes'] as $change) {
            $target = (string)($change['target'] ?? '');
            if ($target === '' || isset($targets[$target])) {
                $issues[] = ['version' => $version, 'type' => 'invalid_state_change_target', 'target' => $target];
                continue;
            }
            $targets[$target] = true;
            $introducedValues = array_values(array_unique(array_map('strval', $change['introduced_values'] ?? [])));
            if ($introducedValues === []) {
                continue;
            }
            $downgradeMap = $change['downgrade_map'] ?? [];
            foreach ($introducedValues as $value) {
                if (!isset($downgradeMap[$value]) || trim((string)$downgradeMap[$value]) === '') {
                    $issues[] = [
                        'version' => $version,
                        'type' => 'missing_state_downgrade',
                        'target' => $target . ':' . $value,
                    ];
                }
            }
            if (trim((string)($change['feature_flag'] ?? '')) === ''
                || ($change['enabled_during_compatibility'] ?? null) !== false) {
                $issues[] = ['version' => $version, 'type' => 'missing_feature_flag_gate', 'target' => $target];
            }
        }
    }

    private function validateSql(string $version, string $sql, array $contract, array &$issues): void
    {
        $analysisSql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        $analysisSql = preg_replace('/^\s*--.*$/m', '', $analysisSql) ?? $analysisSql;
        $destructivePatterns = [
            'drop_table' => '/\bDROP\s+TABLE\b/i',
            'drop_column' => '/\bDROP\s+(?:COLUMN\s+)?(?!TABLE\b|INDEX\b|KEY\b|CONSTRAINT\b|PRIMARY\b|FOREIGN\b|CHECK\b)`?[a-zA-Z_][a-zA-Z0-9_]*`?/i',
            'rename_table' => '/\bRENAME\s+TABLE\b|\bALTER\s+TABLE\b[^;\r\n]*\bRENAME\s+TO\b/i',
            'rename_column' => '/\bRENAME\s+COLUMN\b/i',
        ];
        foreach ($destructivePatterns as $type => $pattern) {
            if (preg_match($pattern, $analysisSql)) {
                $issues[] = ['version' => $version, 'type' => $type];
            }
        }
        preg_match_all(
            '/\bCHANGE\s+(?:COLUMN\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
            $analysisSql,
            $changeMatches,
            PREG_SET_ORDER
        );
        foreach ($changeMatches as $changeMatch) {
            if ($changeMatch[1] !== $changeMatch[2]) {
                $issues[] = ['version' => $version, 'type' => 'rename_column'];
            }
        }

        $normalizedSql = str_replace("''", '__SQL_QUOTE__', $analysisSql);
        preg_match_all(
            '/ALTER\s+TABLE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+ADD\s+(?:COLUMN\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+([^;\r\n]+)/i',
            $normalizedSql,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $column = strtoupper($match[2]);
            if (in_array($column, ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK'], true)) {
                continue;
            }
            $definition = $match[3];
            $hasDefault = preg_match('/\bDEFAULT\b/i', $definition) === 1;
            $isNullable = preg_match('/\bNULL\b/i', $definition) === 1
                && preg_match('/\bNOT\s+NULL\b/i', $definition) !== 1;
            $target = $match[1] . '.' . $match[2];
            if (!$hasDefault && !$isNullable && !$this->hasWriteAdapter($contract, $target)) {
                $issues[] = ['version' => $version, 'type' => 'unsafe_added_column', 'target' => $target];
            }
        }
    }

    private function hasWriteAdapter(array $contract, string $target): bool
    {
        $adapter = $contract['write_adapters'][$target] ?? null;
        if (!is_array($adapter) || ($adapter['preserves_data'] ?? false) !== true) {
            return false;
        }
        return array_values(array_unique(array_map('strval', $adapter['writers'] ?? []))) === ['N', 'N-1'];
    }

    private function versions(array $versions): array
    {
        if ($versions === []) {
            return array_map('strval', array_keys($this->catalog));
        }
        $versions = array_values(array_unique(array_map('strval', $versions)));
        foreach ($versions as $version) {
            if (!isset($this->catalog[$version])) {
                throw new InvalidArgumentException('Unknown migration version: ' . $version);
            }
        }
        return $versions;
    }
}
