import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';
import vm from 'node:vm';

const read = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
function dom() {
  const nodes = new Map();
  function node(id) {
    if (!nodes.has(id)) {
      const classes = new Set();
      nodes.set(id, { innerHTML: '', textContent: '', value: '', style: {}, disabled: false,
        focus() {}, addEventListener() {}, setAttribute() {}, remove() {},
        querySelector() { return { focus() {} }; },
        classList: { add: value => classes.add(value), remove: value => classes.delete(value), contains: value => classes.has(value) }
      });
    }
    return nodes.get(id);
  }
  return { getElementById: node, querySelectorAll: () => [], activeElement: null };
}
function drillPage() {
  const document = dom();
  const context = vm.createContext({ document, window: { addEventListener() {} }, navigator: {},
    setTimeout() { return 1; }, clearTimeout() {}, console, ApiClient: {} });
  vm.runInContext(read('mobile/drill.html').match(/<script>\s*([\s\S]*?)<\/script>/)[1], context);
  context.saveAttemptDraft = () => {};
  context.refreshMicrophonePermission = () => {};
  return context;
}
function resumeData(id = 10) {
  return { attempt: { attempt_id: id, status: 'active', status_version: 8, last_completed_turn_no: 4 },
    turns: [{ speaker: 'employee', content: 'first' }, { speaker: 'customer', content: 'second' }, { speaker: 'employee', content: 'third' }, { speaker: 'customer', content: 'fourth' }],
    practice_context: { scenario: { title: 'scene' }, persona: { primary_need: 'attention' } },
    stage_progress: [{ name: 'opening', status: 'completed' }, { name: 'needs', status: 'active' }] };
}

test('第四批PHP生产服务在隔离SQLite执行队列、恢复、关联报告和指标查询', () => {
  const result = spawnSync('php', ['scripts/intranet_batch4_services.test.php'], { cwd: new URL('..', import.meta.url), encoding: 'utf8', timeout: 15000 });
  assert.equal(result.status, 0, result.stderr + result.stdout);
  const evidence = JSON.parse(result.stdout);
  assert.ok(evidence.passed >= 20);
});

test('招聘入口恢复并保留权限、批次范围、幂等与统一Worker接线', () => {
  const endpoint = read('api/admin/recruitment/process.php');
  for (const marker of ['recruitment.resume_upload', "['POST']", 'accessibleBatch', 'recruitmentAdminRequireIdempotency', 'recruitmentAdminIdempotent', 'dispatchBatch', 'adminRecordOperation']) assert.ok(endpoint.includes(marker), marker);
  assert.ok(read('api/admin/recruitment/services/ResumeProcessingService.php').includes('->retry($job)'));
});

test('刷新从resume恢复多轮对话、上下文和阶段，只调用恢复动作', async () => {
  const page = drillPage(), calls = [];
  page.activeAttemptDraft = () => ({ getLocal: () => ({ payload: { attempt_id: 10 } }) });
  page.apiPost = async (path, payload) => { calls.push({ path, payload }); return { data: resumeData() }; };
  await page.restoreActiveAttempt();
  await page.restoreActiveAttempt();
  assert.deepEqual(calls.map(call => call.payload.action), ['resume', 'resume']);
  assert.equal(page.drill.attempt.id, 10);
  assert.equal(page.drill.attempt.status_version, 8);
  assert.equal(page.drill.turns.length, 4);
  assert.match(page.document.getElementById('sheetBody').innerHTML, /fourth[\s\S]*needs[\s\S]*opening/);
});

test('演练状态轮询合并负责字段，保留对话上下文并忽略旧版本', () => {
  const page = drillPage();
  page.openConversation(resumeData().attempt, resumeData());
  page.applyAttemptState({ attempt: { id: 10, status_version: 9, status: 'evaluating' }, turns: [], practice_context: {} });
  assert.equal(page.drill.turns.length, 4);
  assert.equal(page.drill.practiceContext.persona.primary_need, 'attention');
  page.applyAttemptState({ attempt: { id: 10, status_version: 7, status: 'active' } });
  assert.equal(page.drill.attempt.status_version, 9);
  page.applyAttemptState({ attempt: { id: 99, status_version: 20 } });
  assert.equal(page.drill.attempt.id, 10);
});

test('恢复接口失败保留已有对话并明确提示', async () => {
  const page = drillPage();
  page.openConversation(resumeData().attempt, resumeData());
  let message = '';
  page.notice = text => { message = text; };
  page.apiPost = async () => { throw new Error('offline'); };
  await page.resumeConversation(10);
  assert.match(message, /恢复失败/);
  assert.equal(page.drill.turns.length, 4);
});

test('进行中同一计划项的活跃必修实例恢复四轮对话且不创建新实例', async () => {
  const page = drillPage(), actions = [];
  page.apiGet = async () => ({ data: { items: [{ status: 'in_progress', current_attempt_id: 10, current_attempt_status: 'active', current_attempt_plan_item_id: '2' }] } });
  page.apiPost = async (path, input) => { actions.push(input.action); return { data: resumeData() }; };
  await page.startAssignment(1, 2);
  assert.deepEqual(actions, ['resume']);
  assert.equal(page.drill.attempt.id, 10);
  assert.equal(page.drill.turns.length, 4);
});

test('失败后重练跳过保留的旧ID，新建后再次点击恢复新实例', async () => {
  for (const oldStatus of ['evaluated', 'completed', 'failed', 'active']) {
    const page = drillPage(), calls = [];
    const assignment = { status: 'retry_available', current_attempt_id: 10, current_attempt_status: oldStatus, current_attempt_plan_item_id: 2, items: [{ plan_item_id: 2 }] };
    page.apiGet = async () => ({ data: { items: [assignment] } });
    page.apiPost = async (path, input) => {
      calls.push(input);
      if (input.action === 'create') {
        assert.equal(input.assignment_id, 1);
        assert.equal(input.plan_item_id, 2);
        Object.assign(assignment, { status: 'in_progress', current_attempt_id: 42, current_attempt_status: 'active' });
      }
      if (input.action === 'resume') assert.equal(input.attempt_id, 42);
      return { data: resumeData(42) };
    };
    page.openResult = () => assert.fail('重练不能打开旧报告');
    await page.openAssignments();
    assert.match(page.document.getElementById('sheetBody').innerHTML, /重新练习/);
    await page.startAssignment(1, 2);
    await page.startAssignment(1, 2);
    assert.deepEqual(calls.map(call => call.action), ['create', 'resume', 'resume'], oldStatus);
    assert.equal(page.drill.attempt.id, 42);
    await page.openAssignments();
    assert.match(page.document.getElementById('sheetBody').innerHTML, /恢复演练/);
  }
});

test('进行中任务的旧终态实例或其他计划项实例均不作为恢复目标', async () => {
  for (const [status, itemId] of [['completed', 2], ['evaluated', 2], ['failed', 2], ['active', 3]]) {
    const page = drillPage(), calls = [];
    page.apiGet = async () => ({ data: { items: [{ status: 'in_progress', current_attempt_id: 10, current_attempt_status: status, current_attempt_plan_item_id: itemId }] } });
    page.apiPost = async (path, input) => { calls.push(input); return { data: resumeData(42) }; };
    await page.startAssignment(1, 2);
    assert.deepEqual(calls.map(call => call.action), ['create', 'resume']);
    assert.equal(calls[0].plan_item_id, 2);
    assert.equal(calls[1].attempt_id, 42);
  }
});

test('暂停实例携带版本恢复，提交中和评分中实例保持原实例', async () => {
  for (const status of ['paused', 'turn_finalizing', 'evaluating']) {
    const page = drillPage(), calls = [], polls = [];
    page.pollStatus = attemptId => polls.push(attemptId);
    page.apiGet = async () => ({ data: { items: [{ status: status === 'evaluating' ? 'ai_evaluating' : 'in_progress', current_attempt_id: 10, current_attempt_status: status, current_attempt_plan_item_id: 2, current_attempt_status_version: 8 }] } });
    page.apiPost = async (path, input) => { calls.push(input); const data = resumeData(); data.attempt.status = status === 'paused' ? 'active' : status; return { data }; };
    await page.startAssignment(1, 2);
    assert.deepEqual(calls.map(call => call.action), status === 'paused' ? ['resume_paused', 'resume'] : ['resume']);
    if (status === 'paused') assert.equal(calls[0].status_version, 8);
    else assert.deepEqual(polls, [10]);
    assert.equal(page.drill.attempt.id, 10);
  }
});

test('待复核已通过和取消任务保留状态提示，不恢复或创建实例', async () => {
  for (const status of ['awaiting_review', 'passed', 'cancelled']) {
    const page = drillPage();
    let message = '';
    page.notice = text => { message = text; };
    page.apiGet = async () => ({ data: { items: [{ status, current_attempt_id: 10, current_attempt_status: 'active', current_attempt_plan_item_id: 2 }] } });
    page.apiPost = () => assert.fail('当前任务状态不可开始');
    await page.startAssignment(1, 2);
    assert.match(message, /当前任务/);
  }
});

test('新建必修实例使用服务返回的attempt_id恢复同一实例', async () => {
  const page = drillPage(), calls = [];
  page.apiGet = async () => ({ data: { items: [{ status: 'assigned', current_attempt_id: null }] } });
  page.apiPost = async (path, input) => { calls.push(input); return { data: resumeData(42) }; };
  await page.startAssignment(1, 2);
  assert.deepEqual(calls.map(call => call.action), ['create', 'resume']);
  assert.equal(calls[1].attempt_id, 42);
  assert.equal(page.drill.attempt.id, 42);
  assert.equal(page.drill.turns.length, 4);
  const endpoint = read('api/drill/v2/attempts.php');
  assert.doesNotMatch(endpoint, /\$created\['attempt'\]\['id'\]/);
});

test('结果展示真实复核推荐、空记录和请求失败重试入口', async () => {
  const page = drillPage();
  const item = { attempt_id: 10, evaluation_status: 'completed', total_score: 80, report: {}, learning_recommendations: [{ title: 'resource', reason: { evidence: { quoted_text: 'actual quote' } } }], review: { decision: 'passed', comment: 'actual review' }, growth: [], media: [{ status: 'expired' }] };
  page.apiGet = async () => ({ data: { items: [item] } });
  await page.openResult(10);
  let html = page.document.getElementById('sheetBody').innerHTML;
  assert.match(html, /actual review[\s\S]*actual quote/);
  assert.match(html, /暂无仍关联本次演练的成长汇总/);
  assert.match(html, /录音已到期/);
  assert.doesNotMatch(html, /\[object Object\]/);
  page.apiGet = async () => { throw new Error('service unavailable'); };
  await page.openResult(10);
  html = page.document.getElementById('sheetBody').innerHTML;
  assert.match(html, /service unavailable[\s\S]*重试加载报告/);
  assert.doesNotMatch(html, /actual review/);
  page.apiGet = async () => ({ data: { items: [{ attempt_id: 10 }] } });
  await page.openResult(10);
  assert.match(page.document.getElementById('sheetBody').innerHTML, /关联报告数据不完整/);
});

function fitnessPage() {
  const document = dom();
  const page = vm.createContext({ document, URLSearchParams, console, buildAuthHeaders: () => ({}), escapeHtml: text => String(text), sanitizeReportHtml: text => text });
  const html = read('fitness-assessment-app.html');
  vm.runInContext(html.slice(html.indexOf('var reportHistory ='), html.indexOf('function sanitizeReportHtml(')), page);
  return page;
}
function recordsResponse(records, total = records.length) {
  return { ok: true, headers: { get: () => String(total) }, json: async () => records };
}
test('101条体测数据可访问全部六页，末页禁用并从详情返回原页', async () => {
  const page = fitnessPage(), seen = new Set();
  const records = Array.from({ length: 101 }, (_, index) => ({ id: String(index + 1), child_name: `student-${index + 1}` }));
  page.fetch = async path => {
    const url = new URL(path, 'https://test.local');
    if (url.searchParams.has('id')) return { ok: true, json: async () => records[100] };
    const number = Number(url.searchParams.get('page'));
    assert.equal(url.searchParams.get('page_size'), '20');
    const rows = records.slice((number - 1) * 20, number * 20);
    rows.forEach(row => seen.add(row.id));
    return recordsResponse(rows, records.length);
  };
  for (let number = 1; number <= 6; number++) { page.reportHistory.page = number; await page.loadReportHistory(); }
  assert.equal(seen.size, 101);
  assert.match(page.document.getElementById('recordHistoryList').innerHTML, /student-101/);
  assert.match(page.document.getElementById('recordHistoryPagination').innerHTML, /6 \/ 6 页，共 101 条[\s\S]*disabled>下一页/);
  page.changeReportPage(7);
  assert.equal(page.reportHistory.page, 6);
  await page.showReportDetail('101');
  assert.match(page.document.getElementById('recordHistoryList').innerHTML, /student-101 的体测报告/);
  await page.loadReportHistory();
  assert.equal(page.reportHistory.page, 6);
});

test('体测筛选重置第一页，旧响应不会覆盖新结果或错误状态', async () => {
  const page = fitnessPage(), old = deferred();
  page.fetch = () => old.promise;
  const loading = page.loadReportHistory();
  page.reportHistory.page = 6;
  page.document.getElementById('recordStudentFilter').value = 'new';
  page.fetch = async path => {
    assert.match(path, /student=new&page=1/);
    return recordsResponse([{ id: 'new', child_name: 'new-result' }], 1);
  };
  await page.loadReportHistory();
  old.resolve(recordsResponse([{ id: 'old', child_name: 'old-result' }], 101));
  await loading;
  assert.equal(page.reportHistory.page, 1);
  assert.match(page.document.getElementById('recordHistoryList').innerHTML, /new-result/);
  assert.doesNotMatch(page.document.getElementById('recordHistoryList').innerHTML, /old-result/);
  page.fetch = async () => recordsResponse([], 0);
  await page.loadReportHistory();
  assert.match(page.document.getElementById('recordHistoryList').innerHTML, /暂无符合条件/);
  page.fetch = async () => ({ ok: true, headers: { get: () => null }, json: async () => [] });
  await page.loadReportHistory();
  assert.match(page.document.getElementById('recordHistoryList').innerHTML, /分页数据不完整/);
});

test('系统概览保留真实零并把缺失和失败指标显示为暂不可用', () => {
  const document = dom();
  const context = vm.createContext({ document, window: { requirePageAuth() {} } });
  const html = read('admin/system-dashboard.html');
  vm.runInContext([...html.matchAll(/<script>\s*([\s\S]*?)<\/script>/g)].at(-1)[1], context);
  context.renderSummary({ locked_account_count: 0, system_error_count: null });
  const result = document.getElementById('summaryCards').innerHTML;
  assert.match(result, /锁定账号[\s\S]*class="value">0</);
  assert.match(result, /系统异常[\s\S]*class="value">暂不可用</);
});
