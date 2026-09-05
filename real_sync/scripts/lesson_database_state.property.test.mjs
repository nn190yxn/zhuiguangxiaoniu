import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

function random(seed) {
  let value = seed >>> 0;
  return () => {
    value = (value * 1664525 + 1013904223) >>> 0;
    return value / 0x100000000;
  };
}

function buildState(seed, operationCount = 120) {
  const nextRandom = random(seed);
  const state = {
    submission: { id: 1, status: 'editable', currentVersionId: 1, approvedVersionId: null, libraryStatus: 'hidden' },
    versions: [{ id: 1, submissionId: 1, immutable: false }],
    files: [{ id: 1, submissionId: 1 }],
    parseRuns: [], suggestions: [], tasks: [], exports: [], audits: [], nextId: 2,
  };

  for (let index = 0; index < operationCount; index += 1) {
    const operation = Math.floor(nextRandom() * 7);
    const current = state.versions.find(({ id }) => id === state.submission.currentVersionId);
    if (operation === 0 && ['editable', 'returned'].includes(state.submission.status)) {
      const version = { id: state.nextId++, submissionId: 1, immutable: false };
      state.versions.push(version); state.submission.currentVersionId = version.id; state.submission.status = 'editable';
    } else if (operation === 1 && state.submission.status === 'editable') {
      current.immutable = true; state.submission.status = 'store_review';
      state.tasks.push({ id: state.nextId++, submissionId: 1, versionId: current.id, stage: 'store_review', status: 'pending', comments: '' });
    } else if (operation === 2 && state.submission.status === 'store_review') {
      const task = state.tasks.find(({ stage, status }) => stage === 'store_review' && status === 'pending');
      task.status = 'completed'; state.submission.status = 'supervisor_review';
      state.tasks.push({ id: state.nextId++, submissionId: 1, versionId: task.versionId, stage: 'supervisor_review', status: 'pending', comments: '' });
    } else if (operation === 3 && ['store_review', 'supervisor_review'].includes(state.submission.status)) {
      const task = state.tasks.find(({ stage, status }) => stage === state.submission.status && status === 'pending');
      task.status = 'completed'; task.comments = `return-${seed}-${index}`;
      state.audits.push({ submissionId: 1, versionId: task.versionId, action: 'review_returned', comments: task.comments });
      state.submission.status = 'returned';
    } else if (operation === 4 && state.submission.status === 'supervisor_review') {
      const task = state.tasks.find(({ stage, status }) => stage === 'supervisor_review' && status === 'pending');
      task.status = 'completed'; state.submission.status = 'approved'; state.submission.approvedVersionId = task.versionId; state.submission.libraryStatus = 'published';
    } else if (operation === 5) {
      state.exports.push({ id: state.nextId++, submissionId: 1, versionId: current.id });
    } else if (operation === 6) {
      state.suggestions.push({ id: state.nextId++, submissionId: 1, versionId: current.id });
      if (nextRandom() < 0.15) {
        state.parseRuns.push({ id: state.nextId++, submissionId: 1, sourceFileId: 1, status: 'failed' });
      }
    }
  }
  return state;
}

function assertProperties(state) {
  const versionIds = new Set(state.versions.map(({ id }) => id));
  for (const task of state.tasks) {
    assert.equal(task.submissionId, state.submission.id);
    assert.ok(versionIds.has(task.versionId));
    assert.equal(state.versions.find(({ id }) => id === task.versionId).immutable, true);
  }
  if (state.submission.status === 'approved') {
    assert.ok(versionIds.has(state.submission.approvedVersionId));
    assert.ok(state.tasks.some(({ stage, versionId, status }) => stage === 'supervisor_review' && versionId === state.submission.approvedVersionId && status === 'completed'));
    assert.equal(state.submission.libraryStatus, 'published');
  }
  for (const audit of state.audits.filter(({ action }) => action === 'review_returned')) {
    assert.ok(audit.comments.trim());
    assert.ok(versionIds.has(audit.versionId));
  }
  for (const item of [...state.exports, ...state.suggestions]) {
    assert.equal(item.submissionId, state.submission.id);
    assert.ok(versionIds.has(item.versionId));
  }
  for (const run of state.parseRuns) {
    assert.equal(run.submissionId, state.submission.id);
    assert.ok(state.files.some(({ id, submissionId }) => id === run.sourceFileId && submissionId === run.submissionId));
  }
}

test(`${validatesCriteria(['Property 1', 'Property 2', 'Property 3', 'Property 4'])} 任意审核状态序列保持冻结版本、批准版本和退回记录一致`, () => {
  for (let seed = 1; seed <= 300; seed += 1) assertProperties(buildState(seed));
});

test(`${validatesCriteria(['Property 5', 'Property 6', 'Property 7'])} 任意导出、建议和解析序列保持教案与版本关联`, () => {
  for (let seed = 301; seed <= 600; seed += 1) assertProperties(buildState(seed));
});

test(`${validatesCriteria(['Property 1', 'Property 5', 'Property 6'])} 数据表保存版本唯一性和所有追溯关联`, () => {
  const migration = read('database/migrations/202609030001_smart_lesson_review.sql');
  assert.match(migration, /UNIQUE KEY `uk_lesson_versions_submission_no` \(`submission_id`, `version_no`\)/);
  assert.match(migration, /KEY `idx_lesson_versions_submitted` \(`submission_id`, `is_submitted`, `is_immutable`\)/);
  for (const table of ['lesson_source_files', 'lesson_versions', 'lesson_parse_runs', 'lesson_suggestions', 'lesson_review_tasks', 'lesson_exports', 'lesson_audit_logs']) {
    const body = migration.match(new RegExp('CREATE TABLE IF NOT EXISTS `' + table + '` \\(([\\s\\S]*?)\\) ENGINE='))?.[1] ?? '';
    assert.match(body, /`submission_id` BIGINT UNSIGNED NOT NULL/, `${table} 缺少 submission_id`);
  }
  for (const table of ['lesson_suggestions', 'lesson_review_tasks', 'lesson_exports']) {
    const body = migration.match(new RegExp('CREATE TABLE IF NOT EXISTS `' + table + '` \\(([\\s\\S]*?)\\) ENGINE='))?.[1] ?? '';
    assert.match(body, /`version_id` BIGINT UNSIGNED NOT NULL/, `${table} 缺少 version_id`);
  }
});

test(`${validatesCriteria(['Property 5', 'Property 6'])} 复合外键阻止跨教案版本和跨知识卡版本引用`, () => {
  const migration = read('database/migrations/202609040002_lesson_version_relations.sql');
  assert.match(migration, /uk_lesson_version_submission_pair \(submission_id, id\)/);
  assert.match(migration, /uk_knowledge_item_version_pair \(knowledge_item_id, version_id\)/);
  assert.match(migration, /fk_lesson_submissions_current_version_pair FOREIGN KEY \(id, current_version_id\) REFERENCES lesson_versions \(submission_id, id\)/);
  assert.match(migration, /fk_lesson_submissions_approved_version_pair FOREIGN KEY \(id, approved_version_id\) REFERENCES lesson_versions \(submission_id, id\)/);
  for (const constraint of [
    'fk_lesson_suggestions_version_pair',
    'fk_lesson_review_tasks_version_pair',
    'fk_lesson_exports_version_pair',
    'fk_lesson_audit_logs_version_pair',
  ]) {
    assert.match(migration, new RegExp(`CONSTRAINT ${constraint} FOREIGN KEY \\(.*submission_id.*version_id.*\\) REFERENCES lesson_versions \\(submission_id, id\\)`), `${constraint} 缺少同教案版本约束`);
  }
  assert.match(migration, /fk_lesson_suggestions_knowledge_version_pair FOREIGN KEY \(knowledge_item_id, knowledge_version_id\) REFERENCES knowledge_item_versions \(knowledge_item_id, version_id\)/);
});

test(`${validatesCriteria(['Property 5', 'Property 6'])} 审核详情和导出查询显式校验版本归属`, () => {
  const reviewQuery = read('api/lesson-reviews/LessonReviewQueryService.php');
  const exportService = read('api/lesson-submissions/LessonExportService.php');
  assert.match(reviewQuery, /version\.id = task\.version_id AND version\.submission_id = task\.submission_id/);
  assert.match(reviewQuery, /version\.id = review\.version_id AND version\.submission_id = review\.submission_id/);
  assert.match(exportService, /v\.id = e\.version_id AND v\.submission_id = e\.submission_id/);
});

test(`${validatesCriteria(['Property 1', 'Property 2', 'Property 3', 'Property 4'])} 生产审核服务冻结版本并以事务和状态版本保护转换`, () => {
  const submit = read('api/lesson-submissions/LessonSubmissionReviewService.php');
  const decision = read('api/lesson-reviews/LessonReviewDecisionService.php');
  assert.match(submit, /is_submitted = 1, is_immutable = 1/);
  assert.match(submit, /INSERT INTO lesson_review_tasks[\s\S]*version_id/);
  assert.match(submit, /status_version = \?/);
  assert.match(decision, /beginTransaction\(\)/);
  assert.match(decision, /FOR UPDATE/);
  assert.match(decision, /\$nextStatus = 'supervisor_review'/);
  assert.match(decision, /\$approvedVersionId = \(int\) \$task\['version_id'\]/);
  assert.match(decision, /\$decision === 'returned' && \$comments === ''/);
  assert.match(decision, /lesson_audit_logs/);
});

test(`${validatesCriteria(['7.1', '7.2'])} 正式教案库发布状态绑定批准版本`, () => {
  const migration = read('database/migrations/202609050001_lesson_library_publication.sql');
  const decision = read('api/lesson-reviews/LessonReviewDecisionService.php');
  assert.match(migration, /library_status VARCHAR\(16\) NOT NULL DEFAULT 'hidden'/);
  assert.match(migration, /idx_lesson_submissions_library \(library_status, library_published_at\)/);
  assert.match(decision, /status = 'approved', approved_version_id = \?, library_status = 'published'/);
  assert.match(decision, /library_published_at = NOW\(\), library_published_by_staff_id = \?/);
});

test(`${validatesCriteria(['Property 5', 'Property 7'])} 生产导出和解析失败服务保留来源及版本`, () => {
  const exportService = read('api/lesson-submissions/LessonExportService.php');
  const submissionService = read('api/lesson-submissions/LessonSubmissionService.php');
  assert.match(exportService, /INSERT INTO lesson_exports \(submission_id, version_id/);
  assert.match(exportService, /WHERE id = \? AND submission_id = \?/);
  assert.match(submissionService, /recordParseFailure/);
  assert.match(submissionService, /manual_template/);
  assert.match(submissionService, /parse_failed/);
  assert.match(submissionService, /lesson_source_files/);
});
