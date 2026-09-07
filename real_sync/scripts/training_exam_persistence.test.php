<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/kernel/ApiException.php';
require_once __DIR__ . '/../api/exam/ExamSubmissionService.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
$db->exec('CREATE TABLE exams (id INTEGER PRIMARY KEY, title TEXT, pass_score INTEGER, is_active INTEGER, exam_paper TEXT)');
$db->exec('CREATE TABLE exam_questions (id INTEGER PRIMARY KEY, exam_id INTEGER, question_type INTEGER, answer TEXT, score INTEGER, analysis TEXT, sort_order INTEGER)');
$db->exec('CREATE TABLE staffs (user_id INTEGER, name TEXT, phone TEXT, role TEXT)');
$db->exec("INSERT INTO staffs VALUES (77, 'Test learner', 'test', 'coach')");
$db->exec('CREATE TABLE exam_records (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, module_id INTEGER, exam_type TEXT, total_score INTEGER, passing_score INTEGER, is_passed INTEGER, answers TEXT, wrong_answers TEXT, duration INTEGER, status TEXT, completed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$modules = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach ($modules as $exam) {
    $db->prepare("INSERT INTO exams VALUES (?, ?, ?, 1, 'A')")->execute([$exam['examId'], $exam['title'], $exam['passScore']]);
    $answers = [];
    foreach ($exam['questions'] as $index => $question) {
        $db->prepare("INSERT INTO exam_questions VALUES (?, ?, 1, ?, ?, '', ?)")->execute([$question['id'], $exam['examId'], $question['answer'], $question['score'], $index]);
        $answers[$question['id']] = $question['answer'];
    }
    $db->beginTransaction();
    $result = (new ExamSubmissionService($db))->submit(77, ['source_exam_id' => $exam['examId'], 'selected_exam_id' => $exam['examId'], 'paper_code' => 'A', 'answers' => $answers, 'time_spent' => 60]);
    $db->commit();
    if ($result['score'] !== 100 || !$result['is_passed']) throw new RuntimeException('Server grading failed');
    $record = $db->query('SELECT * FROM exam_records WHERE id = ' . $result['exam_record_id'])->fetch(PDO::FETCH_ASSOC);
    if ($record['status'] !== 'completed' || (int)$record['module_id'] !== $exam['examId']) throw new RuntimeException('Persistence failed');
}
// Execute the actual admin list query with its empty-filter values against isolated records.
$admin = file_get_contents(__DIR__ . '/../api/admin/exam-scores.php');
if (!preg_match('/\$stmt = \$db->prepare\("(SELECT r\.\*,[\s\S]*?)"\);/', $admin, $match)) throw new RuntimeException('Admin query not found');
$query = str_replace(['$whereSql', '$offset', '$perPage'], ['', '0', '20'], $match[1]);
$rows = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 6) throw new RuntimeException('Admin list missing scores');
foreach ($rows as $row) {
    if ($row['name'] !== 'Test learner' || (int)$row['total_score'] !== 100 || !$row['exam_title']) throw new RuntimeException('Admin mapping failed');
}
echo json_encode(['passed' => true, 'submitted_modules' => 6, 'admin_query_rows' => count($rows), 'database' => 'isolated SQLite; MySQL idempotency not exercised'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
