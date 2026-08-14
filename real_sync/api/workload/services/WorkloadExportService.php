<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadStoreAnalyticsService.php';
require_once __DIR__ . '/WorkloadMetricSelectionService.php';
require_once __DIR__ . '/WorkloadMetricVersionService.php';

final class WorkloadExportException extends RuntimeException {
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int {
        return $this->statusCode;
    }
}

final class WorkloadExportService {
    private PDO $pdo;
    private WorkloadMetricVersionService $metricVersion;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->metricVersion = new WorkloadMetricVersionService($pdo);
    }

    public function generate(string $exportType, array $input, array $context): array {
        if ($exportType === 'store_completion') {
            $statistics = (new WorkloadStoreAnalyticsService($this->pdo))->storeCompletion($input, $context);
            return $this->result($exportType, $statistics, $this->storeCompletionRows($statistics));
        }
        if ($exportType === 'metric_selection') {
            $statistics = (new WorkloadMetricSelectionService($this->pdo))->metricSelection($input, $context);
            return $this->result($exportType, $statistics, $this->metricSelectionRows($statistics));
        }
        if (in_array($exportType, ['staff_full_data', 'metric_full_dimension'], true)) {
            $analytics = new WorkloadAnalyticsQueryService($this->pdo);
            $facts = $analytics->facts($input, $context);
            $metadata = $this->metricVersion->responseMetadata($facts['filters'], $facts['filters']['sources']);
            $statistics = array_merge($metadata, [
                'filters' => $facts['filters'],
                'permission_scope' => $facts['permission_scope'],
                'rows' => $facts['rows'],
            ]);
            $table = $exportType === 'staff_full_data'
                ? $this->staffFullDataRows($facts['rows'])
                : $this->metricFullDimensionRows($facts['rows']);
            return $this->result($exportType, $statistics, $table);
        }
        throw new WorkloadExportException('导出类型无效');
    }

    public function plan(string $exportType, array $input, array $context): array {
        if (!in_array($exportType, ['staff_full_data', 'metric_full_dimension'], true)) {
            return $this->generate($exportType, $input, $context);
        }
        $analytics = new WorkloadAnalyticsQueryService($this->pdo);
        $filters = $analytics->normalizeFilters($input);
        $permissionScope = $analytics->permissionScope($context);
        if (empty($permissionScope['can_export'])) {
            throw new WorkloadExportException('当前账号无导出权限', 403);
        }
        $metadata = $this->metricVersion->responseMetadata($filters, $filters['sources']);
        $statistics = array_merge($metadata, [
            'filters' => $filters,
            'permission_scope' => $permissionScope,
        ]);
        $table = $exportType === 'staff_full_data'
            ? $this->staffFullDataRows([])
            : $this->metricFullDimensionRows([]);
        $rowCount = $analytics->countFacts($filters, $permissionScope);
        $table['rows'] = $this->exportFactRows(
            $analytics->iterateFacts($filters, $permissionScope),
            $exportType
        );
        return $this->result($exportType, $statistics, $table, $rowCount);
    }

    public function csv(array $export): string {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new WorkloadExportException('无法创建导出文件', 500);
        }
        $this->writeCsv($export, $stream);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new WorkloadExportException('读取导出文件失败', 500);
        }
        return $csv;
    }

    public function writeCsv(array $export, mixed $stream): int {
        if (!is_resource($stream)) {
            throw new WorkloadExportException('导出目标流无效', 500);
        }
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($export['metadata_rows'] as $row) {
            fputcsv($stream, array_map([$this, 'csvValue'], $row));
        }
        fputcsv($stream, []);
        fputcsv($stream, array_map([$this, 'csvValue'], $export['headers']));
        $rowCount = 0;
        foreach ($export['rows'] as $row) {
            fputcsv($stream, array_map([$this, 'csvValue'], $row));
            $rowCount++;
        }
        return $rowCount;
    }

    private function exportFactRows(iterable $facts, string $exportType): iterable {
        foreach ($facts as $row) {
            if ($exportType === 'staff_full_data') {
                yield $this->factExportRow($row, [
                    'business_date', 'store_name', 'staff_name', 'role_code', 'metric_code',
                    'raw_value', 'pending_value', 'effective_value', 'rejected_value',
                    'report_status', 'audit_status', 'evidence_count', 'source', 'daily_target_points',
                    'daily_effective_points', 'daily_gap_points', 'settlement_status', 'penalty_amount', 'penalty_status',
                ]);
                continue;
            }
            yield [
                (string) ($row['business_date'] ?? ''),
                (string) ($row['store_name'] ?? ''),
                (int) ($row['store_id'] ?? 0),
                (string) ($row['staff_name'] ?? ''),
                (int) ($row['staff_id'] ?? 0),
                (string) ($row['role_code'] ?? ''),
                (string) ($row['metric_code'] ?? ''),
                (string) ($row['metric_name'] ?? ''),
                (string) ($row['unit'] ?? ''),
                (string) ($row['report_status'] ?? ''),
                (string) ($row['audit_status'] ?? ''),
                (float) ($row['raw_value'] ?? 0),
                (float) ($row['pending_value'] ?? 0),
                (float) ($row['effective_value'] ?? 0),
                (float) ($row['rejected_value'] ?? 0),
                (int) ($row['evidence_count'] ?? 0),
                (string) ($row['source'] ?? ''),
                (float) ($row['daily_target_points'] ?? 0),
                (float) ($row['daily_effective_points'] ?? 0),
                (float) ($row['daily_gap_points'] ?? 0),
                (string) ($row['settlement_status'] ?? ''),
                (float) ($row['penalty_amount'] ?? 0),
                (string) ($row['penalty_status'] ?? ''),
            ];
        }
    }

    private function storeCompletionRows(array $statistics): array {
        $groups = [];
        foreach ($statistics['status_details'] ?? [] as $row) {
            $key = implode(':', [
                (string) ($row['business_date'] ?? ''),
                (int) ($row['store_id'] ?? 0),
                (string) ($row['role_code'] ?? ''),
            ]);
            $groups[$key] ??= [
                'business_date' => (string) ($row['business_date'] ?? ''),
                'store_name' => (string) ($row['store_name'] ?? ''),
                'role_code' => (string) ($row['role_code'] ?? ''),
                'required' => 0,
                'submitted' => 0,
                'draft' => 0,
                'missing' => 0,
                'locked_missing' => 0,
                'corrected' => 0,
            ];
            $groups[$key]['required']++;
            $status = (string) ($row['completion_status'] ?? 'missing');
            if (array_key_exists($status, $groups[$key])) {
                $groups[$key][$status]++;
            }
        }
        ksort($groups);
        $rows = [];
        foreach ($groups as $group) {
            $completed = $group['submitted'] + $group['corrected'];
            $rows[] = [
                $group['business_date'], $group['store_name'], $group['role_code'], $group['required'],
                $group['submitted'], $group['draft'], $group['missing'], $group['locked_missing'],
                $group['corrected'], $group['required'] > 0 ? round($completed / $group['required'], 4) : 0,
            ];
        }
        return [
            'headers' => ['日期', '门店', '岗位', '应交', '已提交', '草稿', '缺交', '锁定缺交', '管理更正', '完成率'],
            'rows' => $rows,
            'field_description' => '一行表示一个日期、门店和岗位的日报义务状态汇总；完成率=(已提交+管理更正)/应交',
        ];
    }

    private function metricSelectionRows(array $statistics): array {
        $rows = [];
        foreach ($statistics['project_summaries'] ?? [] as $row) {
            $rows[] = [
                (string) ($row['metric_code'] ?? ''),
                (string) ($row['metric_name'] ?? ''),
                (string) ($row['role_code'] ?? ''),
                (string) ($row['unit'] ?? ''),
                (int) ($row['sample_size'] ?? 0),
                (float) ($row['selection_rate']['value'] ?? 0),
                (float) ($row['effective_selection_rate']['value'] ?? 0),
                (float) ($row['staff_coverage']['value'] ?? 0),
                (float) ($row['store_coverage']['value'] ?? 0),
                (float) ($row['raw_value'] ?? 0),
                (float) ($row['effective_value'] ?? 0),
            ];
        }
        return [
            'headers' => ['项目编码', '项目名称', '岗位', '单位', '样本量', '选取率', '有效选取率', '员工覆盖率', '门店覆盖率', '原始值', '有效值'],
            'rows' => $rows,
            'field_description' => '一行表示权限和筛选范围内一个岗位项目的选取、覆盖与数值汇总',
        ];
    }

    private function staffFullDataRows(array $facts): array {
        $rows = [];
        foreach ($facts as $row) {
            $rows[] = $this->factExportRow($row, [
                'business_date', 'store_name', 'staff_name', 'role_code', 'metric_code',
                'raw_value', 'pending_value', 'effective_value', 'rejected_value',
                'report_status', 'audit_status', 'evidence_count', 'source', 'daily_target_points',
                'daily_effective_points', 'daily_gap_points', 'settlement_status', 'penalty_amount', 'penalty_status',
            ]);
        }
        return [
            'headers' => ['日期', '门店', '员工', '岗位', '项目编码', '原始值', '待审核值', '有效值', '拒绝值', '日报状态', '审核状态', '凭证数', '来源', '每日目标点数', '每日有效点数', '每日差额', '每日结算状态', '处罚金额', '处罚状态'],
            'rows' => $rows,
            'field_description' => '一行表示一个日期、门店、员工、岗位和项目组合，包含日报、凭证和审核值',
        ];
    }

    private function metricFullDimensionRows(array $facts): array {
        $rows = [];
        foreach ($facts as $row) {
            $rows[] = [
                (string) ($row['business_date'] ?? ''),
                (string) ($row['store_name'] ?? ''),
                (int) ($row['store_id'] ?? 0),
                (string) ($row['staff_name'] ?? ''),
                (int) ($row['staff_id'] ?? 0),
                (string) ($row['role_code'] ?? ''),
                (string) ($row['metric_code'] ?? ''),
                (string) ($row['metric_name'] ?? ''),
                (string) ($row['unit'] ?? ''),
                (string) ($row['report_status'] ?? ''),
                (string) ($row['audit_status'] ?? ''),
                (float) ($row['raw_value'] ?? 0),
                (float) ($row['pending_value'] ?? 0),
                (float) ($row['effective_value'] ?? 0),
                (float) ($row['rejected_value'] ?? 0),
                (int) ($row['evidence_count'] ?? 0),
                (string) ($row['source'] ?? ''),
                (float) ($row['daily_target_points'] ?? 0),
                (float) ($row['daily_effective_points'] ?? 0),
                (float) ($row['daily_gap_points'] ?? 0),
                (string) ($row['settlement_status'] ?? ''),
                (float) ($row['penalty_amount'] ?? 0),
                (string) ($row['penalty_status'] ?? ''),
            ];
        }
        return [
            'headers' => ['日期', '门店', '门店ID', '员工', '员工ID', '岗位', '项目编码', '项目名称', '单位', '日报状态', '审核状态', '原始值', '待审核值', '有效值', '拒绝值', '凭证数', '来源', '每日目标点数', '每日有效点数', '每日差额', '每日结算状态', '处罚金额', '处罚状态'],
            'rows' => $rows,
            'field_description' => '一行表示一个日期、门店、员工、岗位和项目组合的全维度事实明细',
        ];
    }

    private function factExportRow(array $row, array $fields): array {
        return array_map(static function (string $field) use ($row): mixed {
            return $row[$field] ?? '';
        }, $fields);
    }

    private function result(string $exportType, array $statistics, array $table, ?int $rowCount = null): array {
        if (empty($statistics['permission_scope']['can_export'])) {
            throw new WorkloadExportException('当前账号无导出权限', 403);
        }
        $filters = $statistics['filters'] ?? [];
        return [
            'export_type' => $exportType,
            'filename' => 'workload-' . str_replace('_', '-', $exportType) . '-' . date('Ymd-His') . '.csv',
            'headers' => $table['headers'],
            'rows' => $table['rows'],
            'row_count' => $rowCount ?? count($table['rows']),
            'permission_scope' => $statistics['permission_scope'],
            'metric_version_id' => isset($statistics['metric_version_id']) ? (int) $statistics['metric_version_id'] : null,
            'metadata_rows' => [
                ['导出类型', $exportType],
                ['生成时间', (string) ($statistics['generated_at'] ?? '')],
                ['统计口径版本', (string) ($statistics['metric_version'] ?? '')],
                ['查询条件', json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
                ['字段说明', $table['field_description']],
            ],
        ];
    }

    private function csvValue(mixed $value): string {
        $value = (string) ($value ?? '');
        return $value !== '' && preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }
}
