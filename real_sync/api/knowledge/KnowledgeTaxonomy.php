<?php
declare(strict_types=1);

final class KnowledgeTaxonomy
{
    private const MAPPING_PATH = __DIR__ . '/../../database/knowledge_taxonomy_mapping.v1.json';

    private static ?array $activeMapping = null;

    public static function mappingVersion(): string
    {
        return (string)self::activeMapping()['mapping_version'];
    }

    public static function primaryCategories(): array
    {
        return self::activeMapping()['primary_categories'];
    }

    public static function lines(): array
    {
        $lines = [];
        foreach (self::primaryCategories() as $code => $category) {
            $lines[$code] = $category['label'];
        }
        return $lines;
    }

    public static function subcategories(): array
    {
        $subcategories = [];
        foreach (self::primaryCategories() as $code => $category) {
            $subcategories[$code] = $category['subcategories'];
        }
        return $subcategories;
    }

    public static function domainMappings(): array
    {
        return self::activeMapping()['domain_mappings'];
    }

    public static function mapDomain(string $domainCode): ?array
    {
        $domainCode = strtolower(trim($domainCode));
        $mapping = self::domainMappings()[$domainCode] ?? null;
        return is_array($mapping) && ($mapping['status'] ?? '') === 'active' ? $mapping : null;
    }

    public static function domainCodesForPrimaryCategory(string $primaryCategory): array
    {
        $codes = [];
        foreach (self::domainMappings() as $domainCode => $mapping) {
            if (($mapping['status'] ?? '') === 'active' && ($mapping['primary_category'] ?? '') === $primaryCategory) {
                $codes[] = $domainCode;
            }
        }
        return $codes;
    }

    public static function classify(array $item): array
    {
        $domain = strtolower(trim((string)($item['domain_code'] ?? '')));
        $type = strtolower(trim((string)($item['content_type'] ?? '')));
        $tags = $item['tags'] ?? '';
        $tagsText = is_array($tags) ? implode(' ', array_map('strval', $tags)) : (string)$tags;
        $text = mb_strtolower(implode(' ', [
            (string)($item['title'] ?? ''),
            (string)($item['summary'] ?? ''),
            $tagsText,
        ]));

        $domainMapping = self::mapDomain($domain);
        if ($domainMapping !== null) {
            return self::classification(
                (string)$domainMapping['primary_category'],
                (string)$domainMapping['subcategory_code']
            );
        }

        $salesTerms = ['销售', '接待', '需求', '体验课', '家长沟通', '异议', '成交', '续费', '话术', '顾问'];
        $isSales = $domain === 'sales' || $type === 'script';
        foreach ($salesTerms as $term) {
            if (mb_strpos($text, $term) !== false) {
                $isSales = true;
                break;
            }
        }

        $line = $isSales ? 'sales' : 'professional';
        $subcategory = self::subcategory($line, $domain, $type, $text);
        return self::classification($line, $subcategory);
    }

    private static function classification(string $line, string $subcategory): array
    {
        $lines = self::lines();
        $subcategories = self::subcategories();
        return [
            'primary_category' => $line,
            'primary_category_label' => $lines[$line],
            'subcategory_code' => $subcategory,
            'subcategory_label' => $subcategories[$line][$subcategory],
            'taxonomy_mapping_version' => self::mappingVersion(),
        ];
    }

    private static function activeMapping(): array
    {
        if (self::$activeMapping !== null) {
            return self::$activeMapping;
        }

        $source = json_decode((string)file_get_contents(self::MAPPING_PATH), true);
        if (!is_array($source) || ($source['schema_version'] ?? '') !== 'knowledge-taxonomy-mapping.v1') {
            throw new RuntimeException('Invalid knowledge taxonomy mapping source');
        }
        $activeVersion = (string)($source['active_mapping_version'] ?? '');
        $versions = $source['versions'] ?? null;
        $activeMappings = is_array($versions) ? array_values(array_filter(
            $versions,
            static fn(mixed $version): bool => is_array($version) && ($version['status'] ?? '') === 'active'
        )) : [];
        if ($activeVersion === '' || count($activeMappings) !== 1
            || ($activeMappings[0]['mapping_version'] ?? '') !== $activeVersion) {
            throw new RuntimeException('Knowledge taxonomy must define one active mapping version');
        }

        $mapping = $activeMappings[0];
        $categories = $mapping['primary_categories'] ?? null;
        $domainMappings = $mapping['domain_mappings'] ?? null;
        if (!is_array($categories) || !is_array($domainMappings)) {
            throw new RuntimeException('Knowledge taxonomy active mapping is incomplete');
        }
        foreach ($domainMappings as $domainCode => $domainMapping) {
            $primary = is_array($domainMapping) ? (string)($domainMapping['primary_category'] ?? '') : '';
            $subcategory = is_array($domainMapping) ? (string)($domainMapping['subcategory_code'] ?? '') : '';
            if ($domainCode === '' || ($domainMapping['status'] ?? '') !== 'active'
                || !isset($categories[$primary]['subcategories'][$subcategory])) {
                throw new RuntimeException('Knowledge taxonomy contains an invalid domain mapping');
            }
        }

        self::$activeMapping = $mapping;
        return self::$activeMapping;
    }

    private static function subcategory(string $line, string $domain, string $type, string $text): string
    {
        if ($line === 'sales') {
            if ($type === 'script' || mb_strpos($text, '话术') !== false) return 'sales_script';
            if (mb_strpos($text, '续费') !== false) return 'renewal';
            if (mb_strpos($text, '成交') !== false) return 'conversion';
            if (mb_strpos($text, '体验') !== false) return 'trial_class';
            if (mb_strpos($text, '需求') !== false) return 'needs_analysis';
            if (mb_strpos($text, '异议') !== false) return 'objection_handling';
            if (mb_strpos($text, '体测') !== false) return 'fitness_explanation';
            if (mb_strpos($text, '家长') !== false) return 'parent_communication';
            return 'reception';
        }

        if (in_array($type, ['action', 'game'], true)) return 'action_game';
        if ($type === 'lesson' || mb_strpos($text, '教案') !== false) return 'lesson_reference';
        if (in_array($domain, ['fitness', 'coach'], true)) return $domain === 'coach' ? 'coach_growth' : 'fitness';
        if ($domain === 'sensory') return 'sensory';
        if (in_array($domain, ['g01', 'child_development'], true)) return 'child_development';
        if (in_array($domain, ['g05', 'teaching'], true)) return 'teaching';
        if (in_array($domain, ['g07', 'safety'], true)) return 'safety';
        if (in_array($domain, ['g08', 'coach_growth'], true)) return 'coach_growth';
        if (mb_strpos($text, '体测') !== false || mb_strpos($text, '评估') !== false) return 'assessment';
        return 'fitness';
    }
}
