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
        $risks = [];
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
            $this->validateSql($version, $sql, $compatibility, $issues, $risks);
        }

        return [
            'compatible' => $issues === [],
            'checked_versions' => $versions,
            'issues' => $issues,
            'risks' => $risks,
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

    private function validateSql(string $version, string $sql, array $contract, array &$issues, array &$risks): void
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

        $riskStart = count($risks);
        $this->classifySqlRisks($version, $analysisSql, $risks);
        $this->validateRiskDeclarations(
            $version,
            array_slice($risks, $riskStart),
            $contract,
            $issues
        );
    }

    private function validateRiskDeclarations(string $version, array $risks, array $contract, array &$issues): void
    {
        if ($risks === []) {
            return;
        }

        $declaration = is_array($contract['risk_declaration'] ?? null)
            ? $contract['risk_declaration']
            : [];
        foreach ($risks as $risk) {
            foreach (['compatibility_window', 'write_adapter', 'estimated_affected_rows', 'lock_risk', 'execution_strategy'] as $field) {
                if ($this->hasDeclarationValue($declaration[$field] ?? null)) {
                    continue;
                }
                $this->appendMissingRiskDeclaration($issues, $version, $risk, $field);
            }
            if (!$this->hasDeclarationValue($declaration['rollback_plan'] ?? null)
                && !$this->hasDeclarationValue($declaration['forward_fix'] ?? null)) {
                $this->appendMissingRiskDeclaration($issues, $version, $risk, 'rollback_or_forward_fix');
            }
        }
    }

    private function hasDeclarationValue(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0;
        }
        return is_string($value) && trim($value) !== '';
    }

    private function appendMissingRiskDeclaration(array &$issues, string $version, array $risk, string $field): void
    {
        $issues[] = [
            'version' => $version,
            'type' => 'missing_risk_declaration',
            'sql_type' => $risk['type'],
            'target' => $risk['target'],
            'statement' => $risk['statement'],
            'missing' => $field,
        ];
    }

    private function classifySqlRisks(string $version, string $sql, array &$risks): void
    {
        foreach ($this->sqlStatements($sql) as $index => $statement) {
            $statementNumber = $index + 1;
            $masked = $this->maskQuotedValues($statement);
            if (preg_match('/^\s*ALTER\s+TABLE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $masked, $alterMatch)) {
                $table = $alterMatch[1];
                preg_match_all(
                    '/\bMODIFY\s+(?:COLUMN\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
                    $masked,
                    $modifyMatches
                );
                foreach ($modifyMatches[1] as $column) {
                    $this->appendRisk($risks, [
                        'version' => $version,
                        'type' => 'modify_column',
                        'target' => $table . '.' . $column,
                        'statement' => $statementNumber,
                    ]);
                    $this->appendRisk($risks, [
                        'version' => $version,
                        'type' => 'table_rewrite',
                        'target' => $table,
                        'statement' => $statementNumber,
                        'source' => 'modify_column',
                    ]);
                }
                if (preg_match('/\bENGINE\s*=/i', $masked)) {
                    $this->appendRisk($risks, [
                        'version' => $version,
                        'type' => 'table_rewrite',
                        'target' => $table,
                        'statement' => $statementNumber,
                        'source' => 'engine_change',
                    ]);
                }
                if (preg_match('/\bCONVERT\s+TO\s+CHARACTER\s+SET\b/i', $masked)) {
                    $this->appendRisk($risks, [
                        'version' => $version,
                        'type' => 'table_rewrite',
                        'target' => $table,
                        'statement' => $statementNumber,
                        'source' => 'charset_conversion',
                    ]);
                }
            }

            if (preg_match('/^\s*UPDATE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $masked, $updateMatch)) {
                $table = $updateMatch[1];
                $this->appendRisk($risks, [
                    'version' => $version,
                    'type' => 'data_update',
                    'target' => $table,
                    'statement' => $statementNumber,
                ]);
                if (preg_match('/\bSET\s+(.*?)(?:\bWHERE\b|\bORDER\s+BY\b|\bLIMIT\b|$)/is', $masked, $setMatch)) {
                    preg_match_all(
                        '/(?:`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*\.\s*)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*=/i',
                        $setMatch[1],
                        $assignmentMatches
                    );
                    $stateColumns = array_filter(
                        array_values(array_unique($assignmentMatches[1])),
                        static fn(string $column): bool => preg_match('/(?:status|state)/i', $column) === 1
                    );
                    foreach ($stateColumns as $column) {
                        $this->appendRisk($risks, [
                            'version' => $version,
                            'type' => 'state_backfill',
                            'target' => $table . '.' . $column,
                            'statement' => $statementNumber,
                        ]);
                    }
                }
            }

            if (preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $masked, $insertMatch)) {
                $this->appendRisk($risks, [
                    'version' => $version,
                    'type' => 'data_insert',
                    'target' => $insertMatch[1],
                    'statement' => $statementNumber,
                ]);
            }
        }
    }

    private function appendRisk(array &$risks, array $risk): void
    {
        foreach ($risks as $existing) {
            if ($existing === $risk) {
                return;
            }
        }
        $risks[] = $risk;
    }

    private function maskQuotedValues(string $sql): string
    {
        return preg_replace_callback(
            '/([\'\"])(?:\\.|(?!\1).)*\1/s',
            static fn(array $match): string => str_repeat(' ', strlen($match[0])),
            $sql
        ) ?? $sql;
    }

    private function sqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $index++;
                    } elseif ($index === 0 || $sql[$index - 1] !== '\\') {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
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
