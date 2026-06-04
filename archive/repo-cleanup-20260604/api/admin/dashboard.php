<?php
/**
 * Admin dashboard API
 */
require_once __DIR__ . '/common.php';
handleCORS();
adminRequireAuth('adminCanAccessHeadquarter');

try {
    $db = getDB();

    // Staff stats
    $totalStaff = (int)$db->query("SELECT COUNT(*) FROM staffs WHERE status = 1")->fetchColumn();
    $byRole = [];
    foreach ($db->query("SELECT role, COUNT(*) as cnt FROM staffs WHERE status = 1 GROUP BY role") as $r) {
        $byRole[$r['role']] = (int)$r['cnt'];
    }

    // Exam stats
    $examStats = $db->query("SELECT
        COUNT(*) as total_exams,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
        COALESCE(AVG(CASE WHEN status = 'completed' THEN total_score END), 0) as avg_score,
        COUNT(CASE WHEN status = 'completed' AND is_passed = 1 THEN 1 END) as passed
    FROM exam_records")->fetch();

    // Pass stats
    $passStats = $db->query("SELECT
        COUNT(*) as total_users,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed
    FROM user_pass_progress")->fetch();

    // Knowledge stats
    $knowledgeTotal = (int)$db->query("SELECT COUNT(*) FROM knowledge_items WHERE status = 1")->fetchColumn();
    $knowledgeRead = 0;

    jsonSuccess([
        'staff_total' => $totalStaff,
        'staff_by_role' => $byRole,
        'exam_total' => (int)($examStats['total_exams'] ?? 0),
        'exam_completed' => (int)($examStats['completed'] ?? 0),
        'exam_avg_score' => round((float)($examStats['avg_score'] ?? 0), 1),
        'exam_passed' => (int)($examStats['passed'] ?? 0),
        'pass_total_users' => (int)($passStats['total_users'] ?? 0),
        'pass_completed_users' => (int)($passStats['completed'] ?? 0),
        'knowledge_total' => $knowledgeTotal,
        'knowledge_read' => $knowledgeRead,
    ]);
} catch (Exception $e) {
    error_log('Dashboard API error');
    jsonResponse(1, '服务器错误');
}
