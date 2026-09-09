<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/admin/services/KnowledgeEnrichmentReleaseService.php';

$batchId = trim((string)($argv[1] ?? ''));
if ($batchId === '') {
    fwrite(STDERR, "Usage: php scripts/auto_publish_safe_knowledge_enrichment.php <batch-id>\n");
    exit(2);
}

$db = getDB();
$db->beginTransaction();
try {
    $rows = $db->prepare("SELECT r.*, k.current_version_id, v.content AS current_source_content FROM knowledge_enrichment_records r INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id INNER JOIN knowledge_item_versions v ON v.version_id = k.current_version_id AND v.knowledge_item_id = k.id WHERE ((r.enrichment_status = 'draft' AND r.review_status = 'pending') OR (r.enrichment_status = 'approved' AND r.review_status = 'approved')) AND r.enriched_content_json IS NOT NULL AND JSON_EXTRACT(r.enriched_content_json, '$.needs_human_review') = false FOR UPDATE");
    $rows->execute();
    $items = $rows->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    $approve = $db->prepare("UPDATE knowledge_enrichment_records SET enrichment_status = 'approved', review_status = 'approved', reviewed_by = NULL, reviewed_at = NOW(), review_note = ? WHERE id = ? AND enrichment_status = 'draft' AND review_status = 'pending'");
    $audit = $db->prepare('INSERT INTO knowledge_audit_logs (actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json) VALUES (NULL, NULL, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $item) {
        $content = json_decode((string)$item['enriched_content_json'], true, 512, JSON_THROW_ON_ERROR);
        $citations = $content['citations'] ?? [];
        $sourceHash = hash('sha256', (string)$item['current_source_content']);
        $validCitations = is_array($citations) && $citations && count(array_filter($citations, static fn($citation): bool => is_array($citation) && trim((string)($citation['excerpt'] ?? '')) !== '' && str_contains((string)$item['current_source_content'], (string)$citation['excerpt']))) === count($citations);
        if ((int)$item['source_version_id'] !== (int)$item['current_version_id'] || !hash_equals($sourceHash, (string)$item['source_content_sha256']) || !$validCitations || !empty($content['risk_flags']) || !empty($content['needs_human_review'])) {
            continue;
        }
        if ($item['enrichment_status'] === 'draft') {
            $approve->execute(['系统门禁：无风险标记且引用完整', (int)$item['id']]);
            if ($approve->rowCount() !== 1) continue;
        }
        $ids[] = (int)$item['id'];
        $audit->execute(['enrichment_auto_approve', 'knowledge_enrichment', (string)$item['id'], json_encode(['review_status' => 'pending'], JSON_UNESCAPED_UNICODE), json_encode(['review_status' => 'approved'], JSON_UNESCAPED_UNICODE), json_encode(['batch_id' => $batchId, 'policy' => 'no_risk_complete_citations'], JSON_UNESCAPED_UNICODE)]);
    }
    $db->commit();

    if (!$ids) {
        echo json_encode(['approved_count' => 0, 'published_count' => 0], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    $release = new KnowledgeEnrichmentReleaseService($db);
    $result = $release->publish(['ids' => $ids, 'release_batch_id' => $batchId . '-safe'], ['user_id' => null, 'staff_id' => null]);
    echo json_encode(['approved_count' => count($ids), 'published' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
