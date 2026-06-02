<?php
require '/www/wwwroot/122.51.223.46/api/config.php';

$db = getDB();
$db->beginTransaction();

try {
    $uid = (int)$db->query("SELECT ID FROM wp_users ORDER BY ID ASC LIMIT 1")->fetchColumn();
    if ($uid <= 0) {
        throw new Exception('no user');
    }

    $answersA = json_encode([
        '1' => 'A',
        '__meta' => ['source_exam_id' => 9001, 'selected_exam_id' => 9001, 'paper_code' => 'A'],
    ], JSON_UNESCAPED_UNICODE);
    $answersB = json_encode([
        '1' => 'B',
        '__meta' => ['source_exam_id' => 9001, 'selected_exam_id' => 9002, 'paper_code' => 'B'],
    ], JSON_UNESCAPED_UNICODE);

    $ins = $db->prepare("INSERT INTO exam_records (user_id,module_id,exam_type,total_score,passing_score,is_passed,answers,wrong_answers,duration,status,completed_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,'completed',NOW(),NOW())");
    $ins->execute([$uid, 9001, 'course_exam', 90, 80, 1, $answersA, '[]', 600]);
    $ins->execute([$uid, 9001, 'course_exam', 50, 80, 0, $answersB, '[]', 620]);

    $st = $db->query("SELECT answers,is_passed,total_score FROM exam_records WHERE exam_type='course_exam' AND status='completed' ORDER BY id DESC LIMIT 50");
    $stats = [
        'A' => ['count' => 0, 'passed' => 0, 'sum' => 0.0],
        'B' => ['count' => 0, 'passed' => 0, 'sum' => 0.0],
    ];

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $paper = 'A';
        $ans = json_decode($row['answers'] ?? '', true);
        if (is_array($ans) && is_array($ans['__meta'] ?? null)) {
            $pc = strtoupper(trim((string)($ans['__meta']['paper_code'] ?? '')));
            if (in_array($pc, ['A', 'B'], true)) {
                $paper = $pc;
            }
        }
        $stats[$paper]['count']++;
        $stats[$paper]['passed'] += ((int)$row['is_passed'] === 1 ? 1 : 0);
        $stats[$paper]['sum'] += (float)($row['total_score'] ?? 0);
    }

    foreach (['A', 'B'] as $p) {
        $c = $stats[$p]['count'];
        $stats[$p]['avg_score'] = $c ? round($stats[$p]['sum'] / $c, 1) : 0;
        $stats[$p]['pass_rate'] = $c ? round($stats[$p]['passed'] / $c * 100, 1) : 0;
        unset($stats[$p]['sum']);
    }

    echo json_encode(['transactional_ab_stats' => $stats], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    $db->rollBack();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
