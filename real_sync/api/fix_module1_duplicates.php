<?php

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
    die("Connection failed: " . $e->getMessage());
}

// 删除模块1中的重复卡片
$duplicates = ['我们的课程体系', '介绍品牌理念'];
$placeholders = implode(',', array_fill(0, count($duplicates), '?'));

$sql = "DELETE FROM training_cards WHERE module_id = 1 AND title IN ($placeholders)";
$stmt = $db->prepare($sql);
$stmt->execute($duplicates);
$deleted = $stmt->rowCount();

echo "Deleted $deleted duplicate cards from module 1.\n";

// 更新模块卡片数量
$db->exec("UPDATE training_modules tm SET tm.total_cards = (SELECT COUNT(*) FROM training_cards WHERE module_id = tm.id) WHERE tm.id = 1");

// 验证
$stmt = $db->query("SELECT COUNT(*) as cnt FROM training_cards WHERE module_id = 1");
$cnt = $stmt->fetchColumn();
echo "Module 1 now has $cnt cards.\nDone!\n";
