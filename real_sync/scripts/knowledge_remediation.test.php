<?php
declare(strict_types=1);
require __DIR__ . '/../api/knowledge/KnowledgeListService.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// Execute production count/filter SQL in SQLite; only MySQL expression syntax is translated.
final class FilterDatabase extends PDO {
    public PDO $inner;
    public array $queries = [];
    public function __construct() {
        $this->inner = new PDO('sqlite::memory:');
        $this->inner->sqliteCreateFunction('CONCAT', static fn(...$parts) => implode('', $parts));
        $this->inner->exec("CREATE TABLE knowledge_items (id INTEGER, current_version_id INTEGER, status INTEGER, publication_status TEXT, title TEXT, summary TEXT, content TEXT, tags TEXT, domain_code TEXT, content_type TEXT, age_group TEXT, subject TEXT, training_type TEXT, category_id INTEGER)");
        $this->inner->exec("CREATE TABLE knowledge_item_versions (version_id INTEGER, knowledge_item_id INTEGER, status TEXT, title TEXT, summary TEXT, content TEXT, tags_json TEXT, domain_code TEXT, content_type TEXT, age_group TEXT, subject TEXT, training_type TEXT)");
        $this->inner->exec('CREATE TABLE knowledge_categories (id INTEGER, name TEXT, type TEXT)');
        $this->inner->exec('CREATE TABLE knowledge_item_age_ranges (knowledge_item_id INTEGER, version_id INTEGER, age_code TEXT, review_status TEXT)');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        return new FilterStatement($this->inner, $query);
    }
}
final class FilterStatement extends PDOStatement {
    private ?PDOStatement $statement = null;
    public function __construct(PDO $db, string $sql) {
        if (str_starts_with($sql, 'SELECT COUNT(*)')) {
            $sql = preg_replace('/CONVERT\((.*?) USING utf8mb4\)/s', '$1', $sql);
            $sql = str_replace(' COLLATE utf8mb4_unicode_ci', '', $sql);
            $this->statement = $db->prepare($sql);
        }
    }
    public function execute(?array $params = null): bool { return $this->statement ? $this->statement->execute($params) : true; }
    public function fetchColumn(int $column = 0): mixed { return $this->statement->fetchColumn($column); }
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
}

$db = new FilterDatabase();
$insert = $db->inner->prepare('INSERT INTO knowledge_items VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)');
$version = $db->inner->prepare('INSERT INTO knowledge_item_versions VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$fixtures = [];
foreach (KnowledgeTaxonomy::domainMappings() as $domain => $mapping) $fixtures[] = [$domain, 'game', '游戏平衡', $mapping['subcategory_code']];
foreach (['首次接待'=>'reception', '需求分析'=>'needs_analysis', '体测沟通'=>'fitness_explanation', '体验课'=>'trial_class', '家长沟通'=>'parent_communication', '异议处理'=>'objection_handling', '成交'=>'conversion', '续费'=>'renewal', '销售话术'=>'sales_script'] as $title => $sub) $fixtures[] = ['sales', 'knowledge_card', $title, $sub];
foreach (['action_game'=>['', 'game'], 'coach_growth'=>['coach', 'knowledge_card'], 'lesson_reference'=>['', 'lesson']] as $sub => [$domain, $type]) $fixtures[] = [$domain, $type, '游戏平衡', $sub];
$expected = [];
foreach ($fixtures as $i => [$domain, $type, $title, $sub]) {
    $id = $i + 1;
    $insert->execute([$id, $id * 10, 'published', $title, '', '平衡内容', '[]', $domain, $type, '3-4岁', '', '']);
    $version->execute([$id * 10, $id, 'active', $title, '', '平衡内容', '[]', $domain, $type, '3-4岁', '', '']);
    $db->inner->prepare("INSERT INTO knowledge_item_age_ranges VALUES (?, ?, 'age_3_4', 'confirmed')")->execute([$id, $id * 10]);
    $expected[$sub] = ($expected[$sub] ?? 0) + 1;
}
$service = new KnowledgeListService($db, static fn($url) => $url);
$buildSummary = new ReflectionMethod(KnowledgeListService::class, 'buildSummary');
foreach (['一', '中', '育', '动'] as $lastCharacter) {
    $content = str_repeat('知', 119) . $lastCharacter . '后续内容';
    $summary = $buildSummary->invoke($service, $content);
    check($summary === str_repeat('知', 119) . $lastCharacter . '…', 'summary preserves final character: ' . $lastCharacter);
    check(json_encode(['summary' => $summary], JSON_THROW_ON_ERROR) !== '', 'summary JSON encoding');
}
$punctuated = str_repeat('知', 116) . '，。；、后续内容';
check($buildSummary->invoke($service, $punctuated) === str_repeat('知', 116) . '…', 'summary trims whole punctuation characters');
check($buildSummary->invoke($service, '简短摘要。') === '简短摘要。', 'short summary preserved');
foreach (KnowledgeTaxonomy::subcategories() as $primary => $subs) {
    foreach ($subs as $sub => $_label) {
        $result = $service->list(1, [], ['primary_category'=>$primary, 'subcategory_code'=>$sub]);
        check($result['total'] === ($expected[$sub] ?? 0), "classification $sub");
    }
}
foreach (['3-4岁游戏平衡', '3至4岁游戏平衡', '3到4岁游戏平衡'] as $query) {
    $result = $service->list(1, [], ['keyword'=>$query]);
    check($result['total'] === 9, 'age synonym: ' . $query);
    check($result['filters']['age_group'] === '', 'no raw age equality');
}
check($service->list(1, [], ['keyword'=>'3至4岁游戏平衡', 'age_code'=>'age_4_5'])['total'] === 0, 'combined confirmed ages');
check($service->list(1, [], ['keyword'=>'游戏不存在'])['total'] === 0, 'remaining keyword');
check($service->list(1, [], ['keyword'=>'3至4岁游戏', 'content_type'=>'lesson'])['total'] === 1, 'explicit content filter keeps keyword');
check($service->list(1, [], ['age_group'=>'3至4岁', 'content_type'=>'game'])['total'] === 9, 'explicit age group synonym');
check($service->list(1, [], ['keyword'=>'0岁游戏'])['total'] === 0, 'zero age normalization');
$db->inner->exec("UPDATE knowledge_item_age_ranges SET review_status = 'pending' WHERE knowledge_item_id = 1");
check($service->list(1, [], ['keyword'=>'3至4岁游戏'])['total'] === 8, 'pending ages excluded');
try { $service->list(1, [], ['subcategory_code'=>'无效']); throw new RuntimeException('accepted invalid category'); } catch (InvalidArgumentException) {}
try { $service->list(1, [], ['primary_category'=>'sales', 'subcategory_code'=>'fitness']); throw new RuntimeException('accepted mismatched category'); } catch (InvalidArgumentException) {}
check(str_contains($db->queries[0], 'COLLATE utf8mb4_unicode_ci'), 'explicit collation retained');

$insert->execute([999, 9990, 'published', '体测平衡评估', '', '平衡内容', '[]', '', 'assessment', '', '', '']);
$version->execute([9990, 999, 'active', '体测平衡评估', '', '平衡内容', '[]', '', 'assessment', '', '', '']);
$assessmentTotal = $service->list(1, [], ['primary_category' => 'professional', 'subcategory_code' => 'assessment'])['total'];
check($assessmentTotal === 2, 'assessment includes legacy content');
check($service->list(1, [], ['keyword' => '体测'])['total'] === $assessmentTotal, 'inferred assessment matches category including legacy content');
check($service->list(1, [], ['keyword' => '体测平衡'])['total'] === $assessmentTotal, 'inferred assessment retains remaining keyword');
check($service->list(1, [], ['keyword' => '体测', 'domain_code' => 'assessment'])['total'] === 1, 'explicit domain remains exact');
check($service->list(1, [], ['keyword' => '体测', 'primary_category' => 'sales'])['total'] === 0, 'inferred assessment respects primary category');

$version->execute([11, 1, 'superseded', 'V1', '', '历史正文', '[]', '', '', '', '', '']);
$version->execute([12, 1, 'rolled_back', '撤回版本', '', '', '[]', '', '', '', '', '']);
$current = $db->inner->query('SELECT kv.version_id FROM ' . EmployeeKnowledgeVisibilityQuery::fromCurrentVersion() . ' WHERE k.id = 1')->fetchColumn();
check((int)$current === 10, 'current version');
$read = $db->inner->prepare('SELECT kv.title, kv.summary FROM ' . EmployeeKnowledgeVisibilityQuery::fromReferencedVersion() . ' WHERE k.id = ? AND kv.version_id = ?');
$read->execute([1, 11]);
check($read->fetch(PDO::FETCH_ASSOC) === ['title'=>'V1', 'summary'=>''], 'fixed historical snapshot');
foreach ([[1, 20], [1, 12], [1, 999]] as $params) { $read->execute($params); check($read->fetch() === false, 'unavailable or mismatched version'); }
$db->inner->exec("UPDATE knowledge_items SET publication_status = 'reviewing' WHERE id = 1");
$read->execute([1, 11]);
check($read->fetch() === false, 'hidden item history');
echo "PASS: taxonomy filters, age synonyms, confirmed ranges, historical versions\n";
