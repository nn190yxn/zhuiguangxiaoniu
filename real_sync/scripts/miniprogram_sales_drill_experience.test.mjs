import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const app = read('mini-program/app.json');
const doing = read('mini-program/pages/drill/doing/doing.js');
const doingView = read('mini-program/pages/drill/doing/doing.wxml');
const freeChat = read('mini-program/pages/drill/free-chat/free-chat.js');
const freeChatView = read('mini-program/pages/drill/free-chat/free-chat.wxml');
const drillClient = read('mini-program/utils/drill-v2.js');
const attemptsEndpoint = read('api/drill/v2/attempts.php');
const turnsEndpoint = read('api/drill/v2/turns.php');
const conversationService = read('api/drill/v2/services/DrillConversationService.php');
const employeeApiService = read('api/drill/v2/services/DrillEmployeeApiService.php');
const evaluationService = read('api/drill/v2/services/DrillEvaluationService.php');
const aiAdapter = read('api/drill/v2/services/DrillAiAdapter.php');
const feedback = read('mini-program/pages/drill/feedback/feedback.js');
const feedbackView = read('mini-program/pages/drill/feedback/feedback.wxml');
const personaMigration = read('database/migrations/202608210004_drill_persona_five_dimensions.sql');
const migrationCatalog = read('database/migration_catalog.php');

test('销售演练小程序覆盖录音、转文字、音频上传和文本兜底', () => {
  assert.match(app, /"WechatSI"/);
  assert.match(app, /scope\.record/);
  assert.match(doing, /wx\.getRecorderManager\(\)/);
  assert.match(doing, /getRecordRecognitionManager\(\)/);
  assert.match(doing, /voiceManager\.start\(/);
  assert.match(doing, /voiceManager\.onRecognize/);
  assert.match(doing, /voiceManager\.onStop/);
  assert.match(doing, /uploadRecording\(/);
  assert.match(drillClient, /uploadAudioTurn/);
  assert.match(drillClient, /audio-assets\.php/);
  assert.match(drillClient, /audio-chunks\.php/);
  assert.match(drillClient, /turns\/finalize\.php/);
  assert.match(drillClient, /final_transcript_text/);
  assert.match(doing, /textFallbackAvailable/);
  assert.match(doing, /音频上传中断，可改用文本提交/);
  assert.match(doing, /privacy\.getRecordAuthorizationStatus\(\)/);
  assert.match(freeChat, /privacy\.getRecordAuthorizationStatus\(\)/);
  assert.match(doing, /if \(this\.data\.recorderActive\)/);
  assert.match(doing, /isRecording: true, recorderActive: true/);
  assert.match(doing, /stopRecording\(\) \{\n    if \(!this\.data\.recorderActive\) return;/);
  assert.match(doing, /if \(this\.data\.voiceActive\)/);
  assert.match(freeChat, /if \(this\.data\.voiceActive\)/);
  assert.match(doing, /practice_context/);
  assert.match(doing, /standard_expressions/);
  assert.match(doing, /normalizeScripts/);
  assert.match(doingView, /当前场景暂无参考话术/);
  assert.match(doingView, /请结合参考话术完成模拟回答/);
  assert.match(doingView, /请根据上方演练目标完成模拟回答/);
  assert.match(doing, /录音隐私声明尚未生效，请先使用文字回答/);
  assert.match(freeChat, /录音隐私声明尚未生效，请先使用文字回答/);
});

test('自由演练支持 AI 家长对话、随机画像、筛选画像和结束评分入口', () => {
  assert.match(freeChat, /create_self_practice/);
  assert.match(freeChat, /mode: 'free_chat'/);
  assert.match(freeChat, /buildSelectionContext\(/);
  assert.match(freeChat, /random_seed: this\.data\.randomMode \? Date\.now\(\) : null/);
  for (const key of ['age_band', 'primary_need', 'communication_style', 'current_status', 'course_tag']) {
    assert.match(freeChat, new RegExp(key));
  }
  assert.match(freeChat, /buildPersonaFields/);
  assert.match(freeChat, /onPersonaFilterChange/);
  assert.match(freeChatView, /wx:for="{{personaFields}}"/);
  assert.match(freeChatView, /range-key="name"/);
  assert.match(employeeApiService, /persona_options/);
  assert.match(employeeApiService, /drill_persona_dimensions/);
  assert.match(freeChat, /submitTextTurn/);
  assert.match(freeChat, /generateOpeningQuestion/);
  assert.match(freeChat, /buildOpeningQuestion/);
  assert.match(freeChat, /voicePressed/);
  assert.match(freeChat, /customer_turn && res\.customer_turn\.content/);
  assert.match(freeChat, /status_version: res\.status_version/);
  assert.match(freeChat, /endAttempt/);
  assert.match(freeChat, /feedback\/feedback\?id=/);
  assert.match(freeChatView, /结束并评分/);
  assert.match(turnsEndpoint, /submitTextTurnWithGeneratedCustomer/);
  assert.match(aiAdapter, /generateCustomerTurn/);
  assert.match(aiAdapter, /客户/);
  assert.match(aiAdapter, /真实家长/);
  assert.match(aiAdapter, /每次只问一个自然问题/);
  assert.match(turnsEndpoint, /action === 'opening'/);
  assert.match(conversationService, /scenario_rules/);
  assert.match(conversationService, /currentStageDefinition/);
  assert.match(freeChatView, /当前环节：/);
});

test('模块化演练使用服务端流程板块、状态版本和步骤展示', () => {
  assert.match(doing, /process_sections/);
  assert.match(doing, /currentStep/);
  assert.match(doing, /statusVersion/);
  assert.match(doing, /loadAttemptStatus/);
  assert.match(doingView, /wx:for="{{steps}}"/);
  assert.match(doingView, /{{item\.status}}/);
  assert.doesNotMatch(doingView, /{{step\.status}}/);
  assert.match(conversationService, /drill_attempt_stage_progress/);
  assert.match(conversationService, /advanceStage/);
  assert.match(conversationService, /只有完整流程演练可以切换板块/);
});

test('客户画像组合和课程匹配进入服务端冻结快照', () => {
  const assignmentCreation = conversationService.slice(
    conversationService.indexOf('function createFromAssignment'),
    conversationService.indexOf('function createPractice')
  );
  const selfPracticeCreation = conversationService.slice(
    conversationService.indexOf('function createPractice'),
    conversationService.indexOf('function resumeAttempt')
  );
  assert.match(attemptsEndpoint, /selection_context/);
  assert.match(conversationService, /normalizeSelectionContext/);
  assert.match(conversationService, /applySelectionContextToPersona/);
  assert.match(conversationService, /profile_overrides/);
  assert.match(conversationService, /drill_persona_dimensions/);
  assert.match(conversationService, /generated_profile/);
  assert.match(conversationService, /家长画像选项无效或已停用/);
  assert.match(conversationService, /in_array\(\$valueCode, \$validCodes, true\)/);
  assert.match(conversationService, /hash\('sha256', \$seed \. '\|' \. \$dimensionCode\)/);
  assert.match(conversationService, /random_int\(1, PHP_INT_MAX\)/);
  assert.doesNotMatch(assignmentCreation, /selectionContext/);
  assert.match(selfPracticeCreation, /applySelectionContextToPersona/);
  assert.match(conversationService, /drill_scenario_personas/);
  assert.match(conversationService, /persona_snapshot_json/);
  assert.match(conversationService, /persona_snapshot_hash/);
  assert.match(conversationService, /course_match_context/);
  assert.match(conversationService, /matched_courses/);
  for (const key of ['age_band', 'primary_need', 'communication_style', 'current_status', 'course_tag']) {
    assert.match(conversationService, new RegExp(key));
  }
});

test('五维画像生产种子可幂等执行并受 readiness 门禁保护', () => {
  assert.match(personaMigration, /INSERT INTO `drill_persona_dimensions`/);
  assert.match(personaMigration, /ON DUPLICATE KEY UPDATE/);
  assert.match(personaMigration, /`status` = VALUES\(`status`\)/);
  assert.match(personaMigration, /domain_row\.`domain_code` = 'new_signing'/);
  assert.match(personaMigration, /'content_package'/);
  assert.match(personaMigration, /'sales-drill-persona-v1'/);
  assert.doesNotMatch(personaMigration, /\b(?:DELETE|TRUNCATE|DROP)\b/i);
  for (const key of ['age_band', 'primary_need', 'communication_style', 'current_status', 'course_tag']) {
    assert.match(personaMigration, new RegExp(`'${key}'`));
    assert.match(migrationCatalog, new RegExp(`'${key}'`));
  }
  const expectedOptions = {
    age_band: ['preschool', 'primary', 'middle_school', 'high_school'],
    primary_need: ['fitness', 'height', 'confidence', 'exam'],
    communication_style: ['rational', 'direct', 'cautious', 'emotional'],
    current_status: ['first_contact', 'comparing', 'experienced', 'renewal'],
    course_tag: ['fitness', 'height', 'exam'],
  };
  for (const [dimension, values] of Object.entries(expectedOptions)) {
    for (const value of values) {
      const pair = new RegExp(`'${dimension}'[^\\n]*'${value}'`);
      assert.match(personaMigration, pair);
      assert.match(migrationCatalog, pair);
    }
  }
  assert.match(migrationCatalog, /'202608210004' => '[a-f0-9]{64}'/);
  assert.match(migrationCatalog, /'id' => 'new_signing_five_dimension_personas_missing'/);
  assert.match(migrationCatalog, /'type' => 'expected_zero'/);
  assert.match(migrationCatalog, /persona\.status = 'active'/);
});

test('评分体系识别薄弱项并生成反馈、证据和学习建议', () => {
  assert.match(drillClient, /endAttempt/);
  assert.match(attemptsEndpoint, /\$action === 'end'/);
  assert.match(attemptsEndpoint, /endAttempt\(/);
  assert.match(turnsEndpoint, /if \(\$action === 'opening'\)/);
  assert.match(turnsEndpoint, /generateOpeningCustomerTurn/);
  assert.match(turnsEndpoint, /does not require write idempotency/);
  assert.match(conversationService, /drill\.evaluation\.process/);
  assert.match(evaluationService, /DrillEvaluationPolicy::score/);
  assert.match(evaluationService, /dimension_scores_json/);
  assert.match(evaluationService, /critical_results_json/);
  assert.match(evaluationService, /suggestions_json/);
  assert.match(evaluationService, /drill_evaluation_evidence/);
  assert.match(evaluationService, /generateRecommendationsInTransaction/);
  assert.match(aiAdapter, /smart_actions/);
  assert.match(aiAdapter, /insufficient_evidence/);
  assert.match(employeeApiService, /suggestions_json/);
  assert.match(feedback, /evaluation_status === 'completed'/);
  assert.match(feedback, /loadAttemptStatus/);
  assert.match(feedback, /item\.status === 'retry_pending'/);
  assert.match(feedback, /status\.poll_after_seconds/);
  assert.match(feedback, /priority_improvements/);
  assert.match(feedback, /dimension_scores = Array\.isArray/);
  assert.match(feedback, /deal_risk/);
  assert.match(feedback, /replacement_scripts/);
  assert.match(feedback, /score_percent/);
  assert.match(feedback, /training_tasks/);
  assert.match(feedback, /critical_risks/);
  assert.match(feedbackView, /最大成交风险/);
  assert.match(feedbackView, /feedback\.deal_risk \|\|/);
  assert.match(feedbackView, /证据片段/);
  assert.match(feedbackView, /下一步训练任务/);
});
