<?php

declare(strict_types=1);

final class WorkloadStandardImportParserException extends RuntimeException
{
}

final class WorkloadStandardImportParser
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;
    private const MAX_ROWS = 10000;
    private const REQUIRED_HEADERS = [
        'role_code', 'metric_code', 'metric_name', 'unit', 'is_required', 'allow_zero',
        'min_value', 'max_value', 'target_value', 'need_evidence', 'min_evidence_count',
        'max_evidence_count', 'audit_mode', 'statistic_direction', 'sort_order',
    ];
    private const HEADER_ALIASES = [
        '岗位编码' => 'role_code',
        '项目编码' => 'metric_code',
        '项目名称' => 'metric_name',
        '单位' => 'unit',
        '值类型' => 'value_type',
        '必填' => 'is_required',
        '允许零值' => 'allow_zero',
        '最小值' => 'min_value',
        '最大值' => 'max_value',
        '目标值' => 'target_value',
        '凭证要求' => 'need_evidence',
        '最少凭证' => 'min_evidence_count',
        '最多凭证' => 'max_evidence_count',
        '审核方式' => 'audit_mode',
        '统计方向' => 'statistic_direction',
        '排序' => 'sort_order',
    ];

    public function parse(string $path, string $fileName): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new WorkloadStandardImportParserException('导入文件不可读取');
        }
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new WorkloadStandardImportParserException('导入文件必须大于 0 且不超过 5MB');
        }
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            throw new WorkloadStandardImportParserException('岗位标准导入仅支持 CSV 或 XLSX');
        }
        $matrix = $extension === 'csv' ? $this->csvMatrix($path) : $this->xlsxMatrix($path);
        return [
            'records' => $this->records($matrix),
            'metadata' => [
                'file_name' => basename($fileName),
                'file_sha256' => hash_file('sha256', $path) ?: '',
                'file_size' => $size,
                'file_type' => $extension,
            ],
        ];
    }

    private function csvMatrix(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new WorkloadStandardImportParserException('无法打开 CSV 文件');
        }
        $rows = [];
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = array_map(static fn(mixed $value): string => trim((string) $value), $row);
                if (count($rows) > self::MAX_ROWS + 1) {
                    throw new WorkloadStandardImportParserException('导入文件最多允许 10000 条数据');
                }
            }
        } finally {
            fclose($handle);
        }
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]) ?? $rows[0][0];
        }
        return $rows;
    }

    private function xlsxMatrix(string $path): array
    {
        $archive = file_get_contents($path);
        if (!is_string($archive)) throw new WorkloadStandardImportParserException('XLSX 文件不可读取');
        $entries = $this->zipEntries($archive);
        $sheetName = $this->firstWorksheetName($entries);
        $sheetXml = $entries[$sheetName] ?? null;
        if (!is_string($sheetXml)) throw new WorkloadStandardImportParserException('XLSX 缺少首个工作表');
        $shared = $this->xlsxSharedStrings($entries['xl/sharedStrings.xml'] ?? '');
        $rows = [];
        preg_match_all('/<row\b[^>]*>([\s\S]*?)<\/row>/i', $sheetXml, $rowMatches);
        foreach ($rowMatches[1] as $rowXml) {
            $values = [];
            preg_match_all('/<c\b([^>]*)>([\s\S]*?)<\/c>/i', $rowXml, $cellMatches, PREG_SET_ORDER);
            foreach ($cellMatches as $cell) {
                preg_match('/\br="([A-Z]+)[0-9]+"/i', $cell[1], $referenceMatch);
                preg_match('/\bt="([^"]+)"/i', $cell[1], $typeMatch);
                $index = $this->columnIndex(strtoupper($referenceMatch[1] ?? 'A'));
                $type = $typeMatch[1] ?? '';
                preg_match('/<v\b[^>]*>([\s\S]*?)<\/v>/i', $cell[2], $valueMatch);
                $value = $this->xmlText($valueMatch[1] ?? '');
                if ($type === 's') {
                    $value = $shared[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = $this->joinedXmlText($cell[2]);
                }
                $values[$index] = trim($value);
            }
            if ($values !== []) {
                $max = max(array_keys($values));
                $rows[] = array_map(static fn(int $index): string => $values[$index] ?? '', range(0, $max));
            }
            if (count($rows) > self::MAX_ROWS + 1) throw new WorkloadStandardImportParserException('导入文件最多允许 10000 条数据');
        }
        return $rows;
    }

    private function xlsxSharedStrings(string $xml): array
    {
        $strings = [];
        preg_match_all('/<si\b[^>]*>([\s\S]*?)<\/si>/i', $xml, $matches);
        foreach ($matches[1] as $item) $strings[] = $this->joinedXmlText($item);
        return $strings;
    }

    private function zipEntries(string $archive): array
    {
        $end = strrpos($archive, "PK\x05\x06");
        if ($end === false || strlen($archive) < $end + 22) throw new WorkloadStandardImportParserException('XLSX ZIP 目录无效');
        $directory = unpack('vdisk/vdirectory_disk/ventries_disk/ventries/Vsize/Voffset/vcomment', substr($archive, $end + 4, 18));
        if (!is_array($directory) || $directory['entries'] > 1000) throw new WorkloadStandardImportParserException('XLSX ZIP 条目数量超限');
        $offset = (int) $directory['offset'];
        $entries = [];
        $totalExtracted = 0;
        for ($index = 0; $index < (int) $directory['entries']; $index++) {
            if (substr($archive, $offset, 4) !== "PK\x01\x02") throw new WorkloadStandardImportParserException('XLSX ZIP 中央目录损坏');
            $header = unpack(
                'vmade/vneeded/vflags/vcompression/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length/vcomment_length/vdisk/vinternal/Vexternal/Vlocal_offset',
                substr($archive, $offset + 4, 42)
            );
            if (!is_array($header)) throw new WorkloadStandardImportParserException('XLSX ZIP 条目无效');
            $name = substr($archive, $offset + 46, $header['name_length']);
            $offset += 46 + $header['name_length'] + $header['extra_length'] + $header['comment_length'];
            $wanted = $name === 'xl/sharedStrings.xml' || $name === 'xl/workbook.xml'
                || $name === 'xl/_rels/workbook.xml.rels' || str_starts_with($name, 'xl/worksheets/');
            if (!$wanted) continue;
            if (($header['flags'] & 1) !== 0 || $header['uncompressed'] > 20 * 1024 * 1024) {
                throw new WorkloadStandardImportParserException('XLSX 包含加密或超大条目');
            }
            $localOffset = (int) $header['local_offset'];
            if (substr($archive, $localOffset, 4) !== "PK\x03\x04") throw new WorkloadStandardImportParserException('XLSX ZIP 本地条目损坏');
            $local = unpack('vneeded/vflags/vcompression/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length', substr($archive, $localOffset + 4, 26));
            $dataOffset = $localOffset + 30 + $local['name_length'] + $local['extra_length'];
            $compressed = substr($archive, $dataOffset, $header['compressed']);
            $content = match ((int) $header['compression']) {
                0 => $compressed,
                8 => gzinflate($compressed),
                default => false,
            };
            if (!is_string($content) || strlen($content) !== (int) $header['uncompressed']) {
                throw new WorkloadStandardImportParserException('XLSX ZIP 条目解压失败');
            }
            $totalExtracted += strlen($content);
            if ($totalExtracted > 30 * 1024 * 1024) throw new WorkloadStandardImportParserException('XLSX 解压内容总量超限');
            $entries[$name] = $content;
        }
        return $entries;
    }

    private function firstWorksheetName(array $entries): string
    {
        $workbook = $entries['xl/workbook.xml'] ?? '';
        $relationships = $entries['xl/_rels/workbook.xml.rels'] ?? '';
        if (preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/i', $workbook, $sheet)
            && preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($sheet[1], '/') . '"[^>]*\bTarget="([^"]+)"/i', $relationships, $relation)) {
            return 'xl/' . ltrim(str_replace('\\', '/', $relation[1]), '/');
        }
        $worksheets = array_values(array_filter(array_keys($entries), static fn(string $name): bool => str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')));
        sort($worksheets);
        return $worksheets[0] ?? 'xl/worksheets/sheet1.xml';
    }

    private function joinedXmlText(string $xml): string
    {
        preg_match_all('/<t\b[^>]*>([\s\S]*?)<\/t>/i', $xml, $matches);
        return implode('', array_map([$this, 'xmlText'], $matches[1]));
    }

    private function xmlText(string $value): string
    {
        return html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function records(array $matrix): array
    {
        if (count($matrix) < 2) {
            throw new WorkloadStandardImportParserException('导入文件需要包含表头和至少一条数据');
        }
        $headers = [];
        foreach ($matrix[0] as $header) {
            $header = trim((string) $header);
            $canonical = self::HEADER_ALIASES[$header] ?? strtolower($header);
            if ($canonical === '' || in_array($canonical, $headers, true)) {
                throw new WorkloadStandardImportParserException('导入表头包含空值或重复字段');
            }
            $headers[] = $canonical;
        }
        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        if ($missing !== []) {
            throw new WorkloadStandardImportParserException('导入表头缺少字段：' . implode(', ', $missing));
        }
        $records = [];
        foreach (array_slice($matrix, 1) as $offset => $values) {
            if (count(array_filter($values, static fn(mixed $value): bool => trim((string) $value) !== '')) === 0) continue;
            $record = ['_row_number' => $offset + 2];
            foreach ($headers as $index => $header) $record[$header] = trim((string) ($values[$index] ?? ''));
            $records[] = $record;
        }
        if ($records === []) throw new WorkloadStandardImportParserException('导入文件没有有效数据行');
        return $records;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) $index = ($index * 26) + ord($letter) - 64;
        return max(0, $index - 1);
    }
}
