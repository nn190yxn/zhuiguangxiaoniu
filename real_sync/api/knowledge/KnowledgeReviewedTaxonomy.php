<?php
declare(strict_types=1);

final class KnowledgeReviewedTaxonomy
{
    private static ?array $release = null;

    public static function release(): array
    {
        if (self::$release !== null) return self::$release;
        $path = __DIR__ . '/../../database/knowledge_reviewed_taxonomy.v1.json';
        if (!is_file($path)) return self::$release = ['release_version' => '', 'topics' => [], 'entries' => []];
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema_version'] ?? '') !== 'knowledge-reviewed-taxonomy.v1'
            || empty($data['release_version']) || !is_array($data['topics'] ?? null) || !is_array($data['entries'] ?? null)) {
            throw new RuntimeException('Invalid reviewed taxonomy release');
        }
        foreach ($data['entries'] as $id => $entry) {
            if (!ctype_digit((string)$id) || (int)$id < 1
                || !ctype_digit((string)($entry['version_id'] ?? '')) || (int)$entry['version_id'] < 1
                || !preg_match('/^[a-f0-9]{64}$/D', (string)($entry['content_sha256'] ?? ''))
                || !preg_match('/^[a-z][a-z0-9_]*$/D', (string)($entry['subtopic_code'] ?? ''))
                || !isset($data['topics'][$entry['topic_code']]['subcategories'][$entry['subtopic_code']])) {
                throw new RuntimeException('Invalid reviewed taxonomy entry');
            }
        }
        return self::$release = $data;
    }

    public static function classify(array $item): ?array
    {
        $release = self::release();
        $entry = $release['entries'][(string)($item['id'] ?? '')] ?? null;
        if ($entry === null || (int)($item['version_id'] ?? 0) !== (int)$entry['version_id']) return null;
        $hash = $item['content_sha256'] ?? hash('sha256', (string)($item['content'] ?? ''));
        if (!is_string($hash) || !hash_equals($entry['content_sha256'], $hash)) return null;
        $topic = $release['topics'][$entry['topic_code']];
        return [
            'primary_category' => 'professional',
            'primary_category_label' => '专业知识',
            'subcategory_code' => $entry['subtopic_code'],
            'subcategory_label' => $topic['subcategories'][$entry['subtopic_code']],
            'topic_code' => $entry['topic_code'],
            'topic_label' => $topic['label'],
            'taxonomy_mapping_version' => $release['release_version'],
            'topic_review_status' => 'confirmed',
            'topic_source_review_status' => $entry['source_review_status'],
            'content_quality_flag' => $entry['quality_flag'],
        ];
    }

    public static function subcategorySql(string $content): string
    {
        $entries = self::release()['entries'];
        if ($entries === []) return 'NULL';
        // Matching the full source digest also protects against edits without a version bump.
        $sql = "CASE CONCAT(k.id, ':', kv.version_id, ':', SHA2($content, 256))";
        foreach ($entries as $id => $entry) {
            $key = $id . ':' . $entry['version_id'] . ':' . $entry['content_sha256'];
            $sql .= " WHEN '$key' THEN '" . $entry['subtopic_code'] . "'";
        }
        return $sql . ' ELSE NULL END';
    }
}
