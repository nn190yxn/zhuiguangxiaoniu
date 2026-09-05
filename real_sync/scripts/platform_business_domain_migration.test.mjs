import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

const endpoints = [
  'api/auth/me.php',
  'api/admin/organization/tree.php',
  'api/learning/lesson.php',
  'api/knowledge/list.php',
  'api/exam/save.php',
  'api/policy/notify.php',
];

function assertOrdered(source, patterns) {
  let previous = -1;
  for (const pattern of patterns) {
    const match = source.match(pattern);
    assert.ok(match, `缺少契约：${pattern}`);
    const index = source.indexOf(match[0]);
    assert.ok(index > previous, `契约顺序错误：${pattern}`);
    previous = index;
  }
}

test('十四域迁移注册表保存稳定功能 ID、代表入口和历史消费者', { skip: !hasPhp }, () => {
  const php = String.raw`
    require 'api/platform/BusinessDomainRegistry.php';
    echo json_encode(PlatformBusinessDomainRegistry::all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);

  const registry = JSON.parse(result.stdout);
  assert.deepEqual(Object.keys(registry), [
    'identity',
    'organization',
    'workload',
    'recruitment',
    'learning',
    'knowledge',
    'exam',
    'policy',
    'drill',
    'skill',
    'reminder',
    'wecom',
    'content',
    'lesson_review',
  ]);
  assert.deepEqual(registry.identity.function_ids, ['IAM-001', 'IAM-004']);
  assert.deepEqual(registry.organization.function_ids, ['IAM-009']);
  assert.deepEqual(registry.workload.function_ids, ['BIZ-001', 'BIZ-002', 'BIZ-003', 'BIZ-004', 'BIZ-005']);
  assert.deepEqual(registry.recruitment.function_ids, ['BIZ-010', 'BIZ-011', 'BIZ-012', 'BIZ-013']);
  assert.deepEqual(registry.learning.function_ids, ['BIZ-014']);
  assert.deepEqual(registry.knowledge.function_ids, ['BIZ-015']);
  assert.deepEqual(registry.exam.function_ids, ['BIZ-016']);
  assert.deepEqual(registry.policy.function_ids, ['BIZ-018', 'MSG-004']);
  assert.deepEqual(registry.drill.function_ids, ['BIZ-006']);
  assert.deepEqual(registry.skill.function_ids, ['BIZ-009']);
  assert.deepEqual(registry.reminder.function_ids, ['MSG-003']);
  assert.deepEqual(registry.wecom.function_ids, ['MSG-001']);
  assert.deepEqual(registry.content.function_ids, ['BIZ-019', 'BIZ-020', 'BIZ-021', 'BIZ-022']);
  assert.deepEqual(registry.lesson_review.function_ids, ['BIZ-023', 'BIZ-024', 'BIZ-025', 'BIZ-026']);
  assert.deepEqual(registry.lesson_review.legacy_consumers, [
    'smart-lessons.html',
    'smart-lessons-api.php',
    'lesson-library.html',
    'js/lesson-library.js',
  ]);
  for (const capability of [
    'approved_version_publication',
    'formal_library_read',
    'canonical_lesson_route',
  ]) {
    assert.ok(registry.lesson_review.capabilities.includes(capability), capability);
  }

  for (const entry of Object.values(registry)) {
    assert.ok(entry.endpoint.startsWith('api/'));
    assert.ok(entry.endpoint_version.match(/^\d+\.\d+\.\d+$/));
    assert.ok(entry.legacy_consumers.length > 0);
    assert.equal(existsSync(new URL(`../${entry.endpoint}`, import.meta.url)), true, entry.endpoint);
    for (const consumer of entry.legacy_consumers) {
      assert.equal(existsSync(new URL(`../${consumer}`, import.meta.url)), true, consumer);
    }
  }
});

test('六域代表入口统一通过 Kernel 兼容控制器返回', () => {
  for (const endpoint of endpoints) {
    const source = read(endpoint);
    assert.match(source, /kernel\/bootstrap\.php/, endpoint);
    assert.match(source, /platformApiContext\(/, endpoint);
    assert.match(source, /platformApiInstallExceptionHandler\(/, endpoint);
    assert.match(source, /platformApiAuthContext\(/, endpoint);
    assert.match(source, /PlatformApiCompatibility::withMetadata\(/, endpoint);
    assert.match(source, /PlatformApiLogger/, endpoint);
    assert.match(source, /platformApiResponse\(/, endpoint);
    assert.doesNotMatch(source, /\b(?:jsonResponse|jsonSuccess|jsonError|json_response)\s*\(/, endpoint);
  }
});

test('身份和组织入口先认证授权，再调用稳定服务并审计响应', () => {
  const identity = read('api/auth/me.php');
  assertOrdered(identity, [
    /platformApiAuthContext\(/,
    /requireAuthenticated\(/,
    /new IdentityContextService/,
    /->log\(/,
    /platformApiResponse\(/,
  ]);

  const organization = read('api/admin/organization/tree.php');
  assertOrdered(organization, [
    /platformApiAuthContext\(/,
    /requirePermission\('organization\.manage'\)/,
    /new OrganizationService/,
    /->log\(/,
    /platformApiResponse\(/,
  ]);
});

test('学习入口使用事务和用户锁保证进度与首次奖励原子提交', () => {
  const controller = read('api/learning/lesson.php');
  const service = read('api/learning/LearningLessonService.php');
  assert.match(controller, /LearningLessonService/);
  assert.match(service, /beginTransaction\(/);
  assert.match(service, /SELECT ID FROM wp_users WHERE ID = \? FOR UPDATE/);
  assert.match(service, /SELECT COUNT\(\*\) FROM course_lessons WHERE course_id = \?/);
  assert.match(service, /->execute\(\[\$courseId\]\)/);
  assert.match(service, /SELECT COUNT\(\*\) FROM user_lesson_progress/);
  assert.match(service, /\$wasCompleted/);
  assert.match(service, /!\$wasCompleted/);
  const completePath = service.slice(service.indexOf('public function complete'), service.indexOf('private function loadLesson'));
  assertOrdered(completePath, [/beginTransaction\(/, /loadLesson\(\$userId, \$lessonId, true\)/, /\$version =/]);
  const writePath = completePath.slice(completePath.indexOf('$version ='));
  assertOrdered(writePath, [/updateCourseProgress\(/, /commit\(/]);
});

test('知识员工端只允许已登录且已发布内容，并拒绝客户端身份覆盖', () => {
  const controller = read('api/knowledge/list.php');
  const service = read('api/knowledge/KnowledgeListService.php');
  const visibility = read('api/knowledge/EmployeeKnowledgeVisibilityQuery.php');
  const categories = read('api/knowledge/categories.php');
  const detail = read('api/knowledge/detail.php');
  const search = read('api/search/search-service.php');
  assert.match(controller, /requireAuthenticated\(\)/);
  assert.match(controller, /KnowledgeListService/);
  assert.doesNotMatch(controller, /\$_GET\[['"](?:role|stage)['"]\]/);
  assert.doesNotMatch(service, /\$_GET\[['"](?:role|stage)['"]\]/);
  assert.match(service, /EmployeeKnowledgeVisibilityQuery::fromCurrentVersion\(\)/);
  assert.match(visibility, /\.current_version_id/);
  assert.match(visibility, /\.knowledge_item_id = .*\.id/);
  assert.match(visibility, /\.status = 'active'/);
  assert.match(visibility, /\.status = 1/);
  assert.match(visibility, /\.publication_status = 'published'/);
  assert.match(service, /k\.content_type/);
  assert.match(service, /k\.domain_code/);
  assert.match(service, /k\.risk_level/);
  assert.doesNotMatch(categories, /\$_GET\[['"](?:role|stage)['"]\]/);
  assert.match(categories, /k\.publication_status = 'published'/);
  assert.match(detail, /请先登录/);
  assert.match(detail, /EmployeeKnowledgeVisibilityQuery::fromCurrentVersion\(\)/);
  assert.match(search, /EmployeeKnowledgeVisibilityQuery::fromCurrentVersion\(\)/);
});

test('考试草稿修复参数契约并提供兼容乐观锁版本', () => {
  const controller = read('api/exam/save.php');
  const service = read('api/exam/ExamDraftService.php');
  assert.match(controller, /ExamDraftService/);
  assert.match(service, /PlatformStateVersion::advance\(/);
  assert.match(service, /SELECT id, answers FROM exam_records/);
  assert.match(service, /'state_version' => \$nextVersion/);
  assert.match(service, /VALUES \(\?, \?, \?, 'in_progress', \?, \?, NOW\(\), NOW\(\)\)/);
  assert.match(service, /->execute\(\[\$userId, \$sourceExamId, \$examType, \$encodedAnswers, \$duration\]\)/);
});

test('制度通知入口使用统一输入、动作方法和具名权限', () => {
  const controller = read('api/policy/notify.php');
  const service = read('api/policy/PolicyNotificationService.php');
  assert.match(controller, /getRequestInput\(/);
  assert.doesNotMatch(controller, /Access-Control-Allow-Origin:\s*\*/);
  assert.match(controller, /requirePermission\('policy\.notify_send'\)/);
  assert.match(controller, /method_not_allowed/);
  assert.match(service, /beginTransaction\(/);
  assert.match(service, /policy_read_history/);
  assert.match(service, /wecomDispatchPolicyNotification/);
});
