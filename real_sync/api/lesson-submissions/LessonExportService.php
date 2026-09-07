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
        $suggestions = $this->suggestions($submissionId, $resolvedVersionId, $content);
        $sourcePath = $format === 'xlsx' ? $this->sourcePath($version) : null;

        $insert = $this->pdo->prepare("INSERT INTO lesson_exports (submission_id, version_id, format, status, created_by) VALUES (?, ?, ?, 'running', ?)");
        $insert->execute([$submissionId, (int) $version['id'], $format, $actorStaffId]);
        $exportId = (int) $this->pdo->lastInsertId();
        $this->audit($submissionId, (int) $version['id'], $actorStaffId, 'export_started', ['export_id' => $exportId, 'format' => $format]);

        try {
            $bytes = $format === 'xlsx' ? $this->xlsx($content, $suggestions, $sourcePath) : $this->docx($content, $suggestions);
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

    private function xlsx(array $content, array $suggestions = [], ?string $sourcePath = null): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('服务器缺少 Office ZIP 生成能力');
        if ($sourcePath !== null && is_readable($sourcePath)) {
            $source = new ZipArchive();
            if ($source->open($sourcePath) === true) {
                $workbook = $source->getFromName('xl/workbook.xml');
                $relations = $source->getFromName('xl/_rels/workbook.xml.rels');
                $types = $source->getFromName('[Content_Types].xml');
                $valid = is_string($workbook) && is_string($relations) && is_string($types)
                    && @simplexml_load_string($workbook) !== false && @simplexml_load_string($relations) !== false
                    && @simplexml_load_string($types) !== false && str_contains($workbook, '</sheets>');
                $source->close();
                if ($valid) return $this->xlsxWithOriginalSheets($sourcePath, $suggestions);
            }
        }
        $rows = $this->sheets($content, $suggestions); $tmp = tempnam(sys_get_temp_dir(), 'lesson-xlsx-');
        if ($tmp === false) throw new RuntimeException('无法创建导出临时文件');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('无法创建 Excel 工作簿');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet5.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/><Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet5.xml"/><Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $names = ['基本信息', '课程流程', '安全与器材', 'ACE反思', '修改建议']; $workbook = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
         foreach ($names as $i => $name) { $workbook .= '<sheet name="' . $this->xml($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>'; $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $i === 4 ? $this->suggestionSheetXml($this->suggestionRows($suggestions)) : $this->sheetXml($rows[$i])); }
        $zip->addFromString('xl/workbook.xml', $workbook . '</sheets></workbook>');
         $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Arial"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellXfs count="2"><xf/><xf applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs></styleSheet>');
        $zip->close(); $bytes = file_get_contents($tmp); if ($bytes === false) throw new RuntimeException('读取 Excel 导出失败'); unlink($tmp); return $bytes;
    }

    private function xlsxWithOriginalSheets(string $sourcePath, array $suggestions): string
    {
        $source = new ZipArchive();
        if ($source->open($sourcePath) !== true) throw new RuntimeException('无法读取原始 Excel 工作簿');
        $tmp = tempnam(sys_get_temp_dir(), 'lesson-xlsx-');
        if ($tmp === false) { $source->close(); throw new RuntimeException('无法创建导出临时文件'); }
        $output = new ZipArchive();
        if ($output->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { $source->close(); throw new RuntimeException('无法创建 Excel 工作簿'); }
        for ($index = 0; $index < $source->numFiles; $index++) {
            $name = $source->getNameIndex($index);
            if ($name !== false) $output->addFromString($name, $source->getFromIndex($index) ?: '');
        }
        $workbook = $source->getFromName('xl/workbook.xml');
        $relations = $source->getFromName('xl/_rels/workbook.xml.rels');
        $types = $source->getFromName('[Content_Types].xml');
        $styles = $source->getFromName('xl/styles.xml');
        if (!is_string($workbook) || !is_string($relations) || !is_string($types)) { $source->close(); $output->close(); throw new RuntimeException('原始 Excel 缺少工作簿元数据'); }
        if (!is_string($styles)) {
            $styles = '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="1"><xf/></cellXfs></styleSheet>';
            $types = str_replace('</Types>', '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>', $types);
        }
        preg_match_all('/<sheet\b[^>]*sheetId="(\d+)"[^>]*>/u', $workbook, $sheetIds);
        $sheetNo = $sheetIds[1] ? max(array_map('intval', $sheetIds[1])) + 1 : 1;
        while ($source->locateName('xl/worksheets/sheet' . $sheetNo . '.xml') !== false) $sheetNo++;
        preg_match_all('/rId(\d+)/', $relations, $relationIds);
        $relationNo = $relationIds[1] ? max(array_map('intval', $relationIds[1])) + 1 : 1;
        if (!str_contains($relations, '/styles"')) {
            $relations = str_replace('</Relationships>', '<Relationship Id="rId' . $relationNo++ . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>', $relations);
        }
        preg_match('/<cellXfs\b[^>]*count="(\d+)"/', $styles, $styleCount);
        $sheetXml = str_replace(' s="1"', ' s="' . (int) ($styleCount[1] ?? 0) . '"', $this->suggestionSheetXml($this->suggestionRows($suggestions)));
        $sheetPath = 'xl/worksheets/sheet' . $sheetNo . '.xml';
        $sheetName = '修改建议';
        for ($suffix = 2; str_contains($workbook, 'name="' . $sheetName . '"'); $suffix++) $sheetName = '修改建议' . $suffix;
        $workbook = preg_replace('/<\/sheets>/u', '<sheet name="' . $sheetName . '" sheetId="' . $sheetNo . '" r:id="rId' . $relationNo . '"/></sheets>', $workbook, 1) ?? $workbook;
        $relations = preg_replace('/<\/Relationships>/u', '<Relationship Id="rId' . $relationNo . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNo . '.xml"/></Relationships>', $relations, 1) ?? $relations;
        $types = preg_replace('/<\/Types>/u', '<Override PartName="/' . $sheetPath . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>', $types, 1) ?? $types;
        $styles = $this->withWrappedCellStyle($styles);
        $output->addFromString('xl/workbook.xml', $workbook);
        $output->addFromString('xl/_rels/workbook.xml.rels', $relations);
        $output->addFromString('[Content_Types].xml', $types);
        $output->addFromString('xl/styles.xml', $styles);
        $output->addFromString($sheetPath, $sheetXml);
        $source->close(); $output->close();
        $bytes = file_get_contents($tmp);
        if ($bytes === false) throw new RuntimeException('读取 Excel 导出失败');
        unlink($tmp);
        return $bytes;
    }

    private function docx(array $content, array $suggestions = []): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('服务器缺少 Office ZIP 生成能力');
        $meta = (array) ($content['metadata'] ?? []);
        $decisions = array_count_values(array_map(static fn(array $item): string => (string) ($item['decision'] ?? 'pending'), $suggestions));
        $paragraphs = [$this->docxParagraph('教案修改建议单', 'Title'), $this->docxParagraph('教案：' . ($meta['title'] ?? '') . '｜科目：' . ($meta['course_line'] ?? '') . '｜年龄：' . ($meta['age_range'] ?? '') . '｜阶段：' . ($meta['class_stage'] ?? '')), $this->docxParagraph('教练：' . ($meta['author_name'] ?? '') . '｜上传时间：' . ($meta['created_at'] ?? '') . '｜建议生成时间：' . date('Y-m-d H:i:s')), $this->docxParagraph('使用说明：本建议单用于辅助修改，原教案内容保持不变。'), $this->docxParagraph('建议总数：' . count($suggestions) . '｜待处理：' . ($decisions['pending'] ?? 0) . '｜已采纳：' . ($decisions['accepted'] ?? 0) . '｜已忽略：' . ($decisions['ignored'] ?? 0))];
        foreach ($suggestions as $index => $suggestion) {
            if ($index > 0) $paragraphs[] = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            $paragraphs[] = $this->docxParagraph('建议 ' . ($index + 1), 'Heading1');
            $paragraphs[] = $this->docxTable([['字段', '内容'], ['教案位置', $suggestion['location'] ?? $suggestion['field_path'] ?? ''], ['当前内容', $suggestion['original_excerpt'] ?? ''], ['发现的问题', $suggestion['message'] ?? ''], ['判断理由', $suggestion['reason'] ?? ''], ['修改建议', $suggestion['recommendation'] ?? $suggestion['message'] ?? ''], ['知识卡依据', $suggestion['knowledge_item_title'] ?? ''], ['教练处理', $suggestion['decision'] ?? 'pending'], ['修改后内容', $suggestion['revised_content'] ?? '']]);
        }
        $paragraphs[] = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>' . $this->docxParagraph('结尾检查表', 'Heading1');
        foreach (['课程信息是否完整', '年龄和班级阶段是否匹配', '动作和游戏是否适合本节课', '安全、器材和时间是否完整', '教练是否处理所有建议', '是否已重新上传修改后的教案'] as $item) $paragraphs[] = $this->docxParagraph('□ ' . $item);
        $document = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . implode('', $paragraphs) . '<w:sectPr/></w:body></w:document>';
        $tmp = tempnam(sys_get_temp_dir(), 'lesson-docx-'); if ($tmp === false) throw new RuntimeException('无法创建导出临时文件'); $zip = new ZipArchive(); if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('无法创建 Word 文档');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'); $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'); $zip->addFromString('word/document.xml', $document); $zip->close(); $bytes = file_get_contents($tmp); if ($bytes === false) throw new RuntimeException('读取 Word 导出失败'); unlink($tmp); return $bytes;
    }

    private function docxParagraph(string $text, ?string $style = null): string
    {
        $prefix = $style ? '<w:pPr><w:pStyle w:val="' . $this->xml($style) . '"/></w:pPr>' : '';
        return '<w:p>' . $prefix . '<w:r><w:t xml:space="preserve">' . $this->xml($text) . '</w:t></w:r></w:p>';
    }

    private function docxTable(array $rows): string
    {
        $xml = '<w:tbl><w:tblPr><w:tblBorders><w:top w:val="single"/><w:left w:val="single"/><w:bottom w:val="single"/><w:right w:val="single"/><w:insideH w:val="single"/><w:insideV w:val="single"/></w:tblBorders></w:tblPr>';
        foreach ($rows as $row) { $xml .= '<w:tr>'; foreach ($row as $value) $xml .= '<w:tc><w:p><w:r><w:t xml:space="preserve">' . $this->xml((string) $value) . '</w:t></w:r></w:p></w:tc>'; $xml .= '</w:tr>'; }
        return $xml . '</w:tbl>';
    }

    private function sheets(array $content, array $suggestions = []): array
    {
        $meta = (array) ($content['metadata'] ?? []); $rows = [];
        foreach (['store_name' => '门店', 'author_name' => '教练', 'course_line' => '课程线', 'class_level' => '班级/级别', 'lesson_date' => '上课日期', 'title' => '标题'] as $key => $label) $rows[0][] = [$label, (string) ($meta[$key] ?? '')];
        $rows[1] = [['阶段', '时长（分钟）', '教学内容']]; foreach ((array) ($content['phases'] ?? []) as $phase) $rows[1][] = [(string) ($phase['name'] ?? $phase['title'] ?? ''), (string) ($phase['duration_minutes'] ?? $phase['duration'] ?? ''), (string) ($phase['content'] ?? $phase['description'] ?? '')];
        $rows[2] = [['安全与器材', '内容'], ['身体安全', (string) (($content['safety']['physical'] ?? ''))], ['心理安全', (string) (($content['safety']['psychological'] ?? ''))], ['器材', implode('、', (array) ($content['equipment'] ?? []))], ['升阶与降阶', implode('、', (array) ($content['progressions'] ?? []))], ['助教分工', (string) ($content['assistant_responsibilities'] ?? '')]];
        $rows[3] = [['维度', '目标', '课后反思']]; foreach (['athletic' => 'A 运动能力', 'cognitive' => 'C 认知能力', 'engagement' => 'E 参与动能'] as $key => $label) $rows[3][] = [$label, (string) (($content['objectives'][$key] ?? '')), (string) (($content['reflection'][$key] ?? ''))];
        $rows[4] = [['优先级', '字段', '建议', '依据', '处理状态']]; foreach ($suggestions as $suggestion) $rows[4][] = [(string) ($suggestion['priority'] ?? ''), (string) ($suggestion['field_path'] ?? ''), (string) ($suggestion['message'] ?? ''), (string) ($suggestion['reason'] ?? ''), (string) ($suggestion['decision'] ?? 'pending')]; return $rows;
    }
    private function sheetXml(array $rows): string { $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'; foreach ($rows as $r => $row) { $xml .= '<row r="' . ($r + 1) . '">'; foreach ($row as $c => $value) { $ref = ''; $n = $c; do { $ref = chr(65 + ($n % 26)) . $ref; $n = intdiv($n, 26) - 1; } while ($n >= 0); $xml .= '<c r="' . $ref . ($r + 1) . '" t="inlineStr"><is><t xml:space="preserve">' . $this->xml((string) $value) . '</t></is></c>'; } $xml .= '</row>'; } return $xml . '</sheetData></worksheet>'; }
    private function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
    private function filename(array $row): string { return preg_replace('/[\\/\x00-\x1F]+/', '_', (string) ($row['title'] ?: '教案')) . '-V' . (int) $row['version_no'] . '.' . $row['format']; }
    private function submission(int $id): array { $s = $this->pdo->prepare('SELECT * FROM lesson_submissions WHERE id = ? LIMIT 1'); $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC); if (!$row) throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在'); return $row; }
    private function version(int $submissionId, int $versionId): array { $s = $this->pdo->prepare('SELECT * FROM lesson_versions WHERE id = ? AND submission_id = ? LIMIT 1'); $s->execute([$versionId, $submissionId]); $row = $s->fetch(PDO::FETCH_ASSOC); if (!$row) throw new PlatformApiException(404, 'lesson_version_not_found', '结构化版本不存在'); return $row; }
    private function suggestions(int $submissionId, int $versionId, array $content = []): array
    {
        $s = $this->pdo->prepare('SELECT s.priority, s.field_path, s.message, s.reason, s.decision, COALESCE(kv.title, k.title, \'\') AS knowledge_item_title FROM lesson_suggestions s LEFT JOIN knowledge_items k ON k.id = s.knowledge_item_id LEFT JOIN knowledge_item_versions kv ON kv.version_id = s.knowledge_version_id WHERE s.submission_id = ? AND s.version_id = ? ORDER BY s.priority DESC, s.id ASC');
        $s->execute([$submissionId, $versionId]); $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $locationQuery = $this->pdo->prepare('SELECT location_map_json FROM lesson_parse_runs WHERE submission_id = ? AND status = \'completed\' ORDER BY created_at DESC, id DESC LIMIT 1'); $locationQuery->execute([$submissionId]);
        $location = json_decode((string) ($locationQuery->fetchColumn() ?: ''), true) ?: []; $mapping = is_array($location['mapping'] ?? null) ? $location['mapping'] : [];
        foreach ($rows as &$row) { $path = (string) ($row['field_path'] ?? ''); $row['location'] = $mapping[$path]['source']['sheet'] ?? ($mapping[$path]['source']['cell'] ?? $path); $row['original_excerpt'] = $this->valueAt($content, $path); $row['revised_content'] = $row['decision'] === 'accepted' ? $row['original_excerpt'] : ''; }
        unset($row); return $rows;
    }
    private function valueAt(array $content, string $path): string
    {
        $value = $content; foreach (explode('.', $path) as $key) { if (!is_array($value) || !array_key_exists($key, $value)) return ''; $value = $value[$key]; }
        return is_array($value) ? implode('、', array_map(static fn($item): string => is_scalar($item) ? (string) $item : '', $value)) : (is_scalar($value) ? (string) $value : '');
    }
    private function sourcePath(array $version): ?string
    {
        $snapshot = json_decode((string) ($version['source_snapshot_json'] ?? ''), true);
        $sourceId = (int) ($snapshot['source_file_id'] ?? 0);
        while ($sourceId <= 0 && (int) ($snapshot['previous_version_id'] ?? 0) > 0 && (int) $snapshot['previous_version_id'] < (int) $version['id']) {
            $version = $this->version((int) $version['submission_id'], (int) $snapshot['previous_version_id']);
            $snapshot = json_decode((string) ($version['source_snapshot_json'] ?? ''), true) ?: [];
            $sourceId = (int) ($snapshot['source_file_id'] ?? 0);
        }
        if ($sourceId <= 0) return null;
        $query = $this->pdo->prepare("SELECT storage_key FROM lesson_source_files WHERE id = ? AND submission_id = ? AND extension = 'xlsx' LIMIT 1"); $query->execute([$sourceId, (int) $version['submission_id']]); $key = $query->fetchColumn();
        if (!is_string($key) || $key === '') return null;
        try { return $this->storage->resolveForRead($key); } catch (Throwable) { return null; }
    }
    private function suggestionRows(array $suggestions): array
    {
        $rows = [['编号', '教案位置', '当前内容', '发现的问题', '判断理由', '修改建议', '知识卡依据', '教练处理', '修改后内容']];
        foreach ($suggestions as $index => $item) $rows[] = [(string) ($index + 1), (string) ($item['location'] ?? $item['field_path'] ?? ''), (string) ($item['original_excerpt'] ?? ''), (string) ($item['issue'] ?? $item['message'] ?? ''), (string) ($item['reason'] ?? ''), (string) ($item['recommendation'] ?? $item['message'] ?? ''), (string) ($item['knowledge_item_title'] ?? ''), (string) ($item['decision'] ?? 'pending'), (string) ($item['revised_content'] ?? '')];
        return $rows;
    }
    private function suggestionSheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="8" customWidth="1"/><col min="2" max="9" width="28" customWidth="1"/></cols><sheetData>';
        foreach ($rows as $r => $row) { $xml .= '<row r="' . ($r + 1) . '">'; foreach ($row as $c => $value) { $ref = ''; $n = $c; do { $ref = chr(65 + ($n % 26)) . $ref; $n = intdiv($n, 26) - 1; } while ($n >= 0); $xml .= '<c r="' . $ref . ($r + 1) . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . $this->xml((string) $value) . '</t></is></c>'; } $xml .= '</row>'; }
        return $xml . '</sheetData><autoFilter ref="A1:I' . max(1, count($rows)) . '"/></worksheet>';
    }
    private function withWrappedCellStyle(string $styles): string
    {
        if (preg_match('/<cellXfs\b[^>]*count="(\d+)"[^>]*>(.*?)<\/cellXfs>/su', $styles, $match) !== 1) return $styles;
        $count = (int) $match[1]; $body = $match[2];
        return substr_replace($styles, '<cellXfs count="' . ($count + 1) . '">' . $body . '<xf applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>', (int) strpos($styles, $match[0]), strlen($match[0]));
    }
    private function audit(int $submissionId, int $versionId, int $staffId, string $action, array $metadata): void { $this->pdo->prepare('INSERT INTO lesson_audit_logs (submission_id, version_id, actor_staff_id, action, metadata_json) VALUES (?, ?, ?, ?, ?)')->execute([$submissionId, $versionId, $staffId, $action, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]); }
}
