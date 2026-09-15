<?php
declare(strict_types=1);

final class KnowledgeEnrichmentReviewService
{
    public function __construct(private PDO $db) {}

    public function requireSchema(): void
    {
        $table = 'knowledge_enrichment_records';
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('知识增强迁移未就绪');
    }

    public function list(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        foreach (['enrichment_status', 'review_status', 'task_type'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '') { $where[] = "r.$field = ?"; $params[] = $value; }
        }
        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') { $where[] = '(k.item_code LIKE ? OR COALESCE(v.title, k.title) LIKE ?)'; $params[] = '%' . $keyword . '%'; $params[] = '%' . $keyword . '%'; }
        $riskFlag = trim((string)($filters['risk_flag'] ?? ''));
        if ($riskFlag !== '') { $where[] = 'JSON_CONTAINS(r.risk_flags_json, JSON_QUOTE(?))'; $params[] = $riskFlag; }
        $sourceLength = trim((string)($filters['source_length'] ?? ''));
        if ($sourceLength === 'short') $where[] = 'CHAR_LENGTH(v.content) < 300';
        if ($sourceLength === 'long') $where[] = 'CHAR_LENGTH(v.content) >= 300';
        $limit = max(1, min((int)($filters['limit'] ?? 50), 100));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sql = 'SELECT r.id, r.knowledge_item_id, r.source_version_id, r.source_content_sha256, r.content_type, r.task_type, r.missing_fields_json, r.risk_flags_json, r.enrichment_status, r.review_status, r.release_batch_id, r.reviewed_by, r.reviewed_at, r.updated_at, COALESCE(v.title, k.title) AS title FROM knowledge_enrichment_records r INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id INNER JOIN knowledge_item_versions v ON v.version_id = r.source_version_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.review_status = \'pending\' DESC, r.updated_at ASC, r.id ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'limit' => $limit, 'offset' => $offset];
    }

    public function item(int $id): array
    {
        $stmt = $this->db->prepare('SELECT r.*, k.item_code, COALESCE(v.title, k.title) AS title, v.content AS source_content FROM knowledge_enrichment_records r INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id INNER JOIN knowledge_item_versions v ON v.version_id = r.source_version_id WHERE r.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new RuntimeException('增强任务不存在');
        return $item;
    }

    public function decide(int $id, string $decision, string $note, array $actor): array
    {
        if (!in_array($decision, ['approve', 'reject'], true) || trim($note) === '') throw new InvalidArgumentException('审核动作和原因不能为空');
        $item = $this->item($id);
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $reviewStatus = $decision === 'approve' ? 'approved' : 'rejected';
        $stmt = $this->db->prepare('UPDATE knowledge_enrichment_records SET enrichment_status = ?, review_status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ? AND review_status = \'pending\'');
        $stmt->execute([$status, $reviewStatus, $actor['staff_id'] ?? null, $note, $id]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('增强任务已被处理，请刷新后重试');
        $audit = $this->db->prepare('INSERT INTO knowledge_audit_logs (actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $audit->execute([$actor['user_id'] ?? null, $actor['staff_id'] ?? null, 'enrichment_' . $decision, 'knowledge_enrichment', (string)$id, json_encode(['review_status' => $item['review_status']], JSON_UNESCAPED_UNICODE), json_encode(['review_status' => $reviewStatus], JSON_UNESCAPED_UNICODE), json_encode(['reason' => $note], JSON_UNESCAPED_UNICODE)]);
        return ['id' => $id, 'enrichment_status' => $status, 'review_status' => $reviewStatus];
    }

    public function saveDraft(int $id, string $content, array $actor): array
    {
        if (trim($content) === '') throw new InvalidArgumentException('增强稿不能为空');
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) throw new InvalidArgumentException('增强稿必须是有效 JSON');
        $item = $this->item($id);
        $canonical = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare('UPDATE knowledge_enrichment_records SET enriched_content_json = ?, enriched_content_sha256 = ?, enrichment_status = \'draft\', review_status = \'pending\', reviewed_by = NULL, reviewed_at = NULL WHERE id = ?');
        $stmt->execute([$canonical, hash('sha256', $canonical), $id]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('增强稿保存失败');
        $audit = $this->db->prepare('INSERT INTO knowledge_audit_logs (actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $audit->execute([$actor['user_id'] ?? null, $actor['staff_id'] ?? null, 'enrichment_save_draft', 'knowledge_enrichment', (string)$id, json_encode(['content_sha256' => $item['enriched_content_sha256'] ?? null], JSON_UNESCAPED_UNICODE), json_encode(['content_sha256' => hash('sha256', $canonical)], JSON_UNESCAPED_UNICODE), json_encode(['reason' => 'manual_edit'], JSON_UNESCAPED_UNICODE)]);
        return ['id' => $id, 'enrichment_status' => 'draft', 'review_status' => 'pending', 'updated_by' => $actor['staff_id'] ?? null];
    }
}
