<?php
/**
 * Admin exam scores summary API
 */
require_once __DIR__ . '/../config.php';
handleCORS();
$user = getJwtCurrentUser();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    jsonError(403, '无权限访问');
}
$db = getDB();

$page = (int)($_GET['page'] ?? 1);
$perPage = 20;
$offset = ($page - 1) * $perPage;
$keyword = trim($_GET['keyword'] ?? '');
$examType = $_GET['exam_type'] ?? '';
$paperCode = strtoupper(trim((string)($_GET['paper_code'] ?? '')));

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(s.name LIKE ? OR s.phone LIKE ?)';
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
}
if ($examType !== '') {
    $where[] = 'e.exam_type = ?';
    $params[] = $examType;
}
if (in_array($paperCode, ['A', 'B'], true)) {
    $where[] = "r.answers LIKE ?";
    $params[] = '%"paper_code":"' . $paperCode . '"%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM exam_records r LEFT JOIN staffs s ON r.user_id = s.user_id LEFT JOIN exams e ON r.exam_type = e.exam_type $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("SELECT r.*, s.name, s.phone, s.role, e.title as exam_title 
    FROM exam_records r 
    LEFT JOIN staffs s ON r.user_id = s.user_id 
    LEFT JOIN exams e ON r.exam_type = e.exam_type 
    $whereSql 
    ORDER BY r.created_at DESC 
    LIMIT $offset, $perPage");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $paper = '';
    if (!empty($row['answers'])) {
        $decoded = json_decode($row['answers'], true);
        if (is_array($decoded)) {
            $paper = strtoupper(trim((string)($decoded['__meta']['paper_code'] ?? '')));
        }
    }
    $row['paper_code'] = in_array($paper, ['A', 'B'], true) ? $paper : 'A';
}
unset($row);

$exams = $db->query("SELECT id, title, exam_type FROM exams ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

jsonSuccess([
    'list' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'filters' => [
        'keyword' => $keyword,
        'exam_type' => $examType,
        'paper_code' => $paperCode,
    ],
    'exams' => $exams,
]);
