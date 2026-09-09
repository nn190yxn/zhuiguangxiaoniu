<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/config.php';
$db = getDB();
$statement = $db->query(
    "SELECT r.knowledge_item_id,
            LEFT(COALESCE(NULLIF(kv.title, ''), k.title), 100) AS title,
            COALESCE(NULLIF(kv.content_type, ''), k.content_type) AS content_type,
            r.confidence, r.review_note
     FROM knowledge_item_age_ranges r
     INNER JOIN knowledge_item_versions kv ON kv.version_id = r.version_id
     INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id
     WHERE r.age_code = 'age_all_review'
       AND r.review_status = 'pending'
        AND (CONVERT(COALESCE(NULLIF(kv.content_type, ''), k.content_type, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) = _utf8mb4'game' COLLATE utf8mb4_unicode_ci
     ORDER BY r.confidence DESC, r.knowledge_item_id ASC
      LIMIT 60"
);
echo json_encode($statement->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
$status = $db->query(
    "SELECT review_status, decision_source, COUNT(*) AS rows_count,
            COUNT(DISTINCT knowledge_item_id) AS cards_count
     FROM knowledge_item_age_ranges
     GROUP BY review_status, decision_source
     ORDER BY review_status, decision_source"
);
echo json_encode($status->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
$types = $db->query(
    "SELECT COALESCE(NULLIF(kv.content_type, ''), k.content_type, '') AS content_type,
            COUNT(DISTINCT r.knowledge_item_id) AS cards_count
     FROM knowledge_item_age_ranges r
     INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id
     INNER JOIN knowledge_item_versions kv ON kv.version_id = r.version_id
     WHERE r.age_code = 'age_all_review' AND r.review_status = 'pending'
     GROUP BY CONVERT(COALESCE(NULLIF(kv.content_type, ''), k.content_type, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci
     ORDER BY cards_count DESC"
);
echo json_encode($types->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
