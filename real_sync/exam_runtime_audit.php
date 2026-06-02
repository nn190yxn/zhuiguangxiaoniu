<?php
require '/www/wwwroot/122.51.223.46/api/config.php';

$db = getDB();

$hasExamPaper = false;
try {
    $stmt = $db->query("SHOW COLUMNS FROM exams LIKE 'exam_paper'");
    $hasExamPaper = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $hasExamPaper = false;
}

$inProgress = (int)$db->query("SELECT COUNT(*) FROM exam_records WHERE exam_type='course_exam' AND status='in_progress'")->fetchColumn();
$completed = (int)$db->query("SELECT COUNT(*) FROM exam_records WHERE exam_type='course_exam' AND status='completed'")->fetchColumn();

$sampleStmt = $db->query("SELECT answers, is_passed, total_score FROM exam_records WHERE exam_type='course_exam' AND status='completed' ORDER BY id DESC LIMIT 300");
$paperStats = [
    'A' => ['count' => 0, 'passed' => 0, 'score_sum' => 0.0],
    'B' => ['count' => 0, 'passed' => 0, 'score_sum' => 0.0],
];
$sampleTotal = 0;
$sampleHasMeta = 0;

foreach ($sampleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $sampleTotal++;
    $paper = 'A';
    $answers = json_decode($row['answers'] ?? '', true);
    if (is_array($answers) && is_array($answers['__meta'] ?? null)) {
        $sampleHasMeta++;
        $pc = strtoupper(trim((string)($answers['__meta']['paper_code'] ?? '')));
        if (in_array($pc, ['A', 'B'], true)) {
            $paper = $pc;
        }
    }
    $paperStats[$paper]['count']++;
    $paperStats[$paper]['passed'] += ((int)$row['is_passed'] === 1 ? 1 : 0);
    $paperStats[$paper]['score_sum'] += (float)($row['total_score'] ?? 0);
}

foreach (['A', 'B'] as $paper) {
    $count = $paperStats[$paper]['count'];
    $paperStats[$paper]['avg_score'] = $count > 0 ? round($paperStats[$paper]['score_sum'] / $count, 1) : 0;
    $paperStats[$paper]['pass_rate'] = $count > 0 ? round($paperStats[$paper]['passed'] / $count * 100, 1) : 0;
    unset($paperStats[$paper]['score_sum']);
}

$result = [
    'has_exam_paper' => $hasExamPaper,
    'in_progress' => $inProgress,
    'completed' => $completed,
    'sample_total' => $sampleTotal,
    'sample_has_meta' => $sampleHasMeta,
    'paper_stats' => $paperStats,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
