import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

function match(content, candidates) {
  const php = String.raw`require 'api/lesson-submissions/LessonKnowledgeMatcher.php'; $input=json_decode(stream_get_contents(STDIN), true); echo json_encode((new LessonKnowledgeMatcher())->match($input['content'], $input['candidates']), JSON_UNESCAPED_UNICODE);`;
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', php], {
    cwd: root,
    encoding: 'utf8',
    input: JSON.stringify({ content, candidates }),
  });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Warning|Notice|Deprecated/);
  return JSON.parse(result.stdout);
}

const lesson = {
  metadata: {
    course_line: '跑酷',
    class_level: '6-8岁',
  },
  objectives: {
    athletic: '提升跳箱和越障能力',
    cognitive: '理解闯关规则',
    engagement: '主动参与挑战',
  },
  phases: [{
    name: '技能教学',
    activity: '跳箱越障与障碍闯关游戏',
    duration_minutes: 35,
  }],
  safety: { physical: '教练保护站位', psychological: '允许先观察' },
  equipment: ['跳箱'],
  progressions: [],
};

function candidate(overrides) {
  return {
    id: 1,
    knowledge_version_id: 101,
    item_code: 'ACTION-0001',
    title: '跳箱基础动作',
    summary: '跳箱动作步骤',
    content: '降阶：降低箱体。进阶：增加助跑距离。',
    content_type: 'action',
    risk_level: '高',
    subject: '跳箱',
    age_group: '6-8岁',
    training_type: '跑酷',
    tags: JSON.stringify(['跳箱', '越障']),
    status: 1,
    publication_status: 'published',
    source_metadata_json: JSON.stringify({
      applicable_ages: ['6-8岁'],
      primary_age: '6-8岁',
      setting: {
        class_type: ['跑酷'],
        equipment: ['跳箱', '软垫'],
        lesson_phase: ['技能教学'],
      },
    }),
    ...overrides,
  };
}

test('知识卡匹配按课程、年龄、项目、阶段、器材和风险生成可追溯建议', () => {
  const suggestions = match(lesson, [
    candidate({}),
    candidate({ id: 2, item_code: 'GAME-0001', title: '障碍闯关游戏', content_type: 'game', subject: '越障', tags: JSON.stringify(['闯关', '越障']), risk_level: '中' }),
    candidate({ id: 3, item_code: 'SAFETY-0001', title: '跳箱保护站位', content_type: 'safety', subject: '跳箱', tags: JSON.stringify(['跳箱', '保护']), content: '保护站位与并发上限', risk_level: 'high' }),
  ]);

  const types = new Set(suggestions.map(({ suggestion_type: type }) => type));
  for (const type of ['knowledge_action', 'knowledge_game', 'knowledge_safety', 'knowledge_equipment', 'knowledge_progression']) {
    assert.ok(types.has(type), `缺少建议类型 ${type}`);
  }
  assert.ok(suggestions.some(({ field_path: path }) => path === 'phases.0.activity'));
  assert.ok(suggestions.every(({ reason, source_type: sourceType, knowledge_item_id: itemId, knowledge_version_id: versionId, knowledge_item_code: itemCode }) => reason && sourceType === 'knowledge_card' && itemId > 0 && versionId > 0 && itemCode));
  assert.ok(suggestions.some(({ matched_dimensions: dimensions }) => dimensions.includes('年龄') && dimensions.includes('课程线') && dimensions.includes('课堂阶段')));
});

test('匹配器只接受已发布且启用的动作、游戏和安全知识卡', () => {
  const suggestions = match(lesson, [
    candidate({ id: 11, item_code: 'ACTION-PUBLISHED' }),
    candidate({ id: 12, item_code: 'ACTION-ISOLATED', publication_status: 'isolated' }),
    candidate({ id: 13, item_code: 'ACTION-DISABLED', status: 0 }),
    candidate({ id: 14, item_code: 'PLAN-PUBLISHED', content_type: 'training_plan' }),
  ]);
  const ids = new Set(suggestions.map(({ knowledge_item_id: id }) => id));
  assert.ok(ids.has(11));
  assert.equal(ids.has(12), false);
  assert.equal(ids.has(13), false);
  assert.equal(ids.has(14), false);
});

test('同一知识卡版本、字段和建议类型不会重复推荐', () => {
  const duplicated = candidate({ id: 21, item_code: 'ACTION-DUPLICATE' });
  const suggestions = match(lesson, [duplicated, { ...duplicated }]);
  const keys = suggestions.map(({ knowledge_item_id: id, knowledge_version_id: versionId, field_path: path, suggestion_type: type }) => `${id}|${versionId}|${path}|${type}`);
  assert.equal(new Set(keys).size, keys.length);
});

test('数据库候选查询和建议写入保持发布边界及版本绑定', () => {
  const source = read('api/lesson-submissions/LessonKnowledgeMatcher.php');
  assert.match(source, /EmployeeKnowledgeVisibilityQuery::fromCurrentVersion\(\)/);
  assert.doesNotMatch(source, /JOIN knowledge_item_versions kv ON/);
  assert.match(source, /COALESCE\(NULLIF\(kv\.content_type, ''\), k\.content_type\) IN \('action', 'game', 'safety'\)/);
  assert.match(source, /raw_frontmatter_json/);
  assert.match(source, /INSERT INTO lesson_suggestions/);
  assert.match(source, /submission_id, version_id, suggestion_type/);
  assert.match(source, /knowledge_item_id, knowledge_version_id/);
  assert.match(source, /source_type = 'knowledge_card'/);
  assert.match(source, /current_version_id.*version_id/s);
  assert.doesNotMatch(source, /DELETE FROM lesson_suggestions/);
});

test('优化接口使用专用权限、统一响应和审计日志', () => {
  const endpoint = read('api/lesson-submissions/optimize.php');
  assert.match(endpoint, /platformApiAuthContext\(\)/);
  assert.match(endpoint, /requirePermission\('lesson_submission\.optimize'\)/);
  assert.match(endpoint, /LessonKnowledgeMatcher\(getDB\(\)\)/);
  assert.match(endpoint, /PlatformApiCompatibility::withMetadata\(/);
  assert.match(endpoint, /lesson_submission\.optimize/);
  assert.match(endpoint, /platformApiResponse\(/);

  const service = read('api/lesson-submissions/LessonKnowledgeMatcher.php');
  assert.match(service, /FOR UPDATE/);
  assert.match(service, /lesson_submission_conflict/);
  assert.match(service, /knowledge_optimize/);
});

test('小程序与云代理矩阵登记知识卡优化接口', () => {
  for (const path of ['mini-program/business-domain-matrix.json', 'cloudfunctions/api-proxy/business-domain-matrix.json']) {
    const matrix = JSON.parse(read(path));
    const lessonReview = matrix.migration_domains.find(({ id }) => id === 'lesson_review');
    const endpoint = lessonReview.endpoints.find(({ method, path: endpointPath }) => method === 'POST' && endpointPath === '/lesson-submissions/optimize.php');
    assert.ok(endpoint);
    assert.equal(endpoint.auth, true);
    assert.equal(endpoint.side_effect, true);
    assert.equal(endpoint.idempotency, true);
  }
});

test('教案详情返回带知识卡编号和标题的版本建议', () => {
  const source = read('api/lesson-submissions/LessonDraftService.php');
  assert.match(source, /lesson_suggestions/);
  assert.match(source, /knowledge_item_code/);
  assert.match(source, /knowledge_item_title/);
  assert.match(source, /s\.knowledge_version_id/);
  assert.match(source, /kv\.version_id = s\.knowledge_version_id/);
  assert.match(source, /s\.submission_id = \?/);
});
