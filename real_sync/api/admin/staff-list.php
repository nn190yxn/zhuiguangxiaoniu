<?php
/**
 * Admin staff list API
 */
require_once __DIR__ . '/common.php';
handleCORS();
adminRequireAuth('adminCanAccessHeadquarter');
$db = getDB();

$page = (int)($_GET['page'] ?? 1);
$perPage = 50;
$offset = ($page - 1) * $perPage;
$keyword = trim($_GET['keyword'] ?? '');
$role = $_GET['role'] ?? '';

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(s.name LIKE ? OR s.phone LIKE ? OR s.employee_no LIKE ?)';
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
}
if ($role !== '') {
    $where[] = 's.role = ?';
    $params[] = $role;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM staffs s $whereSql";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT s.*, st.name as store_name,
    (SELECT COUNT(*) FROM exam_records er WHERE er.user_id = s.user_id AND er.status = 'completed') as exam_count,
    (SELECT COALESCE(AVG(er.total_score), 0) FROM exam_records er WHERE er.user_id = s.user_id AND er.status = 'completed') as exam_avg,
    (SELECT COUNT(*) FROM user_pass_progress upp WHERE upp.user_id = s.user_id AND upp.status = 'completed') as pass_count
FROM staffs s
LEFT JOIN stores st ON s.store_id = st.id
$whereSql
ORDER BY s.created_at DESC
LIMIT ? OFFSET ?");
$limitParams = [...$params, (int)$perPage, (int)$offset];
$stmt->execute($limitParams);
$staffs = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonSuccess([
    'list' => $staffs,
    'total' => $total,
    'page' => $page,
]);
