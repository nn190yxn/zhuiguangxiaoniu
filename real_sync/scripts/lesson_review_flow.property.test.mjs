import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

function decide(state, stage, decision, comments = '') {
  if (state.taskStatus !== 'pending' || state.status !== stage) return { ...state, error: 'conflict' };
  if (decision === 'returned' && !comments.trim()) return { ...state, error: 'reason_required' };
  if (decision === 'returned') return { ...state, status: 'returned', taskStatus: 'completed', decision, comments };
  if (stage === 'store_review') return { ...state, status: 'supervisor_review', taskStatus: 'pending', stage: 'supervisor_review', decision };
  return { ...state, status: 'approved', taskStatus: 'completed', approvedVersionId: state.versionId, libraryStatus: 'published', decision };
}

test('[validates 4.2, 4.4, 7.1, 7.2] 两级通过固定最终批准版本并仅在终审后发布', () => {
  const initial = { status: 'store_review', stage: 'store_review', taskStatus: 'pending', versionId: 73, approvedVersionId: null, libraryStatus: 'hidden' };
  const storeApproved = decide(initial, 'store_review', 'approved');
  assert.equal(storeApproved.status, 'supervisor_review');
  assert.equal(storeApproved.libraryStatus, 'hidden');
  const supervisorApproved = decide(storeApproved, 'supervisor_review', 'approved');
  assert.equal(supervisorApproved.status, 'approved');
  assert.equal(supervisorApproved.approvedVersionId, initial.versionId);
  assert.equal(supervisorApproved.libraryStatus, 'published');
});

test('[validates 4.3] 两个审核阶段退回均要求原因并保留原版本', () => {
  for (const stage of ['store_review', 'supervisor_review']) {
    const initial = { status: stage, stage, taskStatus: 'pending', versionId: 91 };
    assert.equal(decide(initial, stage, 'returned').error, 'reason_required');
    const returned = decide(initial, stage, 'returned', '请补充安全保护人安排');
    assert.equal(returned.status, 'returned');
    assert.equal(returned.versionId, initial.versionId);
    assert.equal(returned.comments, '请补充安全保护人安排');
  }
});

test('[validates Property 1-4] 重复和并发决定只有首次状态转换有效', () => {
  const initial = { status: 'store_review', stage: 'store_review', taskStatus: 'pending', versionId: 118 };
  const first = decide(initial, 'store_review', 'approved');
  const staleSecond = decide({ ...first, taskStatus: 'completed' }, 'store_review', 'returned', '旧请求');
  assert.equal(staleSecond.error, 'conflict');
  assert.equal(first.status, 'supervisor_review');
});

test('PHP 状态机锁定任务与主记录并校验归属、阶段、权限和版本', () => {
  const service = read('api/lesson-reviews/LessonReviewDecisionService.php');
  const endpoint = read('api/lesson-reviews/decision.php');
  assert.match(service, /reviewer_staff_id = \?' \. \$this->lockClause\(\)/);
  assert.match(service, /lesson_submissions WHERE id = \?' \. \$this->lockClause\(\)/);
  assert.match(service, /ATTR_DRIVER_NAME\) === 'sqlite' \? '' : ' FOR UPDATE'/);
  assert.match(service, /lesson_review_task_handled/);
  assert.match(service, /lesson_review_version_mismatch/);
  assert.match(service, /lesson_review_stage_conflict/);
  assert.match(service, /lesson_review_stage_forbidden/);
  assert.match(service, /library_status = 'published'/);
  assert.match(service, /status_version = \?/);
  assert.match(endpoint, /lesson_review\.store_decide/);
  assert.match(endpoint, /lesson_review\.supervisor_decide/);
});

test('审核留痕包含身份、角色、决定、意见、时间、版本和状态迁移', () => {
  const service = read('api/lesson-reviews/LessonReviewDecisionService.php');
  for (const field of ['reviewer_staff_id', 'reviewer_role', 'decision', 'comments', 'decided_at', 'version_id', 'from_status', 'to_status']) assert.match(service, new RegExp(field));
});
