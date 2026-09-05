<?php

declare(strict_types=1);

final class LessonWorkbookParserException extends RuntimeException
{
}

final class LessonWorkbookParser
{
    private const MAX_SHEETS = 50;
    private const MAX_ROWS = 10000;
    private const MAX_CELLS = 200000;

    private const FIELD_ALIASES = [
        '门店' => 'store_name', '门店名称' => 'store_name', '姓名' => 'author_name', '作者' => 'author_name',
        '课程线' => 'course_line', '班级' => 'class_level', '级别' => 'class_level', '上课日期' => 'lesson_date',
        '日期' => 'lesson_date', '标题' => 'title', '教案标题' => 'title',
        'a目标' => 'athletic_objective', 'a维度目标' => 'athletic_objective', '身体目标' => 'athletic_objective',
        'c目标' => 'cognitive_objective', 'c维度目标' => 'cognitive_objective', '认知目标' => 'cognitive_objective',
        'e目标' => 'engagement_objective', 'e维度目标' => 'engagement_objective', '参与目标' => 'engagement_objective',
        '学员关注' => 'learner_focus', '重点学员' => 'learner_focus', '安全' => 'physical_safety',
        '身体安全' => 'physical_safety', '心理安全' => 'psychological_safety', '器材' => 'equipment',
        '助教分工' => 'assistant_responsibilities', '课后反思' => 'reflection', '反思' => 'reflection',
    ];

    public function parse(string $path, string $fileName): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new LessonWorkbookParserException('教案文件不可读取');
        }
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === 'xls') {
            throw new LessonWorkbookParserException('旧版 XLS 暂不支持自动解析，请使用 XLSX 或手工录入');
        }
        if ($extension !== 'xlsx') {
            throw new LessonWorkbookParserException('Excel 解析器仅支持 XLSX');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new LessonWorkbookParserException('服务器缺少 XLSX ZIP 解析能力');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new LessonWorkbookParserException('XLSX 文件损坏或无法打开');
        }
        try {
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relations = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $sharedStrings = $this->sharedStrings($zip);
            $relationshipMap = $this->relationshipMap($relations);
            $sheets = [];
            $content = $this->emptyContent();
            $cellCount = 0;
            $sheetNodes = $workbook->xpath('//*[local-name()="sheet"]') ?: [];
            if (count($sheetNodes) > self::MAX_SHEETS) {
                throw new LessonWorkbookParserException('XLSX 工作表数量超限');
            }
            foreach ($sheetNodes as $sheetIndex => $sheetNode) {
                $attributes = $sheetNode->attributes('r', true);
                $relationId = (string) ($attributes['id'] ?? '');
                $target = $relationshipMap[$relationId] ?? '';
                if ($target === '') {
                    continue;
                }
                $entry = str_starts_with($target, 'xl/') ? $target : 'xl/' . ltrim(str_replace('\\', '/', $target), '/');
                $sheetXml = $this->xml($zip, $entry);
                $parsed = $this->sheet($sheetXml, $sharedStrings, $cellCount);
                $cellCount += count($parsed['cells']);
                if ($cellCount > self::MAX_CELLS) {
                    throw new LessonWorkbookParserException('XLSX 单元格数量超限');
                }
                $sheets[] = [
                    'index' => $sheetIndex + 1,
                    'name' => (string) ($sheetNode['name'] ?? ('Sheet' . ($sheetIndex + 1))),
                    'source_entry' => $entry,
                    ...$parsed,
                ];
                $this->mapFields($content, $parsed['cells'], $sheets[array_key_last($sheets)]['name']);
            }
            if ($sheets === []) {
                throw new LessonWorkbookParserException('XLSX 不包含可解析的工作表');
            }
            return [
                'format' => 'xlsx',
                'metadata' => [
                    'file_name' => basename($fileName),
                    'file_size' => filesize($path) ?: 0,
                    'file_sha256' => hash_file('sha256', $path) ?: '',
                ],
                'sheets' => $sheets,
                'content' => $content,
            ];
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $entry): SimpleXMLElement
    {
        $raw = $zip->getFromName($entry);
        if (!is_string($raw) || trim($raw) === '') {
            throw new LessonWorkbookParserException('XLSX 缺少文件：' . $entry);
        }
        $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if (!$xml) {
            throw new LessonWorkbookParserException('XLSX XML 无效：' . $entry);
        }
        return $xml;
    }

    private function relationshipMap(SimpleXMLElement $relations): array
    {
        $map = [];
        foreach ($relations->xpath('//*[local-name()="Relationship"]') ?: [] as $relation) {
            $target = str_replace('\\', '/', (string) ($relation['Target'] ?? ''));
            $map[(string) ($relation['Id'] ?? '')] = str_starts_with($target, '/') || str_starts_with($target, 'xl/')
                ? ltrim($target, '/')
                : 'xl/' . ltrim($target, '/');
        }
        return $map;
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($raw)) return [];
        $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if (!$xml) throw new LessonWorkbookParserException('共享字符串 XML 无效');
        $result = [];
        foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
            $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $result[] = implode('', array_map(static fn(SimpleXMLElement $part): string => (string) $part, $parts));
        }
        return $result;
    }

    private function sheet(SimpleXMLElement $xml, array $sharedStrings, int $existingCellCount): array
    {
        $cells = [];
        $rows = [];
        foreach ($xml->xpath('//*[local-name()="row"]') ?: [] as $rowNode) {
            $rowNumber = (int) ($rowNode['r'] ?? 0);
            if ($rowNumber < 1 || $rowNumber > self::MAX_ROWS) continue;
            $rowCells = [];
            foreach ($rowNode->xpath('./*[local-name()="c"]') ?: [] as $cellNode) {
                $reference = strtoupper((string) ($cellNode['r'] ?? ''));
                if ($reference === '') continue;
                $type = (string) ($cellNode['t'] ?? '');
                $valueNode = $cellNode->xpath('./*[local-name()="v"]')[0] ?? null;
                $value = $valueNode ? (string) $valueNode : '';
                if ($type === 's') $value = $sharedStrings[(int) $value] ?? '';
                if ($type === 'inlineStr') {
                    $textNodes = $cellNode->xpath('.//*[local-name()="t"]') ?: [];
                    $value = implode('', array_map(static fn(SimpleXMLElement $part): string => (string) $part, $textNodes));
                }
                if ($type === 'b') $value = $value === '1' ? 'TRUE' : 'FALSE';
                $cell = ['reference' => $reference, 'row' => $rowNumber, 'value' => trim($value)];
                $cells[] = $cell;
                $rowCells[] = $cell;
            }
            if ($rowCells !== []) $rows[] = ['row' => $rowNumber, 'cells' => $rowCells];
        }
        $mergedRanges = [];
        foreach ($xml->xpath('//*[local-name()="mergeCell"]') ?: [] as $merge) {
            $ref = trim((string) ($merge['ref'] ?? ''));
            if ($ref !== '') $mergedRanges[] = $ref;
        }
        return ['rows' => $rows, 'cells' => $cells, 'merged_ranges' => $mergedRanges];
    }

    private function mapFields(array &$content, array $cells, string $sheetName): void
    {
        foreach ($cells as $index => $cell) {
            $label = $this->normalize((string) $cell['value']);
            $field = self::FIELD_ALIASES[$label] ?? null;
            if ($field === null) continue;
            $next = $cells[$index + 1]['value'] ?? '';
            if (trim((string) $next) === '') continue;
            $content['mapping'][$field] = ['value' => trim((string) $next), 'source' => ['sheet' => $sheetName, 'cell' => $cells[$index + 1]['reference']]];
            $this->assign($content, $field, trim((string) $next));
        }
    }

    private function assign(array &$content, string $field, string $value): void
    {
        $map = ['store_name' => ['metadata', 'store_name'], 'author_name' => ['metadata', 'author_name'], 'course_line' => ['metadata', 'course_line'], 'class_level' => ['metadata', 'class_level'], 'lesson_date' => ['metadata', 'lesson_date'], 'title' => ['metadata', 'title'], 'athletic_objective' => ['objectives', 'athletic'], 'cognitive_objective' => ['objectives', 'cognitive'], 'engagement_objective' => ['objectives', 'engagement'], 'learner_focus' => ['learner_focus'], 'physical_safety' => ['safety', 'physical'], 'psychological_safety' => ['safety', 'psychological'], 'equipment' => ['equipment'], 'assistant_responsibilities' => ['assistant_responsibilities'], 'reflection' => ['reflection', 'engagement']];
        $path = $map[$field] ?? null;
        if ($path === null) return;
        if (count($path) === 1) $content[$path[0]] = $value;
        else $content[$path[0]][$path[1]] = $value;
    }

    private function normalize(string $value): string
    {
        return strtolower(preg_replace('/[\s:：()（）]/u', '', trim($value)) ?? trim($value));
    }

    private function emptyContent(): array
    {
        return ['metadata' => [], 'objectives' => ['athletic' => '', 'cognitive' => '', 'engagement' => ''], 'learner_focus' => '', 'safety' => ['physical' => '', 'psychological' => ''], 'equipment' => [], 'phases' => [], 'progressions' => [], 'assistant_responsibilities' => '', 'reflection' => ['athletic' => '', 'cognitive' => '', 'engagement' => ''], 'mapping' => []];
    }
}
