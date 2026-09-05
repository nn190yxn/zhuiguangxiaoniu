import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('ACE 规则检查识别必填项和可定位修复字段', () => {
  const php = String.raw`require 'api/lesson-submissions/LessonAceRuleChecker.php'; echo json_encode((new LessonAceRuleChecker())->check(['metadata'=>['title'=>'教案'], 'objectives'=>['athletic'=>'动作'], 'phases'=>[['name'=>'热身','duration_minutes'=>10,'activity'=>'跑跳']], 'safety'=>['physical'=>'保护'], 'equipment'=>[['value'=>'软垫']], 'progressions'=>['降阶'], 'assistant_responsibilities'=>'保护站位', 'reflection'=>['athletic'=>'观察','cognitive'=>'指令','engagement'=>'参与']]), JSON_UNESCAPED_UNICODE);`;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const checked = JSON.parse(result.stdout);
  assert.equal(checked.valid, false);
  assert.ok(checked.findings.some((finding) => finding.field_path === 'metadata.store_name' && finding.severity === 'error'));
  assert.ok(checked.findings.some((finding) => finding.field_path === 'objectives.cognitive'));
  assert.equal(checked.error_count, checked.findings.filter((finding) => finding.severity === 'error').length);
});

test('ACE 完整教案通过规则检查并统计课程时长', () => {
  const php = String.raw`require 'api/lesson-submissions/LessonAceRuleChecker.php'; echo json_encode((new LessonAceRuleChecker())->check(['metadata'=>['store_name'=>'门店','author_name'=>'教练','course_line'=>'体适能','class_level'=>'L2','lesson_date'=>'2026-09-03','title'=>'教案','course_duration_minutes'=>60], 'objectives'=>['athletic'=>'跳跃','cognitive'=>'理解规则','engagement'=>'主动参与'], 'phases'=>[['name'=>'热身','duration_minutes'=>10,'activity'=>'跑跳'],['name'=>'主体','duration_minutes'=>40,'activity'=>'循环训练'],['name'=>'放松','duration_minutes'=>10,'activity'=>'拉伸']], 'safety'=>['physical'=>'保护站位','psychological'=>'鼓励'], 'equipment'=>[['value'=>'软垫']], 'progressions'=>['升阶和降阶'], 'assistant_responsibilities'=>'安全站位', 'reflection'=>['athletic'=>'稳定','cognitive'=>'理解','engagement'=>'投入']]), JSON_UNESCAPED_UNICODE);`;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const checked = JSON.parse(result.stdout);
  assert.equal(checked.valid, true);
  assert.equal(checked.error_count, 0);
  assert.equal(checked.warning_count, 0);
  assert.equal(checked.total_phase_minutes, 60);
});

test('规则检查识别时长超限和高风险动作保护缺失', () => {
  const php = String.raw`require 'api/lesson-submissions/LessonAceRuleChecker.php'; echo json_encode((new LessonAceRuleChecker())->check(['metadata'=>['store_name'=>'门店','author_name'=>'教练','course_line'=>'跑酷','class_level'=>'L2','lesson_date'=>'2026-09-03','title'=>'跳箱','course_duration_minutes'=>45], 'objectives'=>['athletic'=>'跳箱','cognitive'=>'理解规则','engagement'=>'主动参与'], 'phases'=>[['name'=>'主体','duration_minutes'=>50,'activity'=>'跳箱训练']], 'safety'=>['physical'=>'注意安全','psychological'=>'允许先观察'], 'equipment'=>['跳箱'], 'progressions'=>['低箱到高箱'], 'assistant_responsibilities'=>'管理器材', 'reflection'=>['athletic'=>'稳定','cognitive'=>'理解','engagement'=>'投入']]), JSON_UNESCAPED_UNICODE);`;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const checked = JSON.parse(result.stdout);
  assert.ok(checked.findings.some((finding) => finding.code === 'lesson_duration_exceeded'));
  assert.ok(checked.findings.some((finding) => finding.code === 'high_risk_protection_required'));
  assert.ok(checked.findings.every((finding) => finding.priority && finding.basis));
});

test('规则检查安全处理空数组字段且不产生 PHP 警告', () => {
  const php = String.raw`require 'api/lesson-submissions/LessonAceRuleChecker.php'; echo json_encode((new LessonAceRuleChecker())->check(['phases'=>[['name'=>[],'activity'=>[]]],'equipment'=>[[]],'progressions'=>[[]]]), JSON_UNESCAPED_UNICODE);`;
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Warning|Array to string conversion/);
  const checked = JSON.parse(result.stdout);
  assert.ok(checked.findings.some((finding) => finding.field_path === 'phases'));
  assert.ok(checked.findings.some((finding) => finding.field_path === 'progressions'));
});

test('规则检查接口绑定作者可访问的当前版本并复用统一响应契约', () => {
  const source = read('api/lesson-submissions/validate.php');
  assert.match(source, /platformApiAuthContext\(\)/);
  assert.match(source, /requirePermission\('lesson_submission\.create'\)/);
  assert.match(source, /LessonDraftService\(getDB\(\)\)/);
  assert.match(source, /current_version/);
  assert.match(source, /submission_id/);
  assert.match(source, /content_source/);
  assert.match(source, /LessonAceRuleChecker/);
  assert.match(source, /PlatformApiCompatibility::withMetadata\(/);
  assert.match(source, /platformApiResponse\(/);
});

test('小程序与云代理矩阵登记 ACE 规则检查接口', () => {
  for (const path of ['mini-program/business-domain-matrix.json', 'cloudfunctions/api-proxy/business-domain-matrix.json']) {
    const matrix = JSON.parse(read(path));
    const lessonReview = matrix.migration_domains.find(({ id }) => id === 'lesson_review');
    assert.ok(lessonReview.endpoints.some(({ method, path: endpointPath }) => method === 'POST' && endpointPath === '/lesson-submissions/validate.php'));
  }
});
