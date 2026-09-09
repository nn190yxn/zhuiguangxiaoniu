<?php
declare(strict_types=1);
require __DIR__ . '/../api/knowledge/KnowledgeTaxonomy.php';

function expectReviewed(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$release = KnowledgeReviewedTaxonomy::release();
expectReviewed(count($release['entries']) === 1647, 'approved release count');
$db = new PDO('sqlite::memory:');
$db->sqliteCreateFunction('CONCAT', static fn(...$parts) => implode('', $parts));
$db->sqliteCreateFunction('SHA2', static fn($content, $bits) => hash('sha256', (string)$content));
$db->exec('CREATE TABLE k (id INTEGER, domain TEXT, type TEXT, title TEXT, content TEXT)');
$db->exec('CREATE TABLE kv (version_id INTEGER)');
$id = array_key_first($release['entries']);
$entry = $release['entries'][$id];
$item = ['id' => $id, 'version_id' => $entry['version_id'], 'content_sha256' => $entry['content_sha256'],
         'domain_code' => 'physical_qualities', 'content_type' => 'action', 'title' => $entry['title']];
$mapped = KnowledgeTaxonomy::classify($item);
expectReviewed($mapped['subcategory_code'] === $entry['subtopic_code'], 'approved row applied');
expectReviewed($mapped['topic_review_status'] === 'confirmed', 'user confirmation distinguished');
expectReviewed($mapped['content_quality_flag'] === $entry['quality_flag'], 'quality flag preserved');
expectReviewed(KnowledgeTaxonomy::classify(array_replace($item, ['version_id' => 999999]))['subcategory_code'] === 'fitness', 'new version falls back');
expectReviewed(KnowledgeTaxonomy::classify(array_replace($item, ['content_sha256' => str_repeat('0', 64)]))['subcategory_code'] === 'fitness', 'edited body falls back');
expectReviewed(KnowledgeTaxonomy::classify(array_replace($item, ['id' => 999999]))['subcategory_code'] === 'fitness', 'unapproved row unchanged');

// Supply a known source digest to exercise the real SQL mapping for all approved records.
$db->sqliteCreateFunction('SHA2', static fn($content, $bits) => (string)$content);
$insert = $db->prepare('INSERT INTO k VALUES (?, ?, ?, ?, ?)');
$version = $db->prepare('INSERT INTO kv VALUES (?)');
$version->execute([$entry['version_id']]);
$insert->execute([$id, 'physical_qualities', 'action', $entry['title'], $entry['content_sha256']]);
$classification = KnowledgeTaxonomy::classificationSql('k.domain', 'k.type', 'k.title', KnowledgeReviewedTaxonomy::subcategorySql('k.content'));
$query = 'SELECT (' . $classification['primary_category'] . ') AS primary_category, (' . $classification['subcategory_code'] . ') AS subcategory_code FROM k CROSS JOIN kv';
$row = $db->query($query)->fetch(PDO::FETCH_ASSOC);
expectReviewed($row === ['primary_category' => 'professional', 'subcategory_code' => $entry['subtopic_code']], 'SQL uses same approved classification');
$db->exec('UPDATE kv SET version_id = 999999');
expectReviewed($db->query($query)->fetch(PDO::FETCH_ASSOC)['subcategory_code'] === 'fitness', 'SQL protects changed version');
$db->prepare('UPDATE kv SET version_id = ?')->execute([$entry['version_id']]);
$db->exec("UPDATE k SET content = 'changed'");
expectReviewed($db->query($query)->fetch(PDO::FETCH_ASSOC)['subcategory_code'] === 'fitness', 'SQL protects changed body');
echo "PASS: full approved scope, confirmation, quality preservation, SQL/PHP consistency, version and digest fallback\n";
