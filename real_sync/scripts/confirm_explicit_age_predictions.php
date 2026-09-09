<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';

$db = getDB();
$criteria = "decision_source = 'expert_prediction_v3' AND review_status = 'pending' AND ((confidence >= 0.9 AND review_note LIKE '正文存在明确年龄区间%') OR (confidence >= 0.8 AND review_note LIKE '正文存在年龄下限线索%') OR (confidence >= 0.8 AND review_note LIKE '正文存在年龄上限线索%') OR (confidence >= 0.9 AND review_note LIKE '正文存在明确教学阶段%') OR (confidence >= 0.9 AND review_note LIKE '正文存在明确婴幼儿运动线索%'))";
$count = (int)$db->query("SELECT COUNT(*) FROM knowledge_item_age_ranges WHERE {$criteria}")->fetchColumn();

if (!in_array('--apply', $argv, true)) {
    echo json_encode(['dry_run' => true, 'rows' => $count], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

$statement = $db->exec("UPDATE knowledge_item_age_ranges SET review_status = 'confirmed', decision_source = 'expert_confirmed' WHERE {$criteria}");
$ageNeutral = $db->exec(
    "UPDATE knowledge_item_age_ranges r
     INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id
     INNER JOIN knowledge_item_versions kv ON kv.version_id = r.version_id
     SET r.review_status = 'confirmed', r.decision_source = 'expert_confirmed',
         r.review_note = '教练成长内容，适用于不同儿童年龄段'
     WHERE r.age_code = 'age_all_review'
       AND r.review_status = 'pending'
       AND (CONVERT(COALESCE(NULLIF(kv.content_type, ''), k.content_type, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) = _utf8mb4'coach_growth' COLLATE utf8mb4_unicode_ci"
);
$formationRows = $db->exec(
    "UPDATE knowledge_item_age_ranges r
     INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id
     INNER JOIN knowledge_item_versions kv ON kv.version_id = r.version_id
     SET r.review_status = 'confirmed', r.decision_source = 'expert_confirmed',
         r.review_note = '教学队形内容，适用于不同儿童年龄段'
     WHERE r.age_code = 'age_all_review'
       AND r.review_status = 'pending'
       AND (CONVERT(COALESCE(NULLIF(kv.content_type, ''), k.content_type, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) = _utf8mb4'teaching_organization' COLLATE utf8mb4_unicode_ci"
);
$placeholderIds = $db->query(
    "SELECT DISTINCT placeholder.id
     FROM knowledge_item_age_ranges placeholder
     INNER JOIN knowledge_item_age_ranges confirmed
       ON confirmed.knowledge_item_id = placeholder.knowledge_item_id
      AND confirmed.version_id = placeholder.version_id
      AND confirmed.review_status = 'confirmed'
     WHERE placeholder.age_code = 'age_all_review'
       AND placeholder.review_status = 'pending'"
)->fetchAll(PDO::FETCH_COLUMN);
$placeholderStatement = $db->prepare(
    "UPDATE knowledge_item_age_ranges
     SET review_status = 'rejected', decision_source = 'superseded',
         review_note = '同一知识卡已有确认年龄结果'
     WHERE id = ?"
);
foreach ($placeholderIds as $placeholderId) {
    $placeholderStatement->execute([(int)$placeholderId]);
}
$supersededPlaceholders = count($placeholderIds);
echo json_encode(['dry_run' => false, 'confirmed_rows' => $statement, 'age_neutral_rows' => $ageNeutral, 'formation_rows' => $formationRows, 'superseded_placeholders' => $supersededPlaceholders], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
