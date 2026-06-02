<?php
/**
 * 清理培训卡片中的重复数据
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$dbHost = 'localhost';
$dbName = '_122_51_223_46';
$dbUser = '_122_51_223_46';
$dbPass = 'Yaoxiuning190';

try {
    $db = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

echo "Finding and removing duplicate cards...\n";

// 查找重复的卡片（相同module_id, card_type, title）
$sql = "SELECT module_id, card_type, title, COUNT(*) as cnt, GROUP_CONCAT(id) as ids
        FROM training_cards
        GROUP BY module_id, card_type, title
        HAVING cnt > 1";

$stmt = $db->query($sql);
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$deleted = 0;
foreach ($duplicates as $dup) {
    $ids = explode(',', $dup['ids']);
    // 保留第一个，删除其余
    $keepId = $ids[0];
    $deleteIds = array_slice($ids, 1);

    echo "Duplicate [{$dup['card_type']}] {$dup['title']} (module {$dup['module_id']}): keeping id=$keepId, deleting " . implode(',', $deleteIds) . "\n";

    $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
    $deleteSql = "DELETE FROM training_cards WHERE id IN ($placeholders)";
    $stmt = $db->prepare($deleteSql);
    $stmt->execute($deleteIds);
    $deleted += count($deleteIds);
}

// 重新更新模块卡片数量
$db->exec("
    UPDATE training_modules tm
    SET tm.total_cards = (
        SELECT COUNT(*) FROM training_cards WHERE module_id = tm.id
    )
");

echo "\nDeleted $deleted duplicate cards.\n";

// 验证最终结果
$sql = "SELECT tm.id, tm.module_name, tm.total_cards, COUNT(tc.id) as actual_count
        FROM training_modules tm
        LEFT JOIN training_cards tc ON tm.id = tc.module_id
        GROUP BY tm.id";
$stmt = $db->query($sql);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== Final Module Status ===\n";
foreach ($modules as $m) {
    $status = ($m['total_cards'] == $m['actual_count']) ? 'OK' : 'MISMATCH';
    echo "[{$m['id']}] {$m['module_name']}: {$m['actual_count']} cards [$status]\n";
}

echo "\nDone!\n";
