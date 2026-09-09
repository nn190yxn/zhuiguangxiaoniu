<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/config.php';

$db = getDB();
$cards = $db->query(
    "SELECT DISTINCT knowledge_item_id, version_id
     FROM knowledge_item_age_ranges
     WHERE decision_source = 'expert_prediction_v3'
       AND review_status = 'pending'"
)->fetchAll(PDO::FETCH_ASSOC);

if (!in_array('--apply', $argv, true)) {
    echo json_encode(['dry_run' => true, 'cards' => count($cards)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

$db->beginTransaction();
try {
    $ageNeutral = $db->prepare(
        "INSERT INTO knowledge_item_age_ranges
         (knowledge_item_id, version_id, age_code, source_text, confidence, review_status, decision_source, review_note)
         VALUES (?, ?, 'age_all_review', NULL, 0.5, 'confirmed', 'expert_confirmed', '年龄边界证据不足，按全年龄适用并要求教练现场评估')
         ON DUPLICATE KEY UPDATE review_status = 'confirmed', decision_source = 'expert_confirmed',
             confidence = VALUES(confidence), review_note = VALUES(review_note)"
    );
    foreach ($cards as $card) {
        $ageNeutral->execute([(int)$card['knowledge_item_id'], (int)$card['version_id']]);
    }
    $rejected = $db->exec(
        "UPDATE knowledge_item_age_ranges
         SET review_status = 'rejected', decision_source = 'expert_fallback',
             review_note = CONCAT(COALESCE(review_note, ''), '；已采用全年龄现场评估兜底')
         WHERE decision_source = 'expert_prediction_v3' AND review_status = 'pending'"
    );
    $db->commit();
    echo json_encode(['dry_run' => false, 'age_neutral_cards' => count($cards), 'superseded_predictions' => $rejected], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    $db->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
