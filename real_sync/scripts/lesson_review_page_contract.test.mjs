import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('审核页面提供两级任务筛选、证据、历史和决策表单', () => {
  const html = read('lesson-review.html');
  for (const id of ['taskList', 'statusFilter', 'stageFilter', 'lessonContent', 'evidence', 'history', 'comments', 'approveButton', 'returnButton']) assert.match(html, new RegExp(`id="${id}"`));
  assert.match(html, /store_review/);
  assert.match(html, /supervisor_review/);
  assert.match(html, /@media\(max-width:640px\)/);
});

test('审核页面串联列表、详情、通过和退回接口', () => {
  const script = read('js/lesson-review.js');
  assert.match(script, /lesson-reviews\/list\.php/);
  assert.match(script, /lesson-reviews\/decision\.php/);
  assert.match(script, /review_history/);
  assert.match(script, /decision === 'returned' && !comments/);
  assert.match(script, /next_review_task_id/);
});

test('审核详情返回完整审核历史，员工首页提供入口', () => {
  const service = read('api/lesson-reviews/LessonReviewQueryService.php');
  assert.match(service, /review_history/);
  assert.match(service, /reviewer_name/);
  assert.match(read('internal.html'), /href="\/lesson-review\.html"/);
});
