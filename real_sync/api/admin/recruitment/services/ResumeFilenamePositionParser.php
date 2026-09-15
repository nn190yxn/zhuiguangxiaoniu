<?php

declare(strict_types=1);

final class ResumeFilenamePositionParser
{
    /** @var array<string, list<string>> */
    private array $aliasesByPosition;

    /**
     * @param array<string, list<string>> $aliasesByPosition
     */
    public function __construct(array $aliasesByPosition = [])
    {
        $defaults = [
            '店长' => ['店长', '门店店长', '校区店长'],
            '教练' => ['教练', '跑酷教练', '体适能教练', '体能教练', '儿童教练'],
            '教学主管' => ['教学主管', '教务主管', '主管'],
            '销售顾问' => ['销售顾问', '课程顾问', '销售', '顾问'],
            '督导' => ['督导', '门店督导', '运营督导'],
            '线上运营专员' => ['线上运营专员', '线上运营', '新媒体运营', '新媒体', '运营专员', '运营'],
        ];
        $this->aliasesByPosition = $aliasesByPosition !== [] ? $aliasesByPosition : $defaults;
    }

    /**
     * @return list<array{position_name_snapshot:string, matched_alias:string, source_text:string, confidence_boost:float}>
     */
    public function parse(string $filename): array
    {
        $sourceText = $this->normalizeSourceText($filename);
        return $this->parseText($sourceText);
    }

    /**
     * @return list<array{position_name_snapshot:string, matched_alias:string, source_text:string, confidence_boost:float}>
     */
    public function parseText(string $sourceText): array
    {
        $sourceText = trim($sourceText);
        if ($sourceText === '') {
            return [];
        }

        $matches = [];
        foreach ($this->sortedAliases() as $alias) {
            if (!str_contains($sourceText, $alias['alias'])) {
                continue;
            }
            $position = $alias['position'];
            $boost = $this->confidenceBoost($alias['alias'], $position);
            if (isset($matches[$position]) && !$this->shouldReplaceMatch($matches[$position], $alias['alias'], $boost)) {
                continue;
            }
            $matches[$position] = [
                'position_name_snapshot' => $position,
                'matched_alias' => $alias['alias'],
                'source_text' => $sourceText,
                'confidence_boost' => $boost,
            ];
        }

        usort(
            $matches,
            static fn(array $left, array $right): int => $right['confidence_boost'] <=> $left['confidence_boost']
                ?: strlen($right['matched_alias']) <=> strlen($left['matched_alias'])
                ?: strcmp($left['position_name_snapshot'], $right['position_name_snapshot'])
        );

        return array_values($matches);
    }

    private function normalizeSourceText(string $filename): string
    {
        $baseName = basename(str_replace('\\', '/', $filename));
        $withoutExtension = preg_replace('/\.[^.]+$/u', '', $baseName) ?? $baseName;
        $normalized = preg_replace('/[\s_\-+()（）\[\]【】]+/u', '', $withoutExtension) ?? $withoutExtension;
        return trim($normalized);
    }

    /**
     * @return list<array{position:string, alias:string}>
     */
    private function sortedAliases(): array
    {
        $aliases = [];
        foreach ($this->aliasesByPosition as $position => $positionAliases) {
            foreach ($positionAliases as $alias) {
                $alias = trim($alias);
                if ($alias === '') {
                    continue;
                }
                $aliases[] = ['position' => $position, 'alias' => $alias];
            }
        }

        usort(
            $aliases,
            static fn(array $left, array $right): int => strlen($right['alias']) <=> strlen($left['alias'])
                ?: strcmp($left['alias'], $right['alias'])
        );

        return $aliases;
    }

    private function confidenceBoost(string $alias, string $position): float
    {
        if ($alias === $position) {
            return 0.35;
        }
        if (str_contains($alias, $position)) {
            return 0.30;
        }
        return 0.25;
    }

    /**
     * @param array{matched_alias:string, confidence_boost:float} $current
     */
    private function shouldReplaceMatch(array $current, string $nextAlias, float $nextBoost): bool
    {
        if ($nextBoost > $current['confidence_boost']) {
            return true;
        }
        if ($nextBoost < $current['confidence_boost']) {
            return false;
        }
        return strlen($nextAlias) > strlen($current['matched_alias']);
    }
}
