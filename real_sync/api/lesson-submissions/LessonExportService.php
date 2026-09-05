<?php
declare(strict_types=1);

final class LessonExportService
{
    private const FORMATS = ['xlsx', 'docx'];
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(private PDO $pdo, private PlatformPrivateFileStorage $storage)
    {
    }

    public function create(int $submissionId, string $format, int $actorStaffId, ?int $versionId = null): array
    {
        if ($this->pdo->inTransaction()) {
            return $this->createWithinTransaction($submissionId, $format, $actorStaffId, $versionId);
        }

        $this->pdo->beginTransaction();
        try {
            $result = $this->createWithinTransaction($submissionId, $format, $actorStaffId, $versionId);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function resolveVersionId(int $submissionId, string $format, int $actorStaffId, ?int $versionId = null): int
    {
        $format = strtolower(trim($format));
        if (!in_array($format, self::FORMATS, true)) throw new InvalidArgumentException('仅支持 xlsx 或 docx 导出');
        $submission = $this->submission($submissionId);
        if ((int) $submission['author_staff_id'] !== $actorStaffId) throw new PlatformApiException(403, 'lesson_submission_forbidden', '只能导出自己创建的教案');
        $version = $this->version($submissionId, $versionId ?: (int) $submission['current_version_id']);
        return (int) $version['id'];
    }

    public function createWithinTransaction(int $submissionId, string $format, int $actorStaffId, ?int $versionId = null): array
    {
        $format = strtolower(trim($format));
        $resolvedVersionId = $this->resolveVersionId($submissionId, $format, $actorStaffId, $versionId);
        $version = $this->version($submissionId, $resolvedVersionId);
        $content = json_decode((string) $version['content_json'], true, 512, JSON_THROW_ON_ERROR);

        $insert = $this->pdo->prepare("INSERT INTO lesson_exports (submission_id, version_id, format, status, created_by) VALUES (?, ?, ?, 'running', ?)");
        $insert->execute([$submissionId, (int) $version['id'], $format, $actorStaffId]);
        $exportId = (int) $this->pdo->lastInsertId();
        $this->audit($submissionId, (int) $version['id'], $actorStaffId, 'export_started', ['export_id' => $exportId, 'format' => $format]);

        try {
            $bytes = $format === 'xlsx' ? $this->xlsx($content) : $this->docx($content);
            $stored = $this->storage->storeBytes($bytes, 'lesson-exports/submission-' . $submissionId, $format);
            $this->pdo->prepare("UPDATE lesson_exports SET storage_key = ?, status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'running'")
                ->execute([$stored['storage_key'], $exportId]);
            $this->audit($submissionId, (int) $version['id'], $actorStaffId, 'export_completed', ['export_id' => $exportId, 'format' => $format, 'version_no' => (int) $version['version_no']]);
            return ['export_id' => $exportId, 'submission_id' => $submissionId, 'version_id' => (int) $version['id'], 'version_no' => (int) $version['version_no'], 'format' => $format, 'status' => 'completed', 'download_url' => '/api/lesson-submissions/export.php?id=' . $exportId];
        } catch (Throwable $error) {
            $this->pdo->prepare("UPDATE lesson_exports SET status = 'failed', error_message = ? WHERE id = ? AND status = 'running'")
                ->execute([mb_substr($error->getMessage() ?: '导出失败', 0, 2000, 'UTF-8'), $exportId]);
            throw new PlatformApiException(500, 'lesson_export_failed', '教案导出失败，请稍后重试');
        }
    }

    public function download(int $exportId, int $actorStaffId): array
    {
        $statement = $this->pdo->prepare('SELECT e.*, s.author_staff_id, s.title, v.version_no FROM lesson_exports e JOIN lesson_submissions s ON s.id = e.submission_id JOIN lesson_versions v ON v.id = e.version_id AND v.submission_id = e.submission_id WHERE e.id = ? LIMIT 1');
        $statement->execute([$exportId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformApiException(404, 'lesson_export_not_found', '导出文件不存在');
        if ((int) $row['author_staff_id'] !== $actorStaffId) throw new PlatformApiException(403, 'lesson_submission_forbidden', '无权下载该教案导出文件');
        if ($row['status'] !== 'completed' || trim((string) $row['storage_key']) === '') throw new PlatformApiException(409, 'lesson_export_unavailable', '导出文件尚未生成');
        return ['row' => $row, 'download' => $this->storage->prepareDownload([(string) $row['storage_key']], $row['format'] === 'xlsx' ? self::XLSX_MIME : self::DOCX_MIME, $this->filename($row))];
    }

    private function xlsx(array $content): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('服务器缺少 Office ZIP 生成能力');
        $rows = $this->sheets($content); $tmp = tempnam(sys_get_temp_dir(), 'lesson-xlsx-');
        if ($tmp === false) throw new RuntimeException('无法创建导出临时文件');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('无法创建 Excel 工作簿');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/><Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $names = ['基本信息', '课程流程', '安全与器材', 'ACE反思']; $workbook = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($names as $i => $name) { $workbook .= '<sheet name="' . $this->xml($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>'; $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($rows[$i])); }
        $zip->addFromString('xl/workbook.xml', $workbook . '</sheets></workbook>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Arial"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellXfs count="1"><xf/></cellXfs></styleSheet>');
        $zip->close(); $bytes = file_get_contents($tmp); if ($bytes === false) throw new RuntimeException('读取 Excel 导出失败'); unlink($tmp); return $bytes;
    }

    private function docx(array $content): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('服务器缺少 Office ZIP 生成能力');
        $paragraphs = []; foreach ($this->sheets($content) as $section) foreach ($section as $row) $paragraphs[] = '<w:p><w:r><w:t xml:space="preserve">' . $this->xml(implode('：', $row)) . '</w:t></w:r></w:p>';
        $document = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . implode('', $paragraphs) . '<w:sectPr/></w:body></w:document>';
        $tmp = tempnam(sys_get_temp_dir(), 'lesson-docx-'); if ($tmp === false) throw new RuntimeException('无法创建导出临时文件'); $zip = new ZipArchive(); if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('无法创建 Word 文档');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'); $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'); $zip->addFromString('word/document.xml', $document); $zip->close(); $bytes = file_get_contents($tmp); if ($bytes === false) throw new RuntimeException('读取 Word 导出失败'); unlink($tmp); return $bytes;
    }

    private function sheets(array $content): array
    {
        $meta = (array) ($content['metadata'] ?? []); $rows = [];
        foreach (['store_name' => '门店', 'author_name' => '教练', 'course_line' => '课程线', 'class_level' => '班级/级别', 'lesson_date' => '上课日期', 'title' => '标题'] as $key => $label) $rows[0][] = [$label, (string) ($meta[$key] ?? '')];
        $rows[1] = [['阶段', '时长（分钟）', '教学内容']]; foreach ((array) ($content['phases'] ?? []) as $phase) $rows[1][] = [(string) ($phase['name'] ?? $phase['title'] ?? ''), (string) ($phase['duration_minutes'] ?? $phase['duration'] ?? ''), (string) ($phase['content'] ?? $phase['description'] ?? '')];
        $rows[2] = [['安全与器材', '内容'], ['身体安全', (string) (($content['safety']['physical'] ?? ''))], ['心理安全', (string) (($content['safety']['psychological'] ?? ''))], ['器材', implode('、', (array) ($content['equipment'] ?? []))], ['升阶与降阶', implode('、', (array) ($content['progressions'] ?? []))], ['助教分工', (string) ($content['assistant_responsibilities'] ?? '')]];
        $rows[3] = [['维度', '目标', '课后反思']]; foreach (['athletic' => 'A 运动能力', 'cognitive' => 'C 认知能力', 'engagement' => 'E 参与动能'] as $key => $label) $rows[3][] = [$label, (string) (($content['objectives'][$key] ?? '')), (string) (($content['reflection'][$key] ?? ''))]; return $rows;
    }
    private function sheetXml(array $rows): string { $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'; foreach ($rows as $r => $row) { $xml .= '<row r="' . ($r + 1) . '">'; foreach ($row as $c => $value) { $ref = ''; $n = $c; do { $ref = chr(65 + ($n % 26)) . $ref; $n = intdiv($n, 26) - 1; } while ($n >= 0); $xml .= '<c r="' . $ref . ($r + 1) . '" t="inlineStr"><is><t xml:space="preserve">' . $this->xml((string) $value) . '</t></is></c>'; } $xml .= '</row>'; } return $xml . '</sheetData></worksheet>'; }
    private function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
    private function filename(array $row): string { return preg_replace('/[\\/\x00-\x1F]+/', '_', (string) ($row['title'] ?: '教案')) . '-V' . (int) $row['version_no'] . '.' . $row['format']; }
    private function submission(int $id): array { $s = $this->pdo->prepare('SELECT * FROM lesson_submissions WHERE id = ? LIMIT 1'); $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC); if (!$row) throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在'); return $row; }
    private function version(int $submissionId, int $versionId): array { $s = $this->pdo->prepare('SELECT * FROM lesson_versions WHERE id = ? AND submission_id = ? LIMIT 1'); $s->execute([$versionId, $submissionId]); $row = $s->fetch(PDO::FETCH_ASSOC); if (!$row) throw new PlatformApiException(404, 'lesson_version_not_found', '结构化版本不存在'); return $row; }
    private function audit(int $submissionId, int $versionId, int $staffId, string $action, array $metadata): void { $this->pdo->prepare('INSERT INTO lesson_audit_logs (submission_id, version_id, actor_staff_id, action, metadata_json) VALUES (?, ?, ?, ?, ?)')->execute([$submissionId, $versionId, $staffId, $action, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]); }
}
