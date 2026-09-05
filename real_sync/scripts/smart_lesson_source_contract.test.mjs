import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

function generate(payload, extraEnv = {}) {
  const php = String.raw`session_start(); $_SESSION['wp_user_id']=1; $_SERVER['REQUEST_METHOD']='POST'; include 'smart-lessons-api.php';`;
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', php], {
    cwd: root,
    encoding: 'utf8',
    input: JSON.stringify(payload),
    env: {
      ...process.env,
      DB_PASSWORD: 'test-only-placeholder',
      JWT_SECRET: 'test-only-placeholder',
      SMART_LESSONS_DISABLE_KNOWLEDGE: '1',
      ...extraEnv,
    },
  });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Warning|Notice|Deprecated|Fatal error/);
  return JSON.parse(result.stdout);
}

function assertCompletePlan(plan, expectedWeeks) {
  assert.equal(typeof plan.monthlySummary, 'string');
  assert.ok(plan.monthlySummary.length > 0);
  for (const field of ['aceFocus', 'materials', 'segments', 'coachTips', 'parentTips']) {
    assert.ok(Array.isArray(plan[field]), `${field} 应为数组`);
    assert.ok(plan[field].length > 0, `${field} 应包含内容`);
  }
  assert.equal(plan.weeks.length, expectedWeeks);
  for (const week of plan.weeks) {
    for (const field of ['title', 'goal', 'warmup', 'core', 'game', 'coachTip', 'parentTip']) {
      assert.equal(typeof week[field], 'string');
      assert.ok(week[field].trim().length > 0, `周计划 ${field} 应包含内容`);
    }
  }
  assert.equal(plan.segments.length, 4);
}

test('静态教案在知识库降级时保持月计划、周计划和单节课可用', () => {
  const plan = generate({ cycleWeeks: 2, trainingFocus: '跳箱和平衡', ageRange: '6-8 岁' });
  assertCompletePlan(plan, 2);
  assert.equal(plan.libraryStatus.knowledgeAvailable, false);
  assert.equal(plan.libraryStatus.staticLessonsAvailable, true);
  assert.equal(plan.libraryStatus.defaultTemplateUsed, false);
  assert.ok(plan.sourceReferences.some(({ sourceType }) => sourceType === 'static_lesson'));
});

test('所有资料源降级时完整 ACE 默认模板仍生成八周计划', () => {
  const plan = generate(
    { cycleWeeks: 8, trainingFocus: '协调与规则' },
    { SMART_LESSONS_DISABLE_STATIC: '1' },
  );
  assertCompletePlan(plan, 8);
  assert.equal(plan.libraryStatus.defaultTemplateUsed, true);
  assert.deepEqual(plan.libraryStatus.degradedSources, ['knowledge_base_disabled', 'static_lessons_unavailable']);
});

test('异常字段类型回退为稳定默认输入且不产生 PHP 警告', () => {
  const plan = generate({
    className: [],
    ageRange: {},
    trainingFocus: ['跳箱'],
    cycleWeeks: [],
  }, { SMART_LESSONS_DISABLE_STATIC: '1' });
  assertCompletePlan(plan, 4);
  assert.match(plan.segments[1], /协调、平衡、核心控制/);
});

test('知识库查询使用已发布边界和当前版本内容', () => {
  const source = read('smart-lessons-api.php');
  assert.doesNotMatch(source, /资料库文件不存在|资料库解析失败/);
  assert.doesNotMatch(source, /foreach \(\$wordPressSources as/);
  assert.match(source, /EmployeeKnowledgeVisibilityQuery::fromCurrentVersion\(\)/);
  assert.doesNotMatch(source, /JOIN knowledge_item_versions kv ON/);
  assert.match(source, /k\.current_version_id IS NOT NULL/);
  assert.match(source, /'action', 'game', 'safety', 'training_plan'/);
  assert.match(source, /COALESCE\(NULLIF\(kv\.content, ''\), k\.content\)/);
  assert.match(source, /sourceType' => 'knowledge_card'/);
});

test('静态教案来源限制在 manifest 登记的安全文件路径', () => {
  const source = read('smart-lessons-api.php');
  assert.match(source, /lessons\/manifest\.json/);
  assert.match(source, /basename\(\$filename\) !== \$filename/);
  assert.match(source, /str_starts_with\(\$path, \$lessonsDir/);

  const manifest = JSON.parse(read('lessons/manifest.json'));
  assert.ok(manifest.length > 0);
  for (const { filename } of manifest) {
    assert.equal(filename, filename.split('/').at(-1));
  }
});

test('智能教案页面校验响应结构、显示真实来源并控制请求超时', () => {
  const source = read('smart-lessons.html');
  assert.match(source, /AbortController/);
  assert.match(source, /isValidPlanResult/);
  assert.match(source, /generationSourceSummary/);
  assert.match(source, /knowledgeAvailable/);
  assert.match(source, /staticLessonsAvailable/);
  assert.match(source, /登录状态已失效/);
  assert.match(source, /生成教案超时/);
  assert.doesNotMatch(source, /当前结果基于已同步的 V4 与 09 知识库口径/);
});
