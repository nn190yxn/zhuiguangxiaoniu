import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('解析端点使用统一认证并将 Office 文件生成结构化版本', () => {
  const endpoint = read('api/lesson-submissions/parse.php');
  const service = read('api/lesson-submissions/LessonSubmissionService.php');
  assert.match(endpoint, /platformApiAuthContext\(\)/);
  assert.match(endpoint, /requirePermission\('lesson_submission\.create'\)/);
  assert.match(endpoint, /parseUploadedFile/);
  assert.match(endpoint, /PlatformApiCompatibility::withMetadata/);
  assert.match(service, /resolveForRead/);
  assert.match(service, /new LessonWorkbookParser/);
  assert.match(service, /new LessonWordParser/);
  assert.match(service, /version_type, created_by.*'parsed'/s);
  assert.match(service, /recordParseFailure/);
  assert.match(service, /status = 'editable'/);
});

test('编辑页覆盖原文件、ACE 字段、缺项和知识卡建议', () => {
  const html = read('lesson-submission.html');
  for (const field of [
    'metadata.store_name', 'metadata.author_name', 'metadata.course_line',
    'metadata.class_level', 'metadata.lesson_date', 'metadata.title',
    'objectives.athletic', 'objectives.cognitive', 'objectives.engagement',
    'safety.physical', 'safety.psychological', 'assistant_responsibilities',
    'reflection.athletic', 'reflection.cognitive', 'reflection.engagement',
  ]) assert.match(html, new RegExp(`data-path="${field.replace('.', '\\.')}"`));
  assert.match(html, /id="sourceFiles"/);
  assert.match(html, /id="findings"/);
  assert.match(html, /id="suggestions"/);
  assert.match(html, /accept="\.xlsx,\.xls,\.docx,\.doc"/);
  assert.doesNotMatch(html, /\.pdf/);
  assert.match(html, /@media\(max-width:640px\)/);
});

test('编辑器串联创建上传解析保存检查和优化接口', () => {
  const script = read('js/lesson-submission.js');
  for (const endpoint of ['create.php', 'upload.php', 'parse.php', 'detail.php', 'manual-entry.php', 'draft.php', 'validate.php', 'optimize.php']) {
    assert.match(script, new RegExp(endpoint.replace('.', '\\.')));
  }
  assert.match(script, /status_version/);
  assert.match(script, /editableStatuses/);
  assert.match(script, /textContent = String/);
  assert.match(script, /state\.dirty/);
  assert.doesNotMatch(script, /acceptSuggestion|ignoreSuggestion|versionCompare/);
});

test('建议决定接口限制当前版本并记录采纳或忽略留痕', () => {
  const endpoint = read('api/lesson-submissions/suggestion-decision.php');
  const service = read('api/lesson-submissions/LessonSuggestionService.php');
  assert.match(endpoint, /requirePermission\('lesson_submission\.optimize'\)/);
  assert.match(endpoint, /lesson_submission\.suggestion_decision/);
  assert.match(service, /\['accepted', 'ignored'\]/);
  assert.match(service, /decided_by/);
  assert.match(service, /decided_at/);
  assert.match(service, /version_id.*current_version_id/s);
  assert.match(service, /version_type.*'draft'/s);
  assert.match(service, /suggestion_.*\$decision/s);
});

test('编辑页支持建议决定并基于版本快照展示字段差异', () => {
  const html = read('lesson-submission.html');
  const script = read('js/lesson-submission.js');
  assert.match(html, /id="compareFrom"/);
  assert.match(html, /id="compareTo"/);
  assert.match(html, /id="versionDiff"/);
  assert.match(script, /suggestion-decision\.php/);
  assert.match(script, /data-decision="accepted"/);
  assert.match(script, /data-decision="ignored"/);
  assert.match(script, /renderDiff/);
  assert.match(script, /setAt/);
  assert.match(script, /status_version/);
});

test('员工首页、教练中心和智能生成页均提供上传审核入口', () => {
  for (const file of ['internal.html', 'coach.html', 'smart-lessons.html']) {
    assert.match(read(file), /href="\/lesson-submission\.html"/);
  }
});
