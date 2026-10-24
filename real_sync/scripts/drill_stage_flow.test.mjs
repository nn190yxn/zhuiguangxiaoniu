import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const conversation = read('api/drill/v2/services/DrillConversationService.php');
const aiAdapter = read('api/drill/v2/services/DrillAiAdapter.php');
const employeeApi = read('api/drill/v2/services/DrillEmployeeApiService.php');
const attempts = read('api/drill/v2/attempts.php');
const freeChatJs = read('mini-program/pages/drill/free-chat/free-chat.js');
const freeChatWxml = read('mini-program/pages/drill/free-chat/free-chat.wxml');
const listJs = read('mini-program/pages/drill/list/list.js');
const listWxml = read('mini-program/pages/drill/list/list.wxml');
const feedbackJs = read('mini-program/pages/drill/feedback/feedback.js');
const feedbackWxml = read('mini-program/pages/drill/feedback/feedback.wxml');
const transport = read('mini-program/utils/drill-v2.js');

test('客户轮 AI 契约包含环节评估且失败可降级', () => {
  assert.match(aiAdapter, /stage_assessment/);
  assert.match(aiAdapter, /"advance" 或 "stay"/);
  assert.match(aiAdapter, /covered_actions/);
  assert.match(aiAdapter, /recommended === 'advance' \? 'advance' : 'stay'/);
  assert.match(aiAdapter, /结合员工上一轮回答中含糊或遗漏的地方追问/);
});

test('自主练习支持完整流程与单环节专项类型', () => {
  assert.match(conversation, /string \$practiceType = 'self_practice'/);
  assert.match(conversation, /\['self_practice', 'stage_practice', 'full_process'\]/);
  assert.match(conversation, /resolveScenarioForStage/);
  assert.match(conversation, /该环节暂无可用的练习场景。/);
  assert.match(attempts, /action === 'advance_stage'/);
  assert.match(attempts, /start_stage_code/);
  assert.match(attempts, /practice_type/);
});

test('环节推进返回切换信息与新环节开场问题', () => {
  assert.match(conversation, /advanceStageWithTransition/);
  assert.match(conversation, /stage_transition/);
  assert.match(conversation, /opening_question/);
  assert.match(conversation, /buildStageAdvancement/);
  assert.match(conversation, /conversationWindow\(int \$attemptId, int \$limit = 12\)/);
});

test('attempt-status 暴露练习类型与完整环节进度', () => {
  assert.match(employeeApi, /practice_type/);
  assert.match(employeeApi, /stage_progress/);
  assert.match(employeeApi, /is_current/);
});

test('catalog 输出带契约摘要的流程环节清单', () => {
  assert.match(employeeApi, /process_stages/);
  assert.match(employeeApi, /core_goal/);
  assert.match(employeeApi, /validation_actions/);
});

test('对练页支持三种模式并处理推进与重试', () => {
  assert.match(freeChatJs, /mode === 'flow'/);
  assert.match(freeChatJs, /practice_type = 'full_process'/);
  assert.match(freeChatJs, /practice_type = 'stage_practice'/);
  assert.match(freeChatJs, /confirmAdvance/);
  assert.match(freeChatJs, /manualAdvance/);
  assert.match(freeChatJs, /advanceStage\(/);
  assert.match(freeChatJs, /retryPendingTurn/);
  assert.match(freeChatJs, /handleRetryPending/);
  assert.match(freeChatJs, /stage_advancement/);
  assert.match(freeChatWxml, /stage-bar/);
  assert.match(freeChatWxml, /stage-goal-card/);
  assert.match(freeChatWxml, /advance-card/);
  assert.match(freeChatWxml, /进入下一环节/);
  assert.match(freeChatWxml, /重试发送/);
});

test('演练中心提供四类入口与专项环节直达', () => {
  assert.match(listJs, /goToEntry/);
  assert.match(listJs, /goToStagePractice/);
  assert.match(listJs, /loadStageEntries/);
  assert.match(listJs, /mode=stage&stageCode=/);
  assert.match(listWxml, /entry-grid/);
  assert.match(listWxml, /stage-grid/);
});

test('结果页展示成交维度、薄弱专项直达与教练点评', () => {
  assert.match(feedbackJs, /dimension_list/);
  assert.match(feedbackJs, /loadWeakestDimension/);
  assert.match(feedbackJs, /practiceWeakStage/);
  assert.match(feedbackJs, /loadStageJourney/);
  assert.match(feedbackJs, /loadMastery/);
  assert.match(feedbackJs, /replacement_scripts/);
  assert.match(feedbackWxml, /销冠教练点评/);
  assert.match(feedbackWxml, /我的掌握度/);
  assert.match(feedbackWxml, /去练/);
});

test('传输层暴露 advance_stage 动作', () => {
  assert.match(transport, /advanceStage/);
  assert.match(transport, /action: 'advance_stage'/);
});
