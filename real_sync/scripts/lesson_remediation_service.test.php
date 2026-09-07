<?php
declare(strict_types=1);
require_once __DIR__ . '/lesson_lifecycle_e2e.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonSuggestionService.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonWorkbookParser.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonExportService.php';

function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$pdo = database(':memory:');
createSchema($pdo);
$pdo->exec("INSERT INTO staffs VALUES (1, 101, '教练', 10, 'coach', 1), (2, 102, '店长', 10, 'manager', 1)");
$service = new LessonSubmissionService($pdo, new PlatformPrivateFileStorage(sys_get_temp_dir()));
$created = $service->create(['store_name' => '测试门店', 'store_id' => 10, 'author_name' => '教练', 'course_line' => '跑酷', 'age_range' => '6-8岁', 'class_stage' => '初级', 'class_level' => '初级', 'lesson_date' => '2026-09-07', 'title' => '跳箱'], 1);
$id = (int) $created['id'];
foreach ([1 => 'action', 2 => 'game', 3 => 'safety'] as $cardId => $type) {
    $pdo->prepare("INSERT INTO knowledge_items (id, item_code, current_version_id, title, summary, content, content_type, domain_code, risk_level, subject, age_group, training_type, tags, status, publication_status) VALUES (?, ?, ?, '跳箱越障', '保护站位', '安全保护', ?, 'course_skills', 'high', '跳箱', '6-8岁', '跑酷', '[]', 1, 'published')")->execute([$cardId, 'CARD-' . $cardId, 100 + $cardId, $type]);
    $pdo->prepare("INSERT INTO knowledge_item_versions (version_id, knowledge_item_id, status) VALUES (?, ?, 'active')")->execute([100 + $cardId, $cardId]);
}
$content = validContent(['store_name' => '测试门店', 'author_name' => '教练', 'course_line' => '跑酷', 'age_range' => '6-8岁', 'class_level' => '初级', 'lesson_date' => '2026-09-07', 'title' => '跳箱']);
$content['phases'][0]['activity'] = '跳箱越障';
$content['equipment'] = ['软垫'];
$content['progressions'] = ['降低高度'];
$drafts = new LessonDraftService($pdo);
$saved = $drafts->saveDraft($id, $content, 1, 1);
$matcher = new LessonKnowledgeMatcher($pdo);
$first = $matcher->optimize($id, 1);
verify(count($first['suggestions']) >= 3, '应有多条有效建议');
verify($first['inserted_count'] === 0, '保存已自动生成且刷新去重');
$detail = $drafts->detail($id, 1);
verify($first['suggestions'] === $detail['suggestions'], '刷新和详情 DTO 一致');
$decisions = new LessonSuggestionService($pdo);
if (($argv[1] ?? '') === '--editor-fixture') {
    echo json_encode($detail['current_version']['content_json'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
if (($argv[1] ?? '') === '--editor-save') {
    $ignoredPhase = array_values(array_filter($first['suggestions'], static fn($row) => $row['field_path'] === 'phases.0.activity'))[0];
    $decisions->decide($id, (int) $ignoredPhase['id'], 'ignored', 1, null, 2);
    $editorContent = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    verify($editorContent['phases'] === $detail['current_version']['content_json']['phases'], '编辑器改变了未编辑阶段');
    $saved = $drafts->saveDraft($id, $editorContent, 1, 2);
    $findSuggestion = static function (array $rows) use ($ignoredPhase): array {
        $matches = array_values(array_filter($rows, static fn($row) => $row['field_path'] === $ignoredPhase['field_path'] && $row['knowledge_item_id'] === $ignoredPhase['knowledge_item_id'] && $row['suggestion_type'] === $ignoredPhase['suggestion_type']));
        verify(count($matches) === 1, '阶段建议重复或字段路径改变');
        return $matches[0];
    };
    $unchanged = $findSuggestion($matcher->optimize($id, 1)['suggestions']);
    verify($unchanged['decision'] === 'ignored', '只修改反思导致忽略建议重新 pending');
    verify((int) $unchanged['decided_by'] === 1 && $unchanged['decided_at'] !== null, '忽略留痕丢失');
    $editorContent['phases'][0]['activity'] .= '，增加跳箱越障练习';
    $drafts->saveDraft($id, $editorContent, 1, (int) $saved['status_version']);
    $changed = $findSuggestion($matcher->optimize($id, 1)['suggestions']);
    verify($changed['decision'] === 'pending', '真实阶段修改应重新评估');
    echo json_encode(['unchanged_decision' => $unchanged['decision'], 'changed_decision' => $changed['decision'], 'field_path' => $unchanged['field_path']], JSON_THROW_ON_ERROR);
    exit;
}
$ignored = array_values(array_filter($first['suggestions'], static fn($row) => $row['field_path'] === 'safety.physical'))[0];
$decisions->decide($id, (int) $ignored['id'], 'ignored', 1, null, 2);
$accepted = array_values(array_filter($first['suggestions'], static fn($row) => $row['field_path'] !== 'safety.physical'))[0];
$content = $detail['current_version']['content_json'];
$target = &$content;
foreach (explode('.', $accepted['field_path']) as $key) $target = &$target[$key];
$target .= "\n" . $accepted['apply_content'];
unset($target);
$result = $decisions->decide($id, (int) $accepted['id'], 'accepted', 1, $content, 2);
verify($result['status_version'] === 3, '采纳创建新版本');
$current = $matcher->optimize($id, 1);
verify(count(array_filter($current['suggestions'], static fn($row) => $row['decision'] === 'ignored')) === 1, '未变字段的忽略决定继承');
verify(count(array_filter($current['suggestions'], static fn($row) => $row['field_path'] === $accepted['field_path'] && $row['knowledge_item_id'] === $accepted['knowledge_item_id'])) === 0, '已采纳正文不重复推荐');
try { $decisions->decide($id, (int) $accepted['id'], 'accepted', 1, $content, 2); throw new RuntimeException('重复采纳未拒绝'); } catch (PlatformApiException $error) { verify($error->errorCode() === 'lesson_submission_conflict', '重复采纳返回冲突'); }
$review = new LessonSubmissionReviewService($pdo);
try { $review->submit($id, 1, 3); throw new RuntimeException('当前 pending 未阻止提交'); } catch (PlatformApiException $error) { verify($error->errorCode() === 'lesson_suggestions_pending', $error->getMessage()); }
foreach ($current['suggestions'] as $row) if ($row['decision'] === 'pending') $decisions->decide($id, (int) $row['id'], 'ignored', 1, null, 3);
$content['reflection']['athletic'] = '补充观察';
$drafts->saveDraft($id, $content, 1, 3);
verify((int) $pdo->query("SELECT COUNT(*) FROM lesson_suggestions WHERE decision = 'pending'")->fetchColumn() > 0, '历史 pending 保留');
$submitted = $review->submit($id, 1, 4);
$returned = (new LessonReviewDecisionService($pdo))->decide((int) $submitted['review_task_id'], 2, 'returned', '补充反思', ['store_review']);
$content['reflection']['athletic'] = '退回后补充观察结果';
$revised = $drafts->saveDraft($id, $content, 1, (int) $drafts->detail($id, 1)['submission']['status_version']);
$resubmitted = $review->submit($id, 1, (int) $revised['status_version']);
verify($resubmitted['status'] === 'store_review', '退回后可重新提交');
echo "PASS: DTO, dedup, accept, ignore, save, pending isolation, return, resubmit\n";

$storage = new PlatformPrivateFileStorage(sys_get_temp_dir() . '/lesson-remediation-' . bin2hex(random_bytes(4)));
$parserService = new LessonSubmissionService($pdo, $storage);
$parseSubmission = $parserService->create(['store_name' => '测试门店', 'store_id' => 10, 'author_name' => '教练', 'course_line' => '跑酷', 'age_range' => '6-8岁', 'class_stage' => '初级', 'class_level' => '初级', 'lesson_date' => '2026-09-07', 'title' => '跳箱'], 1);
$fixture = tempnam(sys_get_temp_dir(), 'lesson-auto-');
createDocx($fixture);
$stored = $storage->storeBytes(file_get_contents($fixture), 'lesson-submissions/submission-' . $parseSubmission['id'], 'docx');
$pdo->prepare("INSERT INTO lesson_source_files (submission_id, original_name, storage_key, mime_type, extension, byte_size, sha256, uploaded_by) VALUES (?, 'lesson.docx', ?, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx', ?, ?, 1)")->execute([$parseSubmission['id'], $stored['storage_key'], filesize($fixture), hash_file('sha256', $fixture)]);
$sourceId = (int) $pdo->lastInsertId();
$parsed = $parserService->parseUploadedFile((int) $parseSubmission['id'], $sourceId, 1);
verify($parsed['suggestion_status'] === 'completed', '解析后自动建议完成');
$retry = $parserService->parseUploadedFile((int) $parseSubmission['id'], $sourceId, 1);
verify($retry['current_version_id'] === $parsed['current_version_id'], '解析响应丢失重试复用原版本');
$pdo->exec('ALTER TABLE knowledge_item_sources RENAME TO temporarily_unavailable_sources');
$failed = $parserService->parseUploadedFile((int) $parseSubmission['id'], $sourceId, 1);
verify($failed['suggestion_status'] === 'failed' && $failed['current_version_id'] === $parsed['current_version_id'] && $failed['status'] === 'editable', '建议失败保留已解析教案');
$pdo->exec('ALTER TABLE temporarily_unavailable_sources RENAME TO knowledge_item_sources');
$recovered = $parserService->parseUploadedFile((int) $parseSubmission['id'], $sourceId, 1);
verify($recovered['suggestion_status'] === 'completed' && $recovered['current_version_id'] === $parsed['current_version_id'], '建议失败后原版本可重试');
echo "PASS: parse automatically optimizes and retry reuses parsed version\n";

$xlsxSubmission = $parserService->create(['store_name' => '测试门店', 'store_id' => 10, 'author_name' => '教练', 'course_line' => '跑酷', 'age_range' => '6-8岁', 'class_stage' => '初级', 'class_level' => '初级', 'lesson_date' => '2026-09-07', 'title' => '跳箱'], 1);
$exportClass = new ReflectionClass(LessonExportService::class);
$bytes = $exportClass->getMethod('xlsx')->invoke($exportClass->newInstanceWithoutConstructor(), $content);
$stored = $storage->storeBytes($bytes, 'lesson-submissions/submission-' . $xlsxSubmission['id'], 'xlsx');
$pdo->prepare("INSERT INTO lesson_source_files (submission_id, original_name, storage_key, mime_type, extension, byte_size, sha256, uploaded_by) VALUES (?, 'lesson.xlsx', ?, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx', ?, ?, 1)")->execute([$xlsxSubmission['id'], $stored['storage_key'], strlen($bytes), hash('sha256', $bytes)]);
$xlsxParsed = $parserService->parseUploadedFile((int) $xlsxSubmission['id'], (int) $pdo->lastInsertId(), 1);
verify($xlsxParsed['suggestion_status'] === 'completed', 'XLSX 解析后自动生成建议');
echo "PASS: XLSX parse automatically optimizes\n";
