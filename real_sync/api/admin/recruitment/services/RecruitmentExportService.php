<?php

declare(strict_types=1);

require_once __DIR__ . '/ResumeFieldNormalizer.php';

final class RecruitmentExportService
{
    public const COLUMNS = [
        '批次编号', '招聘需求编号', '门店', '应聘岗位', '姓名', '手机号', '来源文件', '当前或最近岗位', '工作年限', '行业经历', '经验摘要',
        '教育与专业', '技能与证书', '简历亮点', '命中关键词', '硬性条件状态', '人工核验项', '匹配分', '等级', '建议理由', '联系状态', '联系备注',
    ];

    private PDO $pdo;
    private RecruitmentPermissionService $permissions;
    private string $storageRoot;

    public function __construct(PDO $pdo, RecruitmentPermissionService $permissions, ?string $storageRoot = null)
    {
        $this->pdo = $pdo;
        $this->permissions = $permissions;
        $configured = trim((string) ($storageRoot ?? getenv('RECRUITMENT_EXPORT_STORAGE_ROOT') ?: ''));
        $this->storageRoot = $configured !== '' ? rtrim($configured, DIRECTORY_SEPARATOR) : dirname(__DIR__, 5) . '/.private/recruitment-exports';
    }

    public function create(array $query, array $scope, int $staffId): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RecruitmentAdminException('服务器缺少 XLSX 生成组件', 503);
        }
        $rows = $this->queryRows($query, $scope);
        $requirementIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['requirement_id'], $rows)));
        $exportNo = 'REX' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
        $fileName = '招聘候选人-' . date('Ymd-His') . '.xlsx';
        $fileKey = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.xlsx';
        $storedQuery = $query;
        $storedQuery['authorized_requirement_ids'] = $requirementIds;
        $columnHash = hash('sha256', json_encode(self::COLUMNS, JSON_UNESCAPED_UNICODE));
        $sortHash = hash('sha256', 'requirement_no:asc,effective_grade:asc,total_score:desc,application_id:asc');
        $requirementId = count($requirementIds) === 1 ? $requirementIds[0] : null;
        $batchId = isset($query['batch_id']) && (int) $query['batch_id'] > 0 ? (int) $query['batch_id'] : null;
        $scopeType = $batchId ? 'batch' : ($requirementId ? 'requirement' : 'all');
        $stmt = $this->pdo->prepare(
            "INSERT INTO recruitment_export_jobs (export_no, requirement_id, batch_id, workbook_scope, status, query_json, column_schema_hash, sort_schema_hash, file_key, file_name, row_count, created_by, started_at, expires_at) VALUES (?, ?, ?, ?, 'running', ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
        );
        $stmt->execute([$exportNo, $requirementId, $batchId, $scopeType, json_encode($storedQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $columnHash, $sortHash, $fileKey, $fileName, count($rows), $staffId ?: null]);
        $jobId = (int) $this->pdo->lastInsertId();
        try {
            $path = $this->safeTarget($fileKey);
            $this->writeWorkbook($path, $rows);
            chmod($path, 0600);
            $done = $this->pdo->prepare("UPDATE recruitment_export_jobs SET status = 'completed', completed_at = NOW() WHERE id = ?");
            $done->execute([$jobId]);
        } catch (Throwable $error) {
            $failed = $this->pdo->prepare("UPDATE recruitment_export_jobs SET status = 'failed', failed_at = NOW(), failure_message = ? WHERE id = ?");
            $failed->execute([mb_substr($error->getMessage(), 0, 1000, 'UTF-8'), $jobId]);
            throw $error;
        }
        return $this->job($jobId, $scope);
    }

    public function job(int $jobId, array $scope): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_export_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            throw new RecruitmentAdminException('导出任务不存在', 404);
        }
        $this->assertJobScope($job, $scope);
        unset($job['file_key']);
        $job['id'] = (int) $job['id'];
        $job['row_count'] = (int) $job['row_count'];
        $job['download_available'] = $job['status'] === 'completed' && strtotime((string) $job['expires_at']) > time();
        return $job;
    }

    public function download(int $jobId, array $scope): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_export_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            throw new RecruitmentAdminException('导出任务不存在', 404);
        }
        $this->assertJobScope($job, $scope);
        if ($job['status'] !== 'completed' || strtotime((string) $job['expires_at']) <= time()) {
            throw new RecruitmentAdminException('导出文件尚未完成或已过期', 410);
        }
        $root = realpath($this->storageRoot);
        $path = realpath($this->storageRoot . '/' . (string) $job['file_key']);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RecruitmentAdminException('导出文件不可用', 404);
        }
        return ['job' => $job, 'path' => $path];
    }

    private function queryRows(array $query, array $scope): array
    {
        [$scopeSql, $params] = $this->permissions->requirementWhereClause($scope, 'requirement');
        $where = [$scopeSql, "application.effective_grade IN ('A', 'B', 'C')"];
        foreach (['requirement_id' => 'application.requirement_id', 'batch_id' => 'document.batch_id'] as $key => $column) {
            $value = (int) ($query[$key] ?? 0);
            if ($value > 0) {
                $where[] = $column . ' = ?';
                $params[] = $value;
            }
        }
        $grade = strtoupper(trim((string) ($query['grade'] ?? '')));
        if ($grade !== '') {
            if (!in_array($grade, ['A', 'B', 'C'], true)) {
                throw new RecruitmentAdminException('导出仅支持有效 A/B/C 候选人');
            }
            $where[] = 'application.effective_grade = ?';
            $params[] = $grade;
        }
        $sql = 'SELECT application.*, candidate.name, candidate.phone_ciphertext, requirement.requirement_no, requirement.position_name_snapshot, requirement.id AS requirement_id, store.name AS store_name, batch.batch_no, '
            . 'GROUP_CONCAT(DISTINCT file.original_name ORDER BY page.page_order SEPARATOR \'、\') AS source_files '
            . 'FROM recruitment_applications application JOIN recruitment_candidates candidate ON candidate.id = application.candidate_id '
            . 'JOIN recruitment_requirements requirement ON requirement.id = application.requirement_id LEFT JOIN stores store ON store.id = requirement.store_id '
            . 'JOIN recruitment_resume_documents document ON document.id = application.document_id JOIN recruitment_resume_batches batch ON batch.id = document.batch_id '
            . 'LEFT JOIN recruitment_resume_document_pages page ON page.document_id = document.id LEFT JOIN recruitment_resume_files file ON file.id = page.resume_file_id '
            . 'WHERE ' . implode(' AND ', $where) . ' GROUP BY application.id, candidate.id, requirement.id, store.id, batch.id '
            . "ORDER BY requirement.requirement_no ASC, CASE application.effective_grade WHEN 'A' THEN 1 WHEN 'B' THEN 2 WHEN 'C' THEN 3 ELSE 4 END, application.total_score DESC, application.id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $normalizer = new ResumeFieldNormalizer();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $profile = json_decode((string) ($row['extracted_profile_json'] ?? ''), true);
            $profile = is_array($profile) ? $profile : [];
            $evidence = $this->evidenceSummary((int) $row['id']);
            $rows[] = [
                'requirement_id' => (int) $row['requirement_id'],
                'requirement_name' => (string) $row['requirement_no'] . '-' . (string) $row['position_name_snapshot'],
                'values' => [
                    $row['batch_no'], $row['requirement_no'], $row['store_name'], $row['position_name_snapshot'], $row['name'],
                    $normalizer->decrypt($row['phone_ciphertext'] ?? null), $row['source_files'], $this->scalar($profile, 'current_or_latest_role'),
                    $this->scalar($profile, 'total_work_years'), $this->items($profile, 'industry_experience'),
                    $this->items($profile, 'employment_history', 'responsibility_highlights'),
                    $this->joinNonEmpty([$this->scalar($profile, 'education_level'), $this->scalar($profile, 'major')]),
                    $this->joinNonEmpty([$this->items($profile, 'skills'), $this->items($profile, 'certificates')]),
                    $this->joinNonEmpty([$this->items($profile, 'responsibility_highlights'), $this->items($profile, 'performance_achievements')]),
                    $evidence['keywords'], $evidence['hard_status'], $this->items($profile, 'manual_checks'),
                    $row['total_score'], $row['effective_grade'], $row['score_adjustment_reason'], $this->contactLabel((string) $row['contact_status']), $row['contact_note'],
                ],
            ];
        }
        return $rows;
    }

    private function evidenceSummary(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT dimension_type, rule_key, match_status FROM recruitment_match_evidence WHERE application_id = ? ORDER BY id');
        $stmt->execute([$applicationId]);
        $keywords = [];
        $hard = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
            if ($item['dimension_type'] === 'keyword' && $item['match_status'] === 'matched') {
                $keywords[] = (string) $item['rule_key'];
            }
            if ($item['dimension_type'] === 'hard_condition') {
                $hard[] = (string) $item['rule_key'] . ':' . (string) $item['match_status'];
            }
        }
        return ['keywords' => implode('、', $keywords), 'hard_status' => implode('；', $hard)];
    }

    private function writeWorkbook(string $path, array $rows): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RecruitmentAdminException('当前 PHP 环境未启用 ZIP 扩展', 500);
        }
        $groups = ['总览' => $rows];
        foreach ($rows as $row) {
            $groups[(string) $row['requirement_name']][] = $row;
        }
        $sheetNames = $this->sheetNames(array_keys($groups));
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RecruitmentAdminException('无法创建 XLSX 文件', 500);
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($groups)));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($groups)));
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $index = 1;
        foreach ($groups as $sheetRows) {
            $zip->addFromString('xl/worksheets/sheet' . $index . '.xml', $this->sheetXml($sheetRows));
            $index++;
        }
        if (!$zip->close()) {
            throw new RecruitmentAdminException('XLSX 文件写入失败', 500);
        }
    }

    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        $allRows = [self::COLUMNS];
        foreach ($rows as $row) {
            $allRows[] = $row['values'];
        }
        foreach ($allRows as $rowIndex => $values) {
            $xml .= '<row r="' . ($rowIndex + 1) . '">';
            foreach (array_values($values) as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1) . ($rowIndex + 1);
                $safe = $this->formulaSafe((string) ($value ?? ''));
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $xml .= '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $this->xml($safe) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $lastRow = max(1, count($allRows));
        return $xml . '</sheetData><autoFilter ref="A1:V' . $lastRow . '"/></worksheet>';
    }

    private function formulaSafe(string $value): string
    {
        return $value !== '' && preg_match('/^[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }

    private function assertJobScope(array $job, array $scope): void
    {
        $query = json_decode((string) ($job['query_json'] ?? ''), true);
        foreach ((array) ($query['authorized_requirement_ids'] ?? []) as $requirementId) {
            if (!$this->permissions->canAccessRequirement($scope, (int) $requirementId)) {
                throw new RecruitmentAdminException('导出任务包含当前无权访问的招聘需求', 403);
            }
        }
    }

    private function safeTarget(string $fileKey): string
    {
        $path = $this->storageRoot . '/' . $fileKey;
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RecruitmentAdminException('无法创建导出存储目录', 500);
        }
        $root = realpath($this->storageRoot);
        $parent = realpath($directory);
        if ($root === false || $parent === false || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
            throw new RecruitmentAdminException('导出存储路径无效', 500);
        }
        return $path;
    }

    private function sheetNames(array $rawNames): array
    {
        $result = [];
        $used = [];
        foreach ($rawNames as $raw) {
            $base = mb_substr(preg_replace('~[\\/?*\[\]:]~u', '_', (string) $raw) ?: '工作表', 0, 31, 'UTF-8');
            $name = $base;
            $suffix = 2;
            while (isset($used[$name])) {
                $tail = '-' . $suffix++;
                $name = mb_substr($base, 0, 31 - mb_strlen($tail, 'UTF-8'), 'UTF-8') . $tail;
            }
            $used[$name] = true;
            $result[] = $name;
        }
        return $result;
    }

    private function contentTypes(int $sheetCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $overrides . '</Types>';
    }

    private function workbookXml(array $sheetNames): string
    {
        $sheets = '';
        foreach ($sheetNames as $index => $name) {
            $sheets .= '<sheet name="' . $this->xml($name) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheets . '</sheets></workbook>';
    }

    private function workbookRels(int $sheetCount): string
    {
        $relations = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $relations .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $relations .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relations . '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF245A4A"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>';
    }

    private function scalar(array $profile, string $field): string
    {
        return trim((string) ($profile[$field]['value'] ?? ''));
    }

    private function items(array $profile, string ...$fields): string
    {
        $items = [];
        foreach ($fields as $field) {
            foreach ((array) ($profile[$field]['items'] ?? []) as $item) {
                $items[] = trim((string) $item);
            }
        }
        return implode('；', array_values(array_filter($items)));
    }

    private function joinNonEmpty(array $items): string
    {
        return implode('；', array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $items))));
    }

    private function contactLabel(string $status): string
    {
        return ['not_contacted' => '待联系', 'calling' => '联系中', 'no_answer' => '待回拨', 'scheduled' => '已预约', 'rejected' => '无意向', 'invalid_phone' => '号码无效'][$status] ?? $status;
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)) . $name;
            $column = intdiv($column, 26);
        }
        return $name;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
