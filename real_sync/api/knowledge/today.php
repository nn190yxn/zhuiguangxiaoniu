<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/KnowledgeListService.php';

$role = strtolower(trim((string)($_GET['role'] ?? 'coach')));
$role = in_array($role, ['sales', 'coach'], true) ? $role : 'coach';
$date = gmdate('Y-m-d');
$limit = $role === 'coach' ? 2 : 1;
$primary = $role === 'sales' ? 'sales' : 'professional';
$db = getDB();
$scope = $role === 'sales' ? "(k.domain_code = 'sales' OR k.content_type = 'script' OR k.title LIKE '%销售%' OR k.title LIKE '%家长沟通%')" : "(k.domain_code <> 'sales' AND k.content_type <> 'script' AND k.title NOT LIKE '%销售%' AND k.title NOT LIKE '%成交%' AND k.title NOT LIKE '%续费%')";
$stmt = $db->prepare('SELECT k.id, k.title, k.content_type, k.summary FROM knowledge_items k WHERE k.status = 1 AND k.publication_status = ? AND ' . $scope . ' AND EXISTS (SELECT 1 FROM knowledge_item_versions v WHERE v.version_id = k.current_version_id AND v.knowledge_item_id = k.id) ORDER BY SHA2(CONCAT(?, ":", k.id), 256) LIMIT ' . $limit);
$stmt->execute(['published', $date]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['code' => 0, 'data' => ['role' => $role, 'date' => $date, 'items' => $items]], JSON_UNESCAPED_UNICODE);
