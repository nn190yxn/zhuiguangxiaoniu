<?php

declare(strict_types=1);

final class LessonWordParserException extends RuntimeException
{
}

final class LessonWordParser
{
    private const FIELD_ALIASES = [
        '门店' => 'store_name', '门店名称' => 'store_name', '姓名' => 'author_name', '作者' => 'author_name',
        '课程线' => 'course_line', '班级' => 'class_level', '级别' => 'class_level', '上课日期' => 'lesson_date',
        '日期' => 'lesson_date', '标题' => 'title', '教案标题' => 'title', 'a目标' => 'athletic_objective',
        '身体目标' => 'athletic_objective', 'c目标' => 'cognitive_objective', '认知目标' => 'cognitive_objective',
        'e目标' => 'engagement_objective', '参与目标' => 'engagement_objective', '学员关注' => 'learner_focus',
        '重点学员' => 'learner_focus', '安全' => 'physical_safety', '身体安全' => 'physical_safety',
        '心理安全' => 'psychological_safety', '器材' => 'equipment', '助教分工' => 'assistant_responsibilities',
        '课后反思' => 'reflection', '反思' => 'reflection',
    ];

    public function parse(string $path, string $fileName): array
    {
        if (!is_file($path) || !is_readable($path)) throw new LessonWordParserException('教案文件不可读取');
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === 'doc') throw new LessonWordParserException('旧版 DOC 暂不支持自动解析，请使用 DOCX 或手工录入');
        if ($extension !== 'docx') throw new LessonWordParserException('Word 解析器仅支持 DOCX');
        if (!class_exists(ZipArchive::class)) throw new LessonWordParserException('服务器缺少 DOCX ZIP 解析能力');

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new LessonWordParserException('DOCX 文件损坏或无法打开');
        try {
            $raw = $zip->getFromName('word/document.xml');
            if (!is_string($raw) || trim($raw) === '') throw new LessonWordParserException('DOCX 缺少正文文件');
            $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) throw new LessonWordParserException('DOCX 正文 XML 无效');
            $content = $this->emptyContent();
            $blocks = [];
            $paragraphNumber = 0;
            $tableNumber = 0;
            foreach ($xml->xpath('//*[local-name()="body"]/*') ?: [] as $node) {
                $name = strtolower($node->getName());
                if ($name === 'p') {
                    $paragraphNumber++;
                    $block = $this->paragraph($node, $paragraphNumber);
                    if ($block['text'] !== '') {
                        $blocks[] = $block;
                        if ($block['type'] === 'heading' && $content['metadata']['title'] === '') {
                            $this->assign($content, 'title', $block['text'], ['paragraph' => $paragraphNumber]);
                        }
                        $this->mapText($content, $block['text'], ['paragraph' => $paragraphNumber]);
                    }
                } elseif ($name === 'tbl') {
                    $tableNumber++;
                    $table = $this->table($node, $tableNumber, $paragraphNumber);
                    $blocks[] = $table;
                    $this->mapTable($content, $table);
                }
            }
            if ($blocks === []) throw new LessonWordParserException('DOCX 正文为空');
            return [
                'format' => 'docx',
                'metadata' => ['file_name' => basename($fileName), 'file_size' => filesize($path) ?: 0, 'file_sha256' => hash_file('sha256', $path) ?: ''],
                'blocks' => $blocks,
                'content' => $content,
            ];
        } finally {
            $zip->close();
        }
    }

    private function paragraph(SimpleXMLElement $node, int $number): array
    {
        $texts = $node->xpath('.//*[local-name()="t"]') ?: [];
        $text = trim(implode('', array_map(static fn(SimpleXMLElement $part): string => (string) $part, $texts)));
        $style = '';
        $styleNode = $node->xpath('./*[local-name()="pPr"]/*[local-name()="pStyle"]')[0] ?? null;
        if ($styleNode !== null) $style = (string) ($styleNode->attributes('w', true)['val'] ?? '');
        $numNode = $node->xpath('./*[local-name()="pPr"]/*[local-name()="numPr"]')[0] ?? null;
        $levelNode = $numNode !== null ? ($numNode->xpath('./*[local-name()="ilvl"]')[0] ?? null) : null;
        return ['type' => $this->isHeading($style) ? 'heading' : ($numNode !== null ? 'list_item' : 'paragraph'), 'number' => $number, 'style' => $style, 'list_level' => $levelNode !== null ? (int) ($levelNode->attributes('w', true)['val'] ?? 0) : null, 'text' => $text, 'source' => ['paragraph' => $number]];
    }

    private function table(SimpleXMLElement $node, int $tableNumber, int $paragraphNumber): array
    {
        $rows = [];
        foreach ($node->xpath('./*[local-name()="tr"]') ?: [] as $rowIndex => $rowNode) {
            $cells = [];
            foreach ($rowNode->xpath('./*[local-name()="tc"]') ?: [] as $cellIndex => $cellNode) {
                $texts = $cellNode->xpath('.//*[local-name()="t"]') ?: [];
                $text = trim(implode('', array_map(static fn(SimpleXMLElement $part): string => (string) $part, $texts)));
                $cells[] = ['column' => $cellIndex + 1, 'text' => $text, 'source' => ['table' => $tableNumber, 'row' => $rowIndex + 1, 'column' => $cellIndex + 1]];
            }
            if ($cells !== []) $rows[] = ['row' => $rowIndex + 1, 'cells' => $cells];
        }
        return ['type' => 'table', 'number' => $tableNumber, 'rows' => $rows, 'source' => ['table' => $tableNumber]];
    }

    private function mapText(array &$content, string $text, array $source): void
    {
        $parts = preg_split('/\s*[：:]\s*/u', $text, 2);
        if (count($parts) === 2) {
            $this->assign($content, $this->field($parts[0]), trim($parts[1]), $source);
        } elseif ($this->isHeading($text) && $content['metadata']['title'] === '') {
            $this->assign($content, 'title', $text, $source);
        }
    }

    private function mapTable(array &$content, array $table): void
    {
        foreach ($table['rows'] as $row) {
            $cells = $row['cells'];
            foreach ($cells as $index => $cell) {
                $field = $this->field($cell['text']);
                if ($field === null || !isset($cells[$index + 1])) continue;
                $value = trim((string) $cells[$index + 1]['text']);
                if ($value !== '') $this->assign($content, $field, $value, $cells[$index + 1]['source']);
            }
        }
    }

    private function field(string $label): ?string
    {
        return self::FIELD_ALIASES[$this->normalize($label)] ?? null;
    }

    private function assign(array &$content, ?string $field, string $value, array $source): void
    {
        if ($field === null || $value === '') return;
        $paths = ['store_name' => ['metadata', 'store_name'], 'author_name' => ['metadata', 'author_name'], 'course_line' => ['metadata', 'course_line'], 'class_level' => ['metadata', 'class_level'], 'lesson_date' => ['metadata', 'lesson_date'], 'title' => ['metadata', 'title'], 'athletic_objective' => ['objectives', 'athletic'], 'cognitive_objective' => ['objectives', 'cognitive'], 'engagement_objective' => ['objectives', 'engagement'], 'learner_focus' => ['learner_focus'], 'physical_safety' => ['safety', 'physical'], 'psychological_safety' => ['safety', 'psychological'], 'assistant_responsibilities' => ['assistant_responsibilities']];
        if ($field === 'equipment') { $content['equipment'][] = ['value' => $value, 'source' => $source]; return; }
        if ($field === 'reflection') { $content['reflection']['engagement'] = $value; $content['mapping'][$field] = ['value' => $value, 'source' => $source]; return; }
        $path = $paths[$field] ?? null;
        if ($path === null) return;
        if (count($path) === 2) $content[$path[0]][$path[1]] = $value;
        else $content[$path[0]] = $value;
        $content['mapping'][$field] = ['value' => $value, 'source' => $source];
    }

    private function normalize(string $value): string
    {
        return strtolower(preg_replace('/[\s:：()（）]/u', '', trim($value)) ?? trim($value));
    }

    private function isHeading(string $style): bool
    {
        return preg_match('/^(heading|标题|title)/iu', trim($style)) === 1;
    }

    private function emptyContent(): array
    {
        return ['metadata' => ['title' => ''], 'objectives' => ['athletic' => '', 'cognitive' => '', 'engagement' => ''], 'learner_focus' => '', 'safety' => ['physical' => '', 'psychological' => ''], 'equipment' => [], 'phases' => [], 'progressions' => [], 'assistant_responsibilities' => '', 'reflection' => ['athletic' => '', 'cognitive' => '', 'engagement' => ''], 'mapping' => []];
    }
}
