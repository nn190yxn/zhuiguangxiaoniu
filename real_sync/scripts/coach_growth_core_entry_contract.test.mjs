import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');

const dashboard = read('admin/dashboard.html');
const fitness = read('fitness-assessment-app.html');
const fitnessAdmin = read('admin/fitness-reports.html');
const recruitmentRequirements = read('admin/recruitment-requirements.html');
const recruitmentRules = read('admin/recruitment-rules.html');
const recruitmentResumes = read('admin/recruitment-resumes.html');
const submission = read('lesson-submission.html');
const review = read('lesson-review.html');
const submissionScript = read('js/lesson-submission.js');
const reviewScript = read('js/lesson-review.js');
const contextMigration = read('database/migrations/202609050002_lesson_context_fields.sql');

test('总部后台首页暴露三项核心业务入口', () => {
  for (const entry of [
    '/admin/fitness-reports.html',
    '/admin/recruitment-requirements.html',
    '/lesson-review.html',
  ]) assert.match(dashboard, new RegExp(`href="${entry.replaceAll('.', '\\.')}`));
});

test('体测入口连接员工记录和后台统计链路', () => {
  assert.match(fitness, /fetch\('\/api\/records\//);
  assert.match(fitness, /fetch\('\/api\/ai-services\.php'/);
  assert.match(fitnessAdmin, /api\/admin\/fitness-reports\.php/);
  assert.match(fitnessAdmin, /window\.authHeaders/);
});

test('招聘入口覆盖需求、规则、上传、处理和候选人复核', () => {
  for (const endpoint of [
    'requirements.php',
    'rules.php',
    'batches.php',
    'upload.php',
    'process.php',
    'candidates.php',
    'candidate-detail.php',
  ]) assert.match(`${recruitmentRequirements}\n${recruitmentRules}\n${recruitmentResumes}`, new RegExp(endpoint.replace('.', '\\.')));
  assert.match(recruitmentRequirements, /window\.requirePageAuth\(/);
  assert.match(recruitmentRules, /window\.requirePageAuth\(/);
  assert.match(recruitmentResumes, /window\.requirePageAuth\(/);
  assert.match(recruitmentResumes, /showToast\(error\.message,true\)/);
});

test('教案入口覆盖上传、建议、重传和双阶段审核', () => {
  for (const endpoint of [
    'lesson-submissions/create.php',
    'lesson-submissions/submit.php',
    'lesson-submissions/optimize.php',
    'lesson-reviews/list.php',
    'lesson-reviews/decision.php',
  ]) assert.match(`${submission}\n${review}\n${submissionScript}\n${reviewScript}`, new RegExp(endpoint.replaceAll('.', '\\.')));
  assert.match(submission, /sourceFile/);
  assert.match(submission, /suggestions/);
  assert.match(submission, /exportDocxButton/);
  assert.match(submission, /id="submitButton"/);
  assert.match(submissionScript, /lesson-submissions\/submit\.php/);
  assert.match(submissionScript, /status_version/);
  assert.match(submissionScript, /请先保存当前修改，再提交审核/);
  assert.match(review, /store_review/);
  assert.match(review, /supervisor_review/);
  assert.match(review, /退回时必须说明需要修改的内容/);
});

test('教案创建保存统一年龄段和班级阶段', () => {
  assert.match(contextMigration, /ADD COLUMN age_range/);
  assert.match(contextMigration, /ADD COLUMN class_stage/);
  assert.match(submission, /id="createAge"/);
  assert.match(submission, /id="createStage"/);
  assert.match(submissionScript, /age_range: \$\('createAge'\)\.value/);
  assert.match(submissionScript, /class_stage: \$\('createStage'\)\.value/);
  assert.match(read('api/lesson-submissions/LessonSubmissionService.php'), /AGE_RANGES/);
  assert.match(read('api/lesson-submissions/LessonSubmissionService.php'), /CLASS_STAGES/);
});

test('三项核心页面的内联脚本保持可解析', () => {
  for (const html of [fitnessAdmin, recruitmentRequirements, recruitmentRules, recruitmentResumes, submission, review]) {
    for (const match of html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
      if (match[1].trim()) new Script(match[1]);
    }
  }
});
