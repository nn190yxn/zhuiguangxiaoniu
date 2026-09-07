<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/admin/recruitment/platform/RecruitmentPlatformJobAdapter.php';
require_once __DIR__ . '/../api/admin/services/SystemOverviewService.php';
require_once __DIR__ . '/../api/drill/v2/services/DrillEmployeeApiService.php';
require_once __DIR__ . '/../api/drill/v2/services/DrillConversationService.php';

$checks = [];
function check(bool $condition, string $name): void {
    global $checks;
    if (!$condition) throw new RuntimeException($name);
    $checks[] = $name;
}
function database(): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

$db = database();
$db->exec("CREATE TABLE recruitment_resume_documents (id INTEGER PRIMARY KEY, batch_id INTEGER);
    CREATE TABLE recruitment_resume_jobs (id INTEGER PRIMARY KEY, document_id INTEGER, status TEXT, priority INTEGER, idempotency_hash TEXT);
    CREATE TABLE platform_jobs (id INTEGER PRIMARY KEY, job_type TEXT, object_type TEXT, object_id TEXT, idempotency_key TEXT, payload_json TEXT, payload_hash TEXT, status TEXT, priority INTEGER, available_at TEXT, max_attempts INTEGER, attempt_count INTEGER DEFAULT 0, completed_at TEXT, error_code TEXT, error_summary TEXT, recovery_required INTEGER DEFAULT 0, updated_at TEXT, UNIQUE(job_type, idempotency_key));
    INSERT INTO recruitment_resume_documents VALUES (1, 10), (2, 20);");
$insert = $db->prepare("INSERT INTO recruitment_resume_jobs VALUES (?, ?, 'pending', 100, ?)");
for ($i = 1; $i <= 102; $i++) $insert->execute([$i, $i === 102 ? 2 : 1, hash('sha256', (string) $i)]);
$adapter = new RecruitmentPlatformJobAdapter($db);
check($adapter->dispatchBatch(10)['dispatched_count'] === 100, '招聘首批投递100个任务');
check($adapter->dispatchBatch(10)['dispatched_count'] === 1, '招聘后续批次越过已入队任务');
check($adapter->dispatchBatch(10)['dispatched_count'] === 0, '招聘重复触发不再创建任务');
check((int) $db->query('SELECT COUNT(*) FROM platform_jobs')->fetchColumn() === 101, '招聘队列持久化且保持批次范围');
check($adapter->dispatchBatch(999)['dispatched_count'] === 0, '招聘空批次返回明确结果');
$db->exec("UPDATE platform_jobs SET status = 'dead_letter', attempt_count = 3, recovery_required = 1 WHERE object_id = '1'");
$job = $db->query('SELECT * FROM recruitment_resume_jobs WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$db->beginTransaction();
$adapter->retry($job);
$adapter->retry($job);
$db->commit();
$queued = $db->query("SELECT * FROM platform_jobs WHERE object_id = '1'")->fetch(PDO::FETCH_ASSOC);
check($queued['status'] === 'pending' && (int) $queued['max_attempts'] === 6 && (int) $queued['attempt_count'] === 3, '招聘重试复用任务并保留尝试历史');
check((int) $db->query('SELECT COUNT(*) FROM platform_jobs')->fetchColumn() === 101, '招聘重复重试只有一份队列任务');
$db->exec("CREATE TRIGGER fail_queue BEFORE INSERT ON platform_jobs BEGIN SELECT RAISE(ABORT, 'test failure'); END;");
try { $adapter->dispatchBatch(20); throw new LogicException('failure expected'); }
catch (PDOException | PlatformJobIdempotencyConflict $error) { check(!$db->inTransaction(), '招聘投递失败回滚事务'); }

$metricsDb = database();
$metricsDb->exec("CREATE TABLE staffs (id INTEGER PRIMARY KEY, account_locked_until TEXT);
    CREATE TABLE system_error_logs (id INTEGER PRIMARY KEY, level TEXT, created_at TEXT);
    INSERT INTO staffs VALUES (1, '2026-09-07 13:00:00'), (2, '2026-09-07 12:00:00'), (3, NULL), (4, '2026-09-06 13:00:00');
    INSERT INTO system_error_logs VALUES (1, 'error', '2026-09-07 00:00:00'), (2, 'fatal', '2026-09-07 23:59:59'), (3, 'warning', '2026-09-07 12:00:00'), (4, 'error', '2026-09-08 00:00:00');");
$now = new DateTimeImmutable('2026-09-07 12:00:00');
$metrics = (new SystemOverviewService($metricsDb))->metrics($now);
check($metrics['summary'] === ['locked_account_count' => 1, 'system_error_count' => 2], '系统指标按有效期和当日错误级别真实计数');
$emptyDb = database();
$emptyDb->exec('CREATE TABLE staffs (account_locked_until TEXT); CREATE TABLE system_error_logs (level TEXT, created_at TEXT)');
check((new SystemOverviewService($emptyDb))->metrics($now)['summary'] === ['locked_account_count' => 0, 'system_error_count' => 0], '系统指标空表真实返回零');
$missing = (new SystemOverviewService(database()))->metrics($now);
check($missing['summary'] === ['locked_account_count' => null, 'system_error_count' => null], '系统指标查询失败返回不可用');

$drillDb = database();
$drillDb->exec("CREATE TABLE drill_attempts (id INTEGER PRIMARY KEY, staff_id INTEGER, status TEXT, evaluation_context TEXT, started_at TEXT, completed_at TEXT, created_at TEXT, scenario_version_id INTEGER,
    assignment_id INTEGER, plan_id INTEGER, plan_item_id INTEGER, domain_id INTEGER, practice_type TEXT, current_stage_id INTEGER, last_completed_turn_no INTEGER, status_version INTEGER, session_goal_json TEXT DEFAULT '{}', persona_snapshot_hash TEXT, process_snapshot_hash TEXT, scenario_snapshot_hash TEXT, rubric_snapshot_hash TEXT, calibration_snapshot_hash TEXT, session_goal_snapshot_hash TEXT, scenario_snapshot_json TEXT DEFAULT '{}', persona_snapshot_json TEXT DEFAULT '{}');
    CREATE TABLE drill_scenario_versions (id INTEGER PRIMARY KEY, title TEXT);
    CREATE TABLE drill_evaluations (id INTEGER PRIMARY KEY, attempt_id INTEGER, status TEXT, failure_code TEXT, total_score INTEGER, dimension_scores_json TEXT, critical_results_json TEXT, suggestions_json TEXT);
    CREATE TABLE drill_evaluation_reports (id INTEGER PRIMARY KEY, attempt_id INTEGER, evaluation_id INTEGER, evaluation_grade TEXT, readiness_status TEXT, report_json TEXT, status TEXT);
    CREATE TABLE drill_certifications (id INTEGER PRIMARY KEY, attempt_id INTEGER, certified_at TEXT);
    CREATE TABLE drill_learning_recommendations (id INTEGER PRIMARY KEY, staff_id INTEGER, attempt_id INTEGER, evaluation_id INTEGER, learning_resource_version_id INTEGER, criterion_code TEXT, reason_snapshot_json TEXT);
    CREATE TABLE drill_learning_resource_versions (id INTEGER PRIMARY KEY, title TEXT, mobile_locator TEXT);
    CREATE TABLE drill_review_tasks (id INTEGER PRIMARY KEY, attempt_id INTEGER, evaluation_id INTEGER, status TEXT, decision TEXT, comment TEXT, ai_score INTEGER, final_score INTEGER, adjustment_reason TEXT, reviewed_at TEXT);
    CREATE TABLE drill_mastery_scores (id INTEGER PRIMARY KEY, staff_id INTEGER, domain_id INTEGER, scope_type TEXT, scope_key TEXT, latest_attempt_id INTEGER, latest_score INTEGER, effective_best_score INTEGER, best_attempt_id INTEGER);
    CREATE TABLE drill_audio_assets (id INTEGER PRIMARY KEY, staff_id INTEGER, attempt_id INTEGER, status TEXT, retention_until TEXT, expired_at TEXT, asset_type TEXT);
    CREATE TABLE drill_process_stages (id INTEGER PRIMARY KEY, stage_code TEXT, name TEXT, sort_order INTEGER);
    CREATE TABLE drill_attempt_stage_progress (id INTEGER PRIMARY KEY, attempt_id INTEGER, stage_id INTEGER, sort_order INTEGER, status TEXT, started_at TEXT, completed_at TEXT);
    CREATE TABLE drill_turns (id INTEGER PRIMARY KEY, attempt_id INTEGER, turn_no INTEGER, stage_id INTEGER, speaker TEXT, input_type TEXT, content TEXT, generation_metadata_json TEXT, finalized_at TEXT);
    CREATE TABLE drill_training_domains (id INTEGER PRIMARY KEY, domain_code TEXT);
    CREATE TABLE drill_growth_level_snapshots (id INTEGER PRIMARY KEY, staff_id INTEGER, domain_id INTEGER, calculated_at TEXT);
    INSERT INTO drill_training_domains VALUES (1, 'new_signing');
    INSERT INTO drill_attempts (id, staff_id, status, evaluation_context, scenario_version_id, domain_id, current_stage_id, last_completed_turn_no, status_version, practice_type, created_at) VALUES (1, 7, 'active', 'full_process', 1, 1, 2, 4, 9, 'full_process', '2026-09-07'), (2, 8, 'active', 'full_process', 1, 1, 2, 0, 1, 'full_process', '2026-09-07'), (3, 7, 'evaluating', 'full_process', 1, 1, 2, 0, 1, 'full_process', '2026-09-07');
    INSERT INTO drill_scenario_versions VALUES (1, 'scene');
    INSERT INTO drill_evaluations VALUES (10, 1, 'completed', NULL, 70, '{}', '{}', '{}'), (11, 1, 'completed', NULL, 85, '{}', '{}', '{}'), (12, 3, 'failed', 'provider_failure', NULL, '{}', '{}', '{}');
    INSERT INTO drill_evaluation_reports VALUES (20, 1, 10, 'C', NULL, '{\"summary\":\"old\"}', 'published'), (21, 1, 11, 'A', NULL, '{\"summary\":\"current\"}', 'published');
    INSERT INTO drill_learning_resource_versions VALUES (1, 'resource', '/learning/example');
    INSERT INTO drill_learning_recommendations VALUES (1, 7, 1, 11, 1, 'needs', '{\"evidence\":{\"quoted_text\":\"actual quote\"}}'), (2, 7, 1, 10, 1, 'old', '{}'), (3, 8, 2, 11, 1, 'other', '{}');
    INSERT INTO drill_review_tasks VALUES (1, 1, 10, 'completed', 'old', 'old', 70, 70, NULL, NULL), (2, 1, 11, 'completed', 'passed', 'actual review', 85, 90, 'actual reason', '2026-09-07');
    INSERT INTO drill_mastery_scores VALUES (1, 7, 1, 'full_process', 'full_process', 1, 85, 85, 1), (2, 8, 1, 'full_process', 'full_process', 2, 80, 80, 2);
    INSERT INTO drill_audio_assets VALUES (1, 7, 1, 'expired', NULL, '2026-09-07', 'turn_recording'), (2, 7, 1, 'completed', NULL, NULL, 'text_input'), (3, 8, 2, 'completed', NULL, NULL, 'turn_recording');
    INSERT INTO drill_process_stages VALUES (1, 'opening', 'opening', 1), (2, 'needs', 'needs', 2);
    INSERT INTO drill_attempt_stage_progress VALUES (1, 1, 1, 1, 'completed', NULL, NULL), (2, 1, 2, 2, 'active', NULL, NULL);");
$turn = $drillDb->prepare("INSERT INTO drill_turns VALUES (?, 1, ?, 2, ?, 'text', ?, NULL, '2026-09-07')");
for ($i = 1; $i <= 4; $i++) $turn->execute([$i, $i, $i % 2 ? 'employee' : 'customer', 'turn-' . $i]);
$conversation = new DrillConversationService($drillDb);
$before = $drillDb->query('SELECT total_changes()')->fetchColumn();
$resume = $conversation->resumeAttempt(1, 7);
check(count($resume['turns']) === 4 && $resume['turns'][3]['content'] === 'turn-4', '演练完整恢复四轮持久化消息');
check($resume['attempt']['attempt_id'] === 1 && $resume['attempt']['status_version'] === 9 && $resume['practice_context']['current_stage']['stage_id'] === 2, '演练恢复实例版本和当前阶段');
check($conversation->resumeAttempt(1, 7) === $resume && $drillDb->query('SELECT total_changes()')->fetchColumn() === $before, '演练重复恢复不创建实例或消息');
$employee = new DrillEmployeeApiService($drillDb);
$drillDb->exec("CREATE TABLE drill_assignments (id INTEGER PRIMARY KEY, staff_id INTEGER, status TEXT, failed_attempts INTEGER, current_attempt_id INTEGER, starts_at TEXT, due_at TEXT, status_version INTEGER, publication_id INTEGER);
    CREATE TABLE drill_plan_publications (id INTEGER PRIMARY KEY, plan_id INTEGER);
    CREATE TABLE drill_plans (id INTEGER PRIMARY KEY, name TEXT, plan_type TEXT, recording_retention_days INTEGER, minimum_client_version TEXT, domain_id INTEGER);
    CREATE TABLE drill_plan_items (id INTEGER PRIMARY KEY, plan_id INTEGER, sort_order INTEGER, evaluation_context TEXT, scenario_version_id INTEGER);
    ALTER TABLE drill_scenario_versions ADD COLUMN objectives_json TEXT;
    ALTER TABLE drill_scenario_versions ADD COLUMN key_actions_json TEXT;
    INSERT INTO drill_plan_publications VALUES (1, 1);
    INSERT INTO drill_plans VALUES (1, 'required plan', 'required', 180, '1', 1);
    INSERT INTO drill_plan_items VALUES (2, 1, 1, 'ai_roleplay', 1);
    INSERT INTO drill_assignments VALUES (1, 7, 'retry_available', 1, 1, NULL, NULL, 3, 1);
    UPDATE drill_attempts SET assignment_id = 1, plan_item_id = 2, status = 'completed' WHERE id = 1;");
$assignment = $employee->assignments(7, 1)['items'][0];
check($assignment['status'] === 'retry_available' && (int) $assignment['current_attempt_id'] === 1 && $assignment['current_attempt_status'] === 'completed', '必修任务可重练状态保留旧ID并返回真实终态');
check((int) $assignment['current_attempt_plan_item_id'] === 2 && (int) $assignment['current_attempt_status_version'] === 9, '必修恢复条件返回实例计划项与状态版本');
$drillDb->exec("UPDATE drill_assignments SET status = 'in_progress' WHERE id = 1; UPDATE drill_attempts SET status = 'active' WHERE id = 1;");
check($employee->assignments(7, 1)['items'][0]['current_attempt_status'] === 'active', '进行中任务关联活跃实例状态');
$drillDb->exec('UPDATE drill_assignments SET current_attempt_id = 2 WHERE id = 1');
check($employee->assignments(7, 1)['items'][0]['current_attempt_status'] === null, '必修当前实例关联限制同一任务及员工');
$result = $employee->results(7, 1)['items'];
check(count($result) === 1 && $result[0]['evaluation_id'] === 11 && $result[0]['report']['summary'] === 'current', '演练结果固定最新评估与对应发布报告');
check(count($result[0]['learning_recommendations']) === 1 && $result[0]['learning_recommendations'][0]['reason']['evidence']['quoted_text'] === 'actual quote', '演练推荐读取本次评估真实证据');
check($result[0]['review']['comment'] === 'actual review' && count($result[0]['growth']) === 1, '演练复核与成长返回实际关联记录');
check(count($result[0]['media']) === 1 && $result[0]['media'][0]['status'] === 'expired', '演练录音排除文本证据和其他实例');
$empty = $employee->results(7, 3)['items'][0];
check($empty['evaluation_status'] === 'failed' && $empty['review'] === null && $empty['media'] === [] && $empty['growth'] === [] && $empty['learning_recommendations'] === [], '演练评分失败与尚无关联记录区分');
check(count($employee->progress(7, null)['mastery']) === 1, '成长概览使用现有scope字段查询');
$drillDb->exec('ALTER TABLE drill_review_tasks RENAME TO unavailable_review_tasks');
try { $employee->results(7, 1); throw new LogicException('failure expected'); }
catch (PDOException $error) { check(true, '关联查询失败向上抛出，禁止伪装空记录'); }
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
