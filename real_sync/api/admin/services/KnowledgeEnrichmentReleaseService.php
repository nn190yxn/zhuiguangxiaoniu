<?php
declare(strict_types=1);

final class KnowledgeEnrichmentReleaseService
{
    public function __construct(private PDO $db) {}

    public function publish(array $input, array $actor): array
    {
        $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? []))));
        if (!$ids) throw new InvalidArgumentException('请选择要发布的增强稿');
        $batch = trim((string)($input['release_batch_id'] ?? '')) ?: 'enrichment-' . gmdate('YmdHis');
        $this->db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT r.*, k.current_version_id, v.content AS current_source_content FROM knowledge_enrichment_records r INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id INNER JOIN knowledge_item_versions v ON v.version_id = k.current_version_id AND v.knowledge_item_id = k.id WHERE r.id IN ($placeholders) FOR UPDATE");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) throw new RuntimeException('存在不存在的增强任务');
            foreach ($rows as $row) {
                if ($row['review_status'] !== 'approved' || !$row['enriched_content_json'] || (int)$row['source_version_id'] !== (int)$row['current_version_id']) throw new RuntimeException('存在未通过审核或源版本已过期的任务');
                $currentSourceHash = hash('sha256', (string)$row['current_source_content']);
                if (!hash_equals($currentSourceHash, (string)$row['source_content_sha256'])) throw new RuntimeException('存在源正文哈希不一致的任务');
                $decodedContent = json_decode((string)$row['enriched_content_json'], true);
                $hash = hash('sha256', $this->canonicalJson($decodedContent));
                if (!hash_equals($hash, (string)$row['enriched_content_sha256'])) throw new RuntimeException('增强稿哈希校验失败');
                $content = $decodedContent;
                if (!is_array($content) || !empty($content['needs_human_review'])) throw new RuntimeException('存在尚未完成人工确认的任务');
                $citations = $content['citations'] ?? [];
                if (!is_array($citations) || !$citations || count(array_filter($citations, static fn($citation): bool => is_array($citation) && trim((string)($citation['excerpt'] ?? '')) !== '' && str_contains((string)$row['current_source_content'], (string)$citation['excerpt']))) !== count($citations)) throw new RuntimeException('存在引用缺失或引用不属于当前正文的任务');
            }
            $snapshot = $this->db->prepare('INSERT INTO knowledge_enrichment_release_items (release_batch_id, enrichment_record_id, before_enrichment_status, before_review_status, before_release_batch_id, before_content_json, before_content_sha256, after_content_sha256) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $row) {
                $snapshot->execute([$batch, (int)$row['id'], $row['enrichment_status'], $row['review_status'], $row['release_batch_id'], $row['enriched_content_json'], $row['enriched_content_sha256'], $row['enriched_content_sha256']]);
            }
            $update = $this->db->prepare("UPDATE knowledge_enrichment_records SET enrichment_status = 'published', release_batch_id = ? WHERE id IN ($placeholders)");
            $update->execute(array_merge([$batch], $ids));
            $this->audit($actor, 'enrichment_publish', [], ['release_batch_id' => $batch, 'record_ids' => $ids, 'count' => count($ids)]);
            $this->db->commit();
            return ['release_batch_id' => $batch, 'published_count' => $update->rowCount()];
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function rollback(string $batch, array $actor): array
    {
        if ($batch === '') throw new InvalidArgumentException('缺少发布批次');
        $this->db->beginTransaction();
        $stmt = $this->db->prepare("SELECT * FROM knowledge_enrichment_release_items WHERE release_batch_id = ? AND rolled_back_at IS NULL FOR UPDATE");
        $stmt->execute([$batch]);
        $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $restore = $this->db->prepare('UPDATE knowledge_enrichment_records SET enrichment_status = ?, review_status = ?, release_batch_id = ?, enriched_content_json = ?, enriched_content_sha256 = ? WHERE id = ? AND enrichment_status = \'published\'');
        foreach ($snapshots as $snapshot) {
            $restore->execute([$snapshot['before_enrichment_status'], $snapshot['before_review_status'], $snapshot['before_release_batch_id'], $snapshot['before_content_json'], $snapshot['before_content_sha256'], $snapshot['enrichment_record_id']]);
        }
        $stmt = $this->db->prepare('UPDATE knowledge_enrichment_release_items SET rolled_back_at = NOW() WHERE release_batch_id = ? AND rolled_back_at IS NULL');
        $stmt->execute([$batch]);
        $this->audit($actor, 'enrichment_rollback', [], ['release_batch_id' => $batch, 'count' => $stmt->rowCount()]);
        $this->db->commit();
        return ['release_batch_id' => $batch, 'rolled_back_count' => count($snapshots)];
    }

    public function report(string $batch): array
    {
        $sql = 'SELECT enrichment_status, COUNT(*) AS total FROM knowledge_enrichment_records' . ($batch !== '' ? ' WHERE release_batch_id = ?' : '') . ' GROUP BY enrichment_status';
        $stmt = $this->db->prepare($sql); $stmt->execute($batch !== '' ? [$batch] : []);
        return ['release_batch_id' => $batch, 'counts' => $stmt->fetchAll(PDO::FETCH_KEY_PAIR)];
    }

    private function audit(array $actor, string $action, array $ids, array $metadata): void
    {
        $stmt = $this->db->prepare('INSERT INTO knowledge_audit_logs (actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$actor['user_id'] ?? null, $actor['staff_id'] ?? null, $action, 'knowledge_enrichment', implode(',', $ids), '{}', '{}', json_encode($metadata, JSON_UNESCAPED_UNICODE)]);
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) ksort($value);
            foreach ($value as $key => $child) $value[$key] = json_decode($this->canonicalJson($child), true);
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
