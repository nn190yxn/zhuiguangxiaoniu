import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');
const drill = read('../mobile/drill.html');
const workload = read('../mobile/workload-v2.html');
const mine = read('../mobile/mine.html');
const sw = read('../sw.js');
const mobilePwa = read('../js/mobile-pwa.js');

test('移动一级入口固定为工作量、演练、我的', () => {
  for (const page of [drill, workload, mine]) {
    for (const label of ['工作量', '演练', '我的']) assert.match(page, new RegExp(label));
  }
  assert.match(drill, /mobile\/workload\.html[\s\S]*mobile\/drill\.html[\s\S]*mobile\/mine\.html/);
});

test('演练 PWA 只通过 ApiClient 调用 v2 演练接口', () => {
  for (const endpoint of ['home.php', 'catalog.php', 'assignments.php', 'attempts.php', 'turns.php', 'attempt-status.php', 'results.php', 'progress.php', 'audio-assets.php', 'audio-chunks.php', 'turns/finalize.php']) assert.match(drill, new RegExp('/api/drill/v2/'+endpoint.replace(/[/.]/g, '\\$&')));
  assert.match(drill, /ApiClient\.get/);
  assert.match(drill, /ApiClient\.post[\s\S]*idempotencyKey:id\(\)/);
  assert.match(drill, /onConflict:function\(error\)[\s\S]*refreshAll\(\)/);
  assert.doesNotMatch(drill, /fetch\('\/api\/drill/);
  assert.match(drill, /item\.learning_recommendations\|\|\[\]/);
  assert.match(drill, /item\.review\|\|\{\}/);
  assert.match(drill, /item\.growth\|\|\[\]/);
  assert.match(drill, /item\.media\|\|\[\]/);
  assert.match(drill, /evidence_status==='insufficient_evidence'/);
  assert.match(drill, /当前分数不用于能力判断/);
  assert.match(drill, /录音已到期/);
  assert.match(drill, /src="\/js\/draft-store\.js"/);
});

test('演练流程涵盖学习、文本与语音、恢复、评分、反馈和认证详情', () => {
  for (const marker of ['准备学习', '文本', 'getUserMedia', 'MediaRecorder', 'audio-chunks.php', 'audio-access.php', 'playLatestAudio', 'online', 'pollStatus', '四段反馈', '学习推荐', '认证详情', '历史与认证']) assert.match(drill, new RegExp(marker));
  assert.match(drill, /create_self_practice/);
  assert.match(drill, /startSelfPractice/);
  assert.match(drill, /attempt\.attempt_id/);
  assert.match(drill, /@media\(max-width:380px\)/);
  assert.match(drill, /@media\(min-width:700px\)/);
  assert.match(drill, /openCatalog\('new_signing'\)/);
  assert.match(drill, /domain==='new_signing'\?'新签训练'/);
  assert.doesNotMatch(drill, /openCatalog\('new_sign'\)/);
});

test('自由对练支持随机客户、组合筛选、画像摘要和创建上下文恢复', () => {
  for (const marker of ['openFreeChat', 'startRandomFreeChat', 'showFreeChatResults', '客户画像', '年龄段', '核心需求', '沟通风格', '当前状态', '课程标签', 'selection_context', 'random_seed']) assert.match(drill, new RegExp(marker));
  assert.match(drill, /mode=random/);
  assert.match(drill, /freeChatSelection/);
  assert.match(drill, /function notice\(text\)[\s\S]*sheetNotice/);
  assert.match(drill, /sheetNotice\.remove\(\)/);
});

test('演练 PWA 使用服务端权威状态并按后端限制分片上传录音', () => {
  assert.match(drill, /function applyAttemptState\(state\)/);
  assert.match(drill, /applyAttemptState\(data\)/);
  assert.match(drill, /applyAttemptState\(\(finalized\.data\|\|\{\}\)\.turn\|\|\{\}\)/);
  assert.match(drill, /chunkSize=4\*1024\*1024/);
  assert.match(drill, /blob\.slice\(index\*chunkSize/);
  assert.match(drill, /expected_chunks:chunkCount/);
  assert.match(drill, /录音上传进度/);
  assert.match(drill, /function draftForAttempt\(\)/);
  assert.match(drill, /turnDraft\.clearLocal\(\)/);
  assert.match(drill, /function activeAttemptDraft\(\)/);
  assert.match(drill, /allowedFields:\['attempt_id','last_completed_turn_no','status_version','pending_action','recording_recovery'\]/);
  assert.match(drill, /function restoreActiveAttempt\(\)/);
  assert.match(drill, /saveAttemptDraft\(\{pending_action:'audio_upload'/);
  assert.match(drill, /recording_recovery:\{audio_asset_id:assetId,chunk_count:chunkCount,uploaded_chunks:uploadedChunks,size:blob\.size\}/);
  assert.match(drill, /restoreActiveAttempt\(\)/);
  assert.match(drill, /function refreshAuthoritativeAttempt\(\)/);
  assert.match(drill, /pwa:session-restored/);
});

test('演练 PWA 为文本、录音、上传和恢复提供明确操作状态', () => {
  assert.match(drill, /function setConversationState\(status,detail\)/);
  for (const state of ['submitting', 'recording', 'uploading', 'customer_response', 'evaluating', 'recovering', 'recoverable', 'text_fallback']) {
    assert.ok(drill.includes(`setConversationState('${state}'`));
  }
  assert.match(drill, /录音不可用，当前可直接使用文本输入/);
});

test('演练 PWA 提供麦克风权限引导、系统设置说明和文本辅助输入', () => {
  for (const marker of ['开启麦克风', 'microphonePermission', 'refreshMicrophonePermission', 'navigator.permissions.query', '浏览器地址栏的网站设置中开启麦克风权限', '文本辅助输入']) {
    assert.match(drill, new RegExp(marker));
  }
  assert.match(drill, /showMicrophonePermission\('unsupported'\)/);
  assert.match(drill, /showMicrophonePermission\(denied\?'denied':'prompt'/);
});

test('模拟场景卡由实例上下文和最近对话生成完整练习提示', () => {
  const source = drill.match(/function renderStages\(items\)\{[\s\S]*?\}\nasync function submitTurn/);
  assert.ok(source, 'PWA must expose the guided practice renderer');
  const context = { esc: value => String(value) };
  vm.runInNewContext(source[0].replace(/\nasync function submitTurn$/, ''), context);
  const html = context.renderPracticeScenario({
    scenario: { title: '首次到店沟通', objectives: ['识别家长核心需求'], standard_expressions: ['我先了解一下孩子的日常表现。'] },
    persona: { age_band: '4_to_6', primary_need: 'attention', current_status: 'comparing_providers' },
    current_stage: { name: '需求诊断' }
  }, [{ speaker: 'customer', content: '孩子上课坐不住，您有什么建议？' }]);

  for (const marker of ['客户角色：', '当前情境：', '客户开场问题：孩子上课坐不住', '练习目标：需求诊断：识别家长核心需求', '参考表达：我先了解一下孩子的日常表现。']) {
    assert.match(html, new RegExp(marker));
  }
  assert.match(drill, /practice_context/);
  assert.match(drill, /data\.turns=\(drill\.turns\|\|\[\]\)\.concat/);
});

test('演练板块按服务端名称分组显示当前、已完成和后续内容', () => {
  const source = drill.match(/function renderStages\(items\)\{[\s\S]*?\}\nasync function submitTurn/);
  assert.ok(source, 'renderStages must remain available in the PWA');
  const context = { esc: value => String(value) };
  vm.runInNewContext(source[0].replace(/\nasync function submitTurn$/, ''), context);

  assert.equal(context.renderStages([]), '');
  assert.match(context.renderStages([{ name: '需求诊断', status: 'active' }]), /当前板块[\s\S]*需求诊断 active/);
  assert.match(
    context.renderStages([
      { name: '开场破冰', status: 'completed' },
      { name: '需求诊断', status: 'active' },
      { name: '方案匹配', status: 'pending' },
    ]),
    /当前板块[\s\S]*需求诊断 active[\s\S]*已完成板块（1）[\s\S]*开场破冰 completed[\s\S]*后续板块（1）[\s\S]*方案匹配 pending/
  );
});

test('任务 4.5：恢复旅程保留权威版本、草稿、录音进度和结果读取', () => {
  assert.match(drill, /status_version:drill\.attempt\.status_version/);
  assert.match(drill, /function restoreActiveAttempt\(\)[\s\S]*attempt-status\.php\?attempt_id=/);
  assert.match(drill, /window\.addEventListener\('online'[^\n]*refreshAuthoritativeAttempt\(\)/);
  assert.match(drill, /getUserMedia[\s\S]*文本辅助输入/);
  assert.match(drill, /recording_recovery:\{audio_asset_id:assetId,chunk_count:chunkCount,uploaded_chunks:uploadedChunks,size:blob\.size\}/);
  assert.match(drill, /function openResult\(attemptId\)[\s\S]*results\.php\?attempt_id=/);
  assert.match(drill, /learning_recommendations[\s\S]*review[\s\S]*growth[\s\S]*media/);
});

test('Service Worker 缓存应用外壳，所有写请求直连网络', () => {
  assert.match(sw, /CACHE_NAME = 'zgxn-pwa-shell-v\d+'/);
  assert.match(sw, /cache\.addAll\(SHELL\)/);
  assert.match(sw, /if \(request\.method !== 'GET'\) return/);
  assert.match(sw, /url\.pathname\.startsWith\('\/api\/'\)/);
  assert.match(drill, /src="\/js\/mobile-pwa\.js\?v=13"/);
  assert.match(mobilePwa, /addEventListener\('updatefound'/);
});
