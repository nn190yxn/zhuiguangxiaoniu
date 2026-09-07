<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../api/config.php';

const AGE_BUCKETS = [
    ['code' => 'age_0_3', 'min' => 0, 'max' => 3],
    ['code' => 'age_3_4', 'min' => 3, 'max' => 4],
    ['code' => 'age_4_5', 'min' => 4, 'max' => 5],
    ['code' => 'age_5_6', 'min' => 5, 'max' => 6],
    ['code' => 'age_6_9', 'min' => 6, 'max' => 9],
    ['code' => 'age_9_12', 'min' => 9, 'max' => 12],
    ['code' => 'age_12_plus', 'min' => 12, 'max' => null],
];

function normalizeAgeGroup(?string $sourceText): array
{
    $sourceText = trim((string)$sourceText);
    $matches = [];
    preg_match_all('/(\d{1,2})\s*(?:岁)?\s*(?:至|到|-)\s*(\d{1,2})\s*岁?/u', $sourceText, $matches, PREG_SET_ORDER);
    $ageCodes = [];

    foreach ($matches as $match) {
        $minAge = (int)$match[1];
        $maxAge = (int)$match[2];
        if ($maxAge <= $minAge || $minAge < 0) {
            continue;
        }
        foreach (AGE_BUCKETS as $bucket) {
            $startsWithinRange = $bucket['min'] >= $minAge && $bucket['min'] < $maxAge;
            if ($startsWithinRange) {
                $ageCodes[$bucket['code']] = true;
            }
        }
    }

    if ($ageCodes !== []) {
        return [array_keys($ageCodes), 'confirmed', 1.0];
    }

    return [['age_all_review'], 'pending', null];
}

$db = getDB();
$rows = $db->query(
    "SELECT k.id AS knowledge_item_id, kv.version_id, kv.age_group
     FROM knowledge_items k
     INNER JOIN knowledge_item_versions kv
       ON kv.version_id = k.current_version_id
      AND kv.knowledge_item_id = k.id
      AND kv.status = 'active'
     WHERE k.status = 1 AND k.publication_status = 'published'
     ORDER BY k.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$plan = [];
$counts = ['confirmed' => 0, 'pending' => 0, 'rows' => 0];
foreach ($rows as $row) {
    [$ageCodes, $reviewStatus, $confidence] = normalizeAgeGroup($row['age_group'] ?? null);
    $counts[$reviewStatus]++;
    foreach ($ageCodes as $ageCode) {
        $plan[] = [
            'knowledge_item_id' => (int)$row['knowledge_item_id'],
            'version_id' => (int)$row['version_id'],
            'age_code' => $ageCode,
            'source_text' => trim((string)($row['age_group'] ?? '')) ?: null,
            'confidence' => $confidence,
            'review_status' => $reviewStatus,
        ];
    }
}
$counts['rows'] = count($plan);

echo json_encode(['dry_run' => !in_array('--apply', $argv, true), 'cards' => count($rows), 'counts' => $counts], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if (!in_array('--apply', $argv, true)) {
    exit(0);
}

$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT IGNORE INTO knowledge_item_age_ranges '
        . '(knowledge_item_id, version_id, age_code, source_text, confidence, review_status) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($plan as $entry) {
        $stmt->execute([
            $entry['knowledge_item_id'],
            $entry['version_id'],
            $entry['age_code'],
            $entry['source_text'],
            $entry['confidence'],
            $entry['review_status'],
        ]);
    }
    $db->commit();
    echo json_encode(['applied' => true, 'inserted_or_existing' => count($plan)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    $db->rollBack();
    fwrite(STDERR, json_encode(['applied' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
