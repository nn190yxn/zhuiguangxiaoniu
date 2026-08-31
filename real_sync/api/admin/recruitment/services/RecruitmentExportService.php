<?php

declare(strict_types=1);

require_once __DIR__ . '/ResumeFieldNormalizer.php';

final class RecruitmentExportService
{
    public const COLUMNS = [
        '批次编号', '录入时间', '招聘需求编号', '门店', '应聘岗位', '姓名', '手机号', '来源文件', '当前或最近岗位', '工作年限', '行业经历', '经验摘要',
        '教育与专业', '技能与证书', '简历亮点', '命中关键词', '硬性条件状态', '人工核验项', '匹配分', '等级', '匹配分析说明', '简历收到日期', '下次联系日期', '跟进人', '联系状态', '联系备注', '重复标记',
    ];

    public const UNCLASSIFIED_COLUMNS = [
        '姓名', '手机号', '来源文件', 'AI建议岗位', '简历亮点', '简历收到日期',
    ];

    public const DATE_COLUMNS = [
        '批次编号', '录入时间', '招聘需求编号', '门店', '应聘岗位', '姓名', '手机号', '来源文件', '当前或最近岗位', '工作年限', '行业经历', '经验摘要',
        '教育与专业', '技能与证书', '简历亮点', '命中关键词', '硬性条件状态', '人工核验项', '匹配分', '等级', '匹配分析说明', '简历收到日期', '下次联系日期', '跟进人', '联系状态', '联系备注', '重复标记', '处理状态',
    ];

    private PDO $pdo;
    private RecruitmentPermissionService $permissions;
    private string $storageRoot;

    public function __construct(PDO $pdo, RecruitmentPermissionService $permissions, ?string $storageRoot = null)
    {
        $this->pdo = $pdo;
        $this->permissions = $permissions;
        $configured = trim((string) ($storageRoot ?? getenv('RECRUITMENT_EXPORT_STORAGE_ROOT') ?: ''));
        $this->storageRoot = $configured !== '' ? rtrim($configured, DIRECTORY_SEPARATOR) : dirname(__DIR__, 4) . '/.private/recruitment-exports';
    }

    public function create(array $query, array $scope, int $staffId): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RecruitmentAdminException('服务器缺少 XLSX 生成组件', 503);
        }
        $query = $this->normalizeQuery($query);
        $rows = $this->queryRows($query, $scope);
        $unclassifiedRows = $this->queryUnclassifiedRows($query, $scope);
        $failedRows = $this->queryFailedRows($query, $scope);
        $rows = $this->sortExportRows($rows);
        $unclassifiedRows = $this->sortExportRows($unclassifiedRows);
        $failedRows = $this->sortExportRows($failedRows);
        $allRows = $this->mergeExportRows($rows, $unclassifiedRows, $failedRows);
        $requirementIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['requirement_id'], $rows)));
        $exportNo = 'REX' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
        $fileName = '招聘候选人-' . date('Ymd-His') . '.xlsx';
        $fileKey = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.xlsx';
        $storedQuery = $query;
        $storedQuery['authorized_requirement_ids'] = $requirementIds;
        $columnHash = hash('sha256', json_encode(self::COLUMNS, JSON_UNESCAPED_UNICODE));
        $sortHash = hash('sha256', 'document_created_at:desc,application_id:desc');
        $requirementId = count($requirementIds) === 1 ? $requirementIds[0] : null;
        $batchId = isset($query['batch_id']) && (int) $query['batch_id'] > 0 ? (int) $query['batch_id'] : null;
        $scopeType = $batchId ? 'batch' : ($requirementId ? 'requirement' : 'all');
        $totalRows = count($allRows);
        $stmt = $this->pdo->prepare(
            "INSERT INTO recruitment_export_jobs (export_no, requirement_id, batch_id, workbook_scope, status, query_json, column_schema_hash, sort_schema_hash, file_key, file_name, row_count, created_by, started_at, expires_at) VALUES (?, ?, ?, ?, 'running', ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
        );
        $stmt->execute([$exportNo, $requirementId, $batchId, $scopeType, json_encode($storedQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $columnHash, $sortHash, $fileKey, $fileName, $totalRows, $staffId ?: null]);
        $jobId = (int) $this->pdo->lastInsertId();
        try {
            $path = $this->safeTarget($fileKey);
            $this->writeWorkbook($path, $rows, $unclassifiedRows, $failedRows);
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
        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where[] = 'document.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where[] = 'document.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $sql = 'SELECT application.*, candidate.name, candidate.phone_ciphertext, candidate.phone_display_ciphertext, candidate.email_lookup_hash, candidate.phone_lookup_hash, candidate.duplicate_status, requirement.requirement_no, requirement.position_name_snapshot, requirement.id AS requirement_id, store.name AS store_name, batch.batch_no, document.id AS document_id, document.created_at AS doc_created_at, position.sort_order AS position_sort_order, '
            . 'contact_log.scheduled_at AS next_contact_date, contact_staff.display_name AS contact_staff_name, grade_result.grade_snapshot_json, '
            . 'GROUP_CONCAT(DISTINCT file.original_name ORDER BY page.page_order SEPARATOR \'、\') AS source_files '
            . 'FROM recruitment_applications application JOIN recruitment_candidates candidate ON candidate.id = application.candidate_id '
            . 'JOIN recruitment_requirements requirement ON requirement.id = application.requirement_id LEFT JOIN stores store ON store.id = requirement.store_id LEFT JOIN organization_positions position ON position.id = requirement.position_id '
            . 'JOIN recruitment_resume_documents document ON document.id = application.document_id JOIN recruitment_resume_batches batch ON batch.id = document.batch_id '
            . 'LEFT JOIN recruitment_resume_document_pages page ON page.document_id = document.id LEFT JOIN recruitment_resume_files file ON file.id = page.resume_file_id '
            . 'LEFT JOIN recruitment_contact_logs contact_log ON contact_log.application_id = application.id AND contact_log.id = (SELECT MAX(id) FROM recruitment_contact_logs WHERE application_id = application.id) '
            . 'LEFT JOIN wp_users contact_staff ON contact_staff.ID = contact_log.operator_staff_id '
            . 'LEFT JOIN recruitment_grade_results grade_result ON grade_result.application_id = application.id '
            . 'WHERE ' . implode(' AND ', $where) . ' GROUP BY application.id, candidate.id, requirement.id, store.id, batch.id, contact_log.id, contact_staff.ID, grade_result.id '
            . 'ORDER BY document.created_at DESC, application.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $normalizer = new ResumeFieldNormalizer();
        $duplicateHashes = $this->duplicateHashSet();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $profile = $this->loadProfile((int) $row['current_processing_version_id']);
            $evidence = $this->evidenceSummary((int) $row['id']);
            $matchAnalysis = $this->matchAnalysis((string) ($row['grade_snapshot_json'] ?? ''));
            $duplicate = $this->duplicateFlag($duplicateHashes, (string) ($row['phone_lookup_hash'] ?? ''), (string) ($row['email_lookup_hash'] ?? ''));
            $receivedDate = $row['doc_created_at'] ? date('Y-m-d', strtotime((string) $row['doc_created_at'])) : '';
            $receivedAt = $row['doc_created_at'] ? date('Y-m-d H:i', strtotime((string) $row['doc_created_at'])) : '';
            $nextContactDate = $row['next_contact_date'] ? date('Y-m-d H:i', strtotime((string) $row['next_contact_date'])) : '';
            $contactStaff = trim((string) ($row['contact_staff_name'] ?? ''));
            $rows[] = [
                'document_id' => (int) $row['document_id'],
                'received_date' => $receivedDate,
                'received_at' => $receivedAt,
                'position_sort_order' => $row['position_sort_order'] === null ? null : (int) $row['position_sort_order'],
                'classification_status' => 'classified',
                'requirement_id' => (int) $row['requirement_id'],
                'requirement_name' => trim((string) $row['position_name_snapshot']) ?: '未命名岗位',
                'values' => [
                    $row['batch_no'], $receivedAt, $row['requirement_no'], $row['store_name'], $row['position_name_snapshot'], $row['name'],
                    $normalizer->decrypt($row['phone_ciphertext'] ?? null) ?: $normalizer->decrypt($row['phone_display_ciphertext'] ?? null), $row['source_files'], $this->scalar($profile, 'current_or_latest_role'),
                    $this->scalar($profile, 'total_work_years'), $this->items($profile, 'industry_experience'),
                    $this->items($profile, 'employment_history', 'responsibility_highlights'),
                    $this->joinNonEmpty([$this->scalar($profile, 'education_level'), $this->scalar($profile, 'major')]),
                    $this->joinNonEmpty([$this->items($profile, 'skills'), $this->items($profile, 'certificates')]),
                    $this->joinNonEmpty([$this->items($profile, 'responsibility_highlights'), $this->items($profile, 'performance_achievements')]),
                    $evidence['keywords'], $evidence['hard_status'], $this->items($profile, 'manual_checks'),
                    $row['total_score'], $row['effective_grade'], $matchAnalysis, $receivedDate, $nextContactDate, $contactStaff,
                    $this->contactLabel((string) $row['contact_status']), $row['contact_note'], $duplicate,
                ],
            ];
        }
        return $rows;
    }

    private function normalizeQuery(array $query): array
    {
        $scopeMode = strtolower(trim((string) ($query['scope_mode'] ?? 'all')));
        if (!in_array($scopeMode, ['all', 'current'], true)) {
            throw new RecruitmentAdminException('导出范围无效');
        }
        $query['scope_mode'] = $scopeMode;
        if ($scopeMode === 'all') {
            unset($query['batch_id']);
        } elseif ((int) ($query['batch_id'] ?? 0) <= 0) {
            throw new RecruitmentAdminException('请选择需要导出的当前批次');
        }
        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        foreach ([$dateFrom, $dateTo] as $date) {
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new RecruitmentAdminException('录入日期格式无效');
            }
        }
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            throw new RecruitmentAdminException('录入结束日期需晚于或等于开始日期');
        }
        return $query;
    }

    private function queryUnclassifiedRows(array $query, array $scope): array
    {
        [$scopeSql, $params] = $this->permissions->requirementWhereClause($scope, 'req');
        $where = ['d.classification_status = \'needs_confirmation\'', '(' . $scopeSql . ')'];
        $batchId = (int) ($query['batch_id'] ?? 0);
        if ($batchId > 0) {
            $where[] = 'd.batch_id = ?';
            $params[] = $batchId;
        }
        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'd.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'd.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $sql = "SELECT d.id AS document_id, d.created_at AS doc_created_at, d.classification_status, "
            . "cv.confidence_score, cv.selected_requirement_id, req.position_name_snapshot, "
            . "GROUP_CONCAT(DISTINCT f.original_name ORDER BY p.page_order SEPARATOR '、') AS source_files "
            . "FROM recruitment_resume_documents d "
            . "JOIN recruitment_resume_batches b ON b.id = d.batch_id "
            . "LEFT JOIN recruitment_resume_classification_versions cv ON cv.document_id = d.id "
            . "LEFT JOIN recruitment_requirements req ON req.id = cv.selected_requirement_id "
            . "LEFT JOIN recruitment_resume_document_pages p ON p.document_id = d.id "
            . "LEFT JOIN recruitment_resume_files f ON f.id = p.resume_file_id "
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . "GROUP BY d.id, cv.id, req.id "
            . "ORDER BY d.created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($documents)) {
            return [];
        }
        $suggestions = $this->aiSuggestPositions($documents);
        $rows = [];
        foreach ($documents as $doc) {
            $suggestion = $suggestions[(int) $doc['document_id']] ?? ['name' => '', 'phone' => '', 'position' => '', 'highlights' => []];
            $receivedDate = $doc['doc_created_at'] ? date('Y-m-d', strtotime((string) $doc['doc_created_at'])) : '';
            $rows[] = [
                'document_id' => (int) $doc['document_id'],
                'received_date' => $receivedDate,
                'received_at' => $receivedDate,
                'position_sort_order' => null,
                'classification_status' => 'needs_confirmation',
                'requirement_id' => 0,
                'requirement_name' => '未归类确认',
                'values' => [
                    $suggestion['name'] ?? '',
                    $suggestion['phone'] ?? '',
                    (string) ($doc['source_files'] ?? ''),
                    $suggestion['position'] ?? '',
                    implode('；', $suggestion['highlights'] ?? []),
                    $receivedDate,
                ],
            ];
        }
        return $rows;
    }

    private function sortExportRows(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $leftSort = $left['position_sort_order'] ?? null;
            $rightSort = $right['position_sort_order'] ?? null;
            if (($leftSort === null) !== ($rightSort === null)) {
                return $leftSort === null ? 1 : -1;
            }
            $sort = ($leftSort ?? 0) <=> ($rightSort ?? 0);
            if ($sort !== 0) {
                return $sort;
            }
            $sort = ((int) ($left['requirement_id'] ?? 0)) <=> ((int) ($right['requirement_id'] ?? 0));
            if ($sort !== 0) {
                return $sort;
            }
            $sort = strcmp((string) ($left['received_at'] ?? ''), (string) ($right['received_at'] ?? ''));
            if ($sort !== 0) {
                return $sort;
            }
            return ((int) ($left['document_id'] ?? 0)) <=> ((int) ($right['document_id'] ?? 0));
        });
        $unique = [];
        foreach ($rows as $row) {
            $documentId = (int) ($row['document_id'] ?? 0);
            $key = $documentId > 0 ? (string) $documentId : 'row-' . count($unique);
            if (!isset($unique[$key])) {
                $unique[$key] = $row;
            }
        }
        return array_values($unique);
    }

    private function mergeExportRows(array ...$rowSets): array
    {
        $merged = [];
        foreach ($rowSets as $rows) {
            foreach ($rows as $row) {
                $documentId = (int) ($row['document_id'] ?? 0);
                $key = $documentId > 0 ? (string) $documentId : 'row-' . count($merged);
                if (!isset($merged[$key])) {
                    $merged[$key] = $row;
                }
            }
        }
        return $this->sortExportRows(array_values($merged));
    }

    private function queryFailedRows(array $query, array $scope): array
    {
        [$scopeSql, $params] = $this->permissions->requirementWhereClause($scope, 'req');
        $where = ["d.status = 'failed'", "d.classification_status = 'failed'", '(' . $scopeSql . ')'];
        $batchId = (int) ($query['batch_id'] ?? 0);
        if ($batchId > 0) {
            $where[] = 'd.batch_id = ?';
            $params[] = $batchId;
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            $date = trim((string) ($query[$key] ?? ''));
            if ($date !== '') {
                $where[] = 'd.created_at ' . $operator . ' ?';
                $params[] = $date . ($key === 'date_from' ? ' 00:00:00' : ' 23:59:59');
            }
        }
        $sql = 'SELECT d.id AS document_id, d.created_at AS doc_created_at, d.failure_message, b.batch_no, '
            . 'req.id AS requirement_id, req.requirement_no, req.position_name_snapshot, store.name AS store_name, position.sort_order AS position_sort_order, '
            . "GROUP_CONCAT(DISTINCT f.original_name ORDER BY p.page_order SEPARATOR '、') AS source_files "
            . 'FROM recruitment_resume_documents d JOIN recruitment_resume_batches b ON b.id = d.batch_id '
            . 'LEFT JOIN recruitment_requirements req ON req.id = d.assigned_requirement_id LEFT JOIN stores store ON store.id = req.store_id '
            . 'LEFT JOIN organization_positions position ON position.id = req.position_id '
            . 'LEFT JOIN recruitment_resume_document_pages p ON p.document_id = d.id LEFT JOIN recruitment_resume_files f ON f.id = p.resume_file_id '
            . 'WHERE ' . implode(' AND ', $where) . ' GROUP BY d.id, b.id, req.id, store.id, position.id ORDER BY d.created_at ASC, d.id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $receivedDate = $row['doc_created_at'] ? date('Y-m-d', strtotime((string) $row['doc_created_at'])) : '';
            $receivedAt = $row['doc_created_at'] ? date('Y-m-d H:i', strtotime((string) $row['doc_created_at'])) : '';
            $values = array_fill(0, count(self::COLUMNS), '');
            $values[0] = $row['batch_no'] ?? '';
            $values[1] = $receivedAt;
            $values[2] = $row['requirement_no'] ?? '';
            $values[3] = $row['store_name'] ?? '';
            $values[4] = $row['position_name_snapshot'] ?? '';
            $values[7] = $row['source_files'] ?? '';
            $values[16] = trim((string) ($row['failure_message'] ?? ''));
            $values[21] = $receivedDate;
            $rows[] = [
                'document_id' => (int) $row['document_id'],
                'received_date' => $receivedDate,
                'received_at' => $receivedAt,
                'position_sort_order' => $row['position_sort_order'] === null ? null : (int) $row['position_sort_order'],
                'classification_status' => 'failed',
                'requirement_id' => $row['requirement_id'] === null ? 0 : (int) $row['requirement_id'],
                'requirement_name' => trim((string) ($row['position_name_snapshot'] ?? '')) ?: '未归类确认',
                'values' => $values,
            ];
        }
        return $rows;
    }

    private function aiSuggestPositions(array $documents): array
    {
        if (!function_exists('ai_stepfun_recruitment_chat')) {
            return [];
        }
        $suggestions = [];
        foreach ($documents as $doc) {
            $text = mb_substr((string) ($doc['full_text'] ?? ''), 0, 8000, 'UTF-8');
            if (trim($text) === '') {
                continue;
            }
            try {
                $prompt = json_encode([
                    'pages' => [['page_no' => 1, 'text' => $text]],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $systemPrompt = '你是招聘顾问。根据简历内容，提取候选人姓名、手机号，推荐一个最适合的面试岗位，并提炼3条简历亮点。仅返回JSON：{"name":"姓名","phone":"手机号","position":"建议面试岗位","highlights":["亮点1","亮点2","亮点3"]}';
                $content = ai_stepfun_recruitment_chat($prompt, $systemPrompt, 2000, 0.0, 120, true);
                $result = json_decode($content, true);
                if (is_array($result)) {
                    $suggestions[(int) $doc['document_id']] = $result;
                }
            } catch (Throwable) {
                continue;
            }
        }
        return $suggestions;
    }

    private function loadProfile(int $processingVersionId): array
    {
        $stmt = $this->pdo->prepare("SELECT model_output_json FROM recruitment_model_results WHERE processing_version_id = ? AND status = 'succeeded' LIMIT 1");
        $stmt->execute([$processingVersionId]);
        $decoded = json_decode((string) ($stmt->fetchColumn() ?: ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function matchAnalysis(string $gradeSnapshotJson): string
    {
        $snapshot = json_decode($gradeSnapshotJson, true);
        if (!is_array($snapshot)) {
            return '暂无评分数据';
        }
        $evidence = $snapshot['evidence'] ?? [];
        $hardMatched = 0;
        $hardTotal = 0;
        $kwMatched = 0;
        $kwNames = [];
        $expStatus = '';
        foreach ($evidence as $item) {
            $type = (string) ($item['dimension_type'] ?? '');
            $status = (string) ($item['match_status'] ?? '');
            if ($type === 'hard_condition') {
                $hardTotal++;
                if (in_array($status, ['matched', 'manual_check'], true)) {
                    $hardMatched++;
                }
            }
            if ($type === 'keyword') {
                if (in_array($status, ['matched', 'manual_check'], true)) {
                    $kwMatched++;
                    $kwNames[] = (string) ($item['rule_key'] ?? '');
                }
            }
            if ($type === 'experience') {
                $expStatus = in_array($status, ['matched', 'manual_check'], true) ? 'matched' : $status;
            }
        }
        $parts = [];
        if ($hardTotal > 0) {
            $parts[] = "硬条件{$hardMatched}/{$hardTotal}满足";
        }
        if ($kwMatched > 0) {
            $kwLabel = implode('、', array_slice($kwNames, 0, 3));
            if (count($kwNames) > 3) {
                $kwLabel .= '等';
            }
            $parts[] = "命中{$kwMatched}项关键词({$kwLabel})";
        }
        if ($expStatus === 'matched') {
            $parts[] = '经验符合要求';
        } elseif ($expStatus === 'unmatched') {
            $parts[] = '经验年限不足';
        }
        return implode('；', $parts) ?: '自动评分';
    }

    private function duplicateHashSet(): array
    {
        $hashes = [];
        $stmt = $this->pdo->query("SELECT phone_lookup_hash, email_lookup_hash FROM recruitment_candidates WHERE phone_lookup_hash != '' OR email_lookup_hash != ''");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $phone = trim((string) ($row['phone_lookup_hash'] ?? ''));
            $email = trim((string) ($row['email_lookup_hash'] ?? ''));
            if ($phone !== '') {
                $hashes[$phone] = ($hashes[$phone] ?? 0) + 1;
            }
            if ($email !== '') {
                $hashes[$email] = ($hashes[$email] ?? 0) + 1;
            }
        }
        return $hashes;
    }

    private function duplicateFlag(array $hashSet, string $phoneHash, string $emailHash): string
    {
        $reasons = [];
        if ($phoneHash !== '' && ($hashSet[$phoneHash] ?? 0) > 1) {
            $reasons[] = '电话重复';
        }
        if ($emailHash !== '' && ($hashSet[$emailHash] ?? 0) > 1) {
            $reasons[] = '邮箱重复';
        }
        return implode('；', $reasons);
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

    private function writeWorkbook(string $path, array $rows, array $unclassifiedRows, array $failedRows = []): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RecruitmentAdminException('当前 PHP 环境未启用 ZIP 扩展', 500);
        }
        $groups = ['总览' => $rows];
        $columnsBySheet = ['总览' => self::COLUMNS];
        $allRows = $this->mergeExportRows($rows, $unclassifiedRows, $failedRows);
        $groups['总览'] = $this->dateSheetRows($allRows);
        $columnsBySheet['总览'] = self::DATE_COLUMNS;
        $dateGroups = [];
        foreach ($allRows as $row) {
            $receivedDate = trim((string) ($row['received_date'] ?? ''));
            if ($receivedDate !== '') {
                $dateGroups[$receivedDate][] = $row;
            }
        }
        ksort($dateGroups, SORT_STRING);
        foreach ($dateGroups as $receivedDate => $dateRows) {
            $groups[$receivedDate] = $this->dateSheetRows($this->sortExportRows($dateRows));
            $columnsBySheet[$receivedDate] = self::DATE_COLUMNS;
        }
        foreach ($rows as $row) {
            $groups[(string) $row['requirement_name']][] = $row;
            $columnsBySheet[(string) $row['requirement_name']] = self::COLUMNS;
        }
        if (!empty($unclassifiedRows)) {
            $groups['未归类确认'] = $unclassifiedRows;
            $columnsBySheet['未归类确认'] = self::UNCLASSIFIED_COLUMNS;
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
        foreach ($groups as $sheetKey => $sheetRows) {
            $columns = $columnsBySheet[$sheetKey] ?? self::COLUMNS;
            $zip->addFromString('xl/worksheets/sheet' . $index . '.xml', $this->sheetXml($sheetRows, $columns));
            $index++;
        }
        if (!$zip->close()) {
            throw new RecruitmentAdminException('XLSX 文件写入失败', 500);
        }
    }

    private function dateSheetRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $values = (array) ($row['values'] ?? []);
            if (count($values) === count(self::UNCLASSIFIED_COLUMNS)) {
                $dateValues = array_fill(0, count(self::COLUMNS), '');
                $dateValues[4] = $values[3] ?? '';
                $dateValues[5] = $values[0] ?? '';
                $dateValues[6] = $values[1] ?? '';
                $dateValues[7] = $values[2] ?? '';
                $dateValues[14] = $values[4] ?? '';
                $dateValues[21] = $values[5] ?? '';
            } else {
                $dateValues = array_slice(array_pad($values, count(self::COLUMNS), ''), 0, count(self::COLUMNS));
            }
            $dateValues[] = $this->processingStatusLabel((string) ($row['classification_status'] ?? ''));
            $row['values'] = $dateValues;
            $result[] = $row;
        }
        return $result;
    }

    private function processingStatusLabel(string $status): string
    {
        return [
            'classified' => '已归类',
            'needs_confirmation' => '待确认岗位',
            'failed' => '处理失败',
        ][$status] ?? $status;
    }

    private function sheetXml(array $rows, array $columns): string
    {
        $colCount = count($columns);
        $lastCol = $this->columnName($colCount);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        $allRows = [$columns];
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
        return $xml . '</sheetData><autoFilter ref="A1:' . $lastCol . $lastRow . '"/></worksheet>';
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
