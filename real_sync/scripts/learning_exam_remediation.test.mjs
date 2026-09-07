import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { spawnSync } from 'node:child_process';

const read = path => readFileSync(new URL('../' + path, import.meta.url), 'utf8');
test('六模块真实服务评分入库，后台实际列表 SQL 可查成绩', () => {
  const modules = ['03-reception', '04-assessment', '05-trial', '06-communication', '07-renewal', '08-goals'].map(module =>
    JSON.parse(read('training/' + module + '/index.html').match(/window.EXAM_DATA = (\{[\s\S]*?\n\});/)[1]));
  const result = spawnSync('php', ['scripts/training_exam_persistence.test.php'], { input: JSON.stringify(modules), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.equal(JSON.parse(result.stdout).admin_query_rows, 6);
});
const response = (data, ok = true, state = '') => ({ ok, headers: { get: () => state }, json: async () => ({ code: ok ? 0 : 1, data, message: '测试失败' }) });
const examDetail = ctx => response({ exam: { id: ctx.EXAM_DATA.examId, title: '后台考试', duration: 30, total_score: 100, pass_score: 60 } });
function environment() {
  const nodes = new Map();
  const node = () => ({ innerHTML: '', textContent: '', disabled: false, hidden: false, dataset: {},
    classList: { contains: () => false, add() {}, toggle() {} }, querySelectorAll: () => [], prepend() {}, children: [], appendChild(child) { this.children.push(child); }, replaceChildren() {} });
  const storage = new Map();
  const ctx = { console, URLSearchParams, URL, Date, crypto: { randomUUID: () => 'test-key' },
    document: { getElementById: id => { if (!nodes.has(id)) nodes.set(id, node()); return nodes.get(id); },
      querySelector: () => node(), createElement: node, body: node() },
    sessionStorage: { getItem: k => storage.get(k), setItem: (k, v) => storage.set(k, v) },
    confirm: () => true, alert() {}, scrollTo() {}, setTimeout() {}, requirePageAuth() {}, authHeaders: extra => ({ ...extra, Authorization: 'Bearer test' }),
    escapeHtmlExam: String, location: { search: '?id=1', href: 'https://example.com/mobile/course.html?id=1' } };
  ctx.window = ctx;
  ctx.authFetch = (...args) => ctx.fetch(...args);
  vm.createContext(ctx);
  return { ctx, nodes, storage };
}

test('21 条课程分页完整，末页停止请求，旧分类响应被丢弃', async () => {
  const { ctx, nodes } = environment();
  const html = read('mobile/learning.html');
  vm.runInContext(html.match(/<script>\s*([\s\S]*?)<\/script>/)[1], ctx);
  let calls = 0;
  ctx.fetch = async url => {
    calls++;
    const page = Number(new URL(url, 'https://example.com').searchParams.get('page'));
    return response({ page, page_size: 20, total: 21, list: Array.from({ length: page === 1 ? 20 : 1 }, (_, i) => ({ id: (page - 1) * 20 + i + 1, title: '课程' })) });
  };
  await ctx.loadCourses(); await ctx.loadCourses(true); await ctx.loadCourses(true);
  assert.equal(calls, 2);
  assert.equal((nodes.get('courseList').innerHTML.match(/class="course-card/g) || []).length, 21);
  assert.equal(nodes.get('courseMore').hidden, true);
  let resolveOld;
  ctx.fetch = () => new Promise(resolve => { resolveOld = resolve; });
  const old = ctx.loadCourses();
  ctx.fetch = async () => response({ page: 1, page_size: 20, total: 0, list: [] });
  await ctx.loadCourses();
  resolveOld(response({ page: 1, page_size: 20, total: 1, list: [{ id: 99, title: '过期' }] })); await old;
  assert.match(nodes.get('courseList').innerHTML, /暂无课程/);
});

test('签到 JWT 位于 headers，双击只发一次，失败允许重试', async () => {
  const { ctx, nodes } = environment();
  vm.runInContext(read('mobile/learning.html').match(/<script>\s*([\s\S]*?)<\/script>/)[1], ctx);
  let finish, calls = 0;
  ctx.fetch = (url, options) => {
    calls++; assert.equal(options.headers.Authorization, 'Bearer test');
    assert.ok(options.headers['Idempotency-Key']);
    return new Promise(resolve => { finish = resolve; });
  };
  const first = ctx.doCheckin(); await ctx.doCheckin(); assert.equal(calls, 1);
  finish(response({}, false)); await first;
  assert.equal(nodes.get('checkinBtn').disabled, false);
  assert.notEqual(nodes.get('checkinStatus')?.textContent, '已签到');
});

for (const module of ['03-reception', '04-assessment', '05-trial', '06-communication', '07-renewal', '08-goals']) {
  test(module + ' 后台评分确认、网络失败后刷新重试复用答卷和幂等键', async () => {
    const { ctx, storage } = environment();
    ctx.EXAM_DATA = JSON.parse(read('training/' + module + '/index.html').match(/window.EXAM_DATA = (\{[\s\S]*?\n\});/)[1]);
    const requests = [];
    let fail = true;
    ctx.AppAuth = { getUserInfo: () => ({ id: 77 }), authFetch: async (url, options) => {
      if (url.includes('action=detail')) return examDetail(ctx);
      if (url.includes('questions')) return response({ questions: ctx.EXAM_DATA.questions.map(q => ({ ...q, question_type: 1 })) });
      requests.push(options);
      if (fail) { fail = false; throw new Error('network'); }
      return response({ exam_record_id: 42, score: 80, pass_score: 60, is_passed: true, question_results: [] });
    } };
    vm.runInContext(read('training/exam-common.js'), ctx);
    await ctx.renderExam();
    for (const q of ctx.EXAM_DATA.questions) ctx.selectOption(q.id, 'A');
    await ctx.submitExam();
    assert.equal(requests.length, 1);
    vm.runInContext(read('training/exam-common.js'), ctx);
    await ctx.renderExam(); await ctx.submitExam(); await ctx.submitExam();
    assert.equal(requests.length, 2);
    assert.equal(requests[0].body, requests[1].body);
    assert.equal(requests[0].headers['Idempotency-Key'], requests[1].headers['Idempotency-Key']);
    assert.equal(JSON.parse([...storage.values()][0]).result.exam_record_id, 42);
  });
}

test('试卷映射错误阻止提交，已回滚响应允许重新提交', async () => {
  const { ctx } = environment();
  ctx.EXAM_DATA = { examId: 2, questions: [{ id: 6, type: 'choice', content: '题目', score: 20, options: ['A'] }] };
  let posts = 0, match = false;
  ctx.AppAuth = { getUserInfo: () => ({ id: 77 }), authFetch: async url => {
    if (url.includes('action=detail')) return examDetail(ctx);
    if (url.includes('questions')) return response({ questions: match ? [{ ...ctx.EXAM_DATA.questions[0], question_type: 1 }] : [] });
    posts++; return response({}, false, 'rolled_back');
  } };
  vm.runInContext(read('training/exam-common.js'), ctx);
  await ctx.renderExam(); await ctx.submitExam(); assert.equal(posts, 0);
  match = true; await ctx.renderExam(); await ctx.submitExam();
  ctx.selectOption(6, 'A'); await ctx.submitExam(); assert.equal(posts, 2);
});

test('课程详情保存调用章节完成接口，失败重试复用 key', async () => {
  const { ctx, nodes } = environment();
  let init;
  ctx.requirePageAuth = options => { init = options.onAuthed; };
  const writes = [];
  ctx.AppAuth = { authFetch: async (url, options) => {
    if (options) { writes.push(options); if (writes.length === 1) throw new Error('network'); return response({}); }
    if (url.includes('detail.php')) return response({ course: { title: '课程', progress_percent: 0, completed_lessons: 0, total_lessons: 1 }, lessons: [{ id: 1, title: '第一章' }] });
    return response({ lesson: { title: '第一章', content: '正文' } });
  } };
  vm.runInContext(read('mobile/course.js'), ctx); await init();
  assert.equal(nodes.get('content').textContent, '正文');
  await nodes.get('complete').onclick(); await nodes.get('complete').onclick();
  assert.equal(writes.length, 2);
  assert.equal(writes[0].headers['Idempotency-Key'], writes[1].headers['Idempotency-Key']);
  assert.equal(nodes.get('message').textContent, '进度已保存');
});

test('不及格刷新后可明确重考，清空旧状态、新计时跨刷新保留、新提交使用新 key', async () => {
  const { ctx, nodes, storage } = environment();
  let now = 100000, sequence = 0;
  ctx.Date = { now: () => now };
  ctx.crypto.randomUUID = () => 'attempt-' + (++sequence);
  ctx.EXAM_DATA = JSON.parse(read('training/03-reception/index.html').match(/window.EXAM_DATA = (\{[\s\S]*?\n\});/)[1]);
  const requests = [];
  ctx.AppAuth = { getUserInfo: () => ({ id: 77 }), authFetch: async (url, options) => {
    if (url.includes('action=detail')) return examDetail(ctx);
    if (url.includes('questions')) return response({ questions: ctx.EXAM_DATA.questions.map(q => ({ ...q, question_type: 1 })) });
    requests.push(options);
    return response({ exam_record_id: requests.length, score: 0, pass_score: 60, is_passed: false, question_results: [] });
  } };
  const source = read('training/exam-common.js');
  vm.runInContext(source, ctx); await ctx.renderExam(); ctx.selectOption(6, 'B');
  now += 60000; await ctx.submitExam();
  vm.runInContext(source, ctx); await ctx.renderExam();
  const button = nodes.get('examContainer').children.at(-1);
  assert.equal(button.textContent, '重新考试');
  assert.match(nodes.get('examContainer').innerHTML, /未通过/);
  now += 60000;
  ctx.confirm = () => false; await button.onclick();
  assert.equal(JSON.parse([...storage.values()][0]).result.exam_record_id, 1);
  ctx.confirm = () => true;
  const originalSet = ctx.sessionStorage.setItem;
  ctx.sessionStorage.setItem = () => { throw new Error('storage'); };
  await button.onclick();
  assert.equal(JSON.parse([...storage.values()][0]).result.exam_record_id, 1);
  ctx.sessionStorage.setItem = originalSet;
  await button.onclick();
  const fresh = JSON.parse([...storage.values()][0]);
  assert.deepEqual({ ...fresh, paper: null }, { answers: {}, pending: null, result: null, startedAt: now, paper: null });
  assert.equal(fresh.paper.title, '后台考试');
  assert.match(nodes.get('examContainer').innerHTML, /提交试卷/);
  now += 7000;
  vm.runInContext(source, ctx); await ctx.renderExam();
  now += 5000; ctx.selectOption(7, 'A'); await ctx.submitExam();
  assert.equal(requests.length, 2);
  assert.notEqual(requests[0].headers['Idempotency-Key'], requests[1].headers['Idempotency-Key']);
  assert.equal(JSON.parse(requests[1].body).time_spent, 12);
  assert.deepEqual(JSON.parse(requests[1].body).answers, { 7: 'A' });
});

test('提交中和未知结果刷新后均阻止重考，重试严格重放原请求', async () => {
  const { ctx, storage, nodes } = environment();
  ctx.EXAM_DATA = JSON.parse(read('training/03-reception/index.html').match(/window.EXAM_DATA = (\{[\s\S]*?\n\});/)[1]);
  let rejectRequest, sequence = 0;
  ctx.crypto.randomUUID = () => 'attempt-' + (++sequence);
  const requests = [];
  ctx.AppAuth = { getUserInfo: () => ({ id: 77 }), authFetch: async (url, options) => {
    if (url.includes('action=detail')) return examDetail(ctx);
    if (url.includes('questions')) return response({ questions: ctx.EXAM_DATA.questions.map(q => ({ ...q, question_type: 1 })) });
    requests.push(options);
    if (requests.length === 1) return new Promise((resolve, reject) => { rejectRequest = reject; });
    return response({ exam_record_id: 42, score: 0, pass_score: 60, is_passed: false, question_results: [] });
  } };
  const source = read('training/exam-common.js');
  vm.runInContext(source, ctx); await ctx.renderExam(); ctx.selectOption(6, 'B');
  const submission = ctx.submitExam();
  await new Promise(resolve => setImmediate(resolve));
  const snapshot = [...storage.values()][0];
  ctx.restartExam(); assert.equal([...storage.values()][0], snapshot);
  rejectRequest(new Error('network')); await submission;
  vm.runInContext(source, ctx); await ctx.renderExam();
  assert.equal(nodes.get('examContainer').children.length, 0);
  ctx.restartExam(); ctx.selectOption(6, 'A');
  assert.equal([...storage.values()][0], snapshot);
  await ctx.submitExam();
  assert.equal(requests.length, 2);
  assert.equal(requests[0].body, requests[1].body);
  assert.equal(requests[0].headers['Idempotency-Key'], requests[1].headers['Idempotency-Key']);
  assert.equal(sequence, 1);
});

function remoteExamEnvironment() {
  const env = environment();
  const { ctx } = env;
  ctx.EXAM_DATA = { examId: 1, title: '静态标题', totalScore: 999, questions: [
    { id: 1, type: 'choice', content: '静态题干', options: ['静态选项'], answer: '旧答案', score: 999 }
  ] };
  const state = { questions: [{ id: '1', question_type: '1', content: '实际题干', options: ['后台截断选项', '实际选项B'], score: '20' }],
    requests: [], failGet: false, failPost: false,
    result: { exam_record_id: 91, score: 7, pass_score: 60, is_passed: false,
      question_results: [{ question_id: 1, question_type: 1, earned_score: 7, max_score: 25, is_correct: false }] } };
  let sequence = 0;
  ctx.crypto.randomUUID = () => 'remote-attempt-' + (++sequence);
  ctx.AppAuth = { getUserInfo: () => ({ id: 77 }), authFetch: async (url, options) => {
    state.requests.push({ url, options });
    if (options?.method === 'POST') {
      if (state.failPost) throw new Error('提交断网');
      return response(state.result);
    }
    if (state.failGet) throw new Error('加载断网');
    assert.equal(options.cache, 'no-store');
    if (url.includes('action=detail')) return examDetail(ctx);
    return response({ questions: state.questions });
  } };
  const reload = () => vm.runInContext(read('training/exam-common.js'), ctx);
  reload();
  return { ...env, state, reload, posts: () => state.requests.filter(req => req.options?.method === 'POST'),
    draft: () => JSON.parse(env.storage.get('training-exam:77:1')) };
}

test('静态与后台题干选项完全不同，加载真实试卷后可提交并使用服务端逐题评分', async () => {
  const { ctx, nodes, state, posts, draft } = remoteExamEnvironment();
  await ctx.renderExam();
  const html = nodes.get('examContainer').innerHTML;
  assert.match(html, /后台考试/);
  assert.match(html, /实际题干/);
  assert.match(html, /后台截断选项/);
  assert.doesNotMatch(html, /静态|999/);
  assert.equal(draft().paper.questions[0].score, 20);
  ctx.selectOption(1, 'B');
  await ctx.submitExam();
  assert.equal(posts().length, 1);
  assert.deepEqual(JSON.parse(posts()[0].options.body).answers, { 1: 'B' });
  assert.equal(state.requests.filter(req => req.url.includes('questions')).length, 2);
  assert.match(nodes.get('examContainer').innerHTML, /7\/25分/);
  assert.match(nodes.get('examContainer').innerHTML, /你的答案：B/);
  assert.match(nodes.get('examContainer').innerHTML, /正确答案：服务端未返回/);
  assert.doesNotMatch(nodes.get('examContainer').innerHTML, /旧答案|999/);
});

test('调用者未 await 和重复 render 均保持加载锁，加载成功后保留作答', async () => {
  const { ctx, state, nodes, draft, posts } = remoteExamEnvironment();
  const authFetch = ctx.AppAuth.authFetch;
  let release;
  ctx.AppAuth.authFetch = async (url, options) => {
    if (url.includes('questions')) await new Promise(resolve => { release = resolve; });
    return authFetch(url, options);
  };
  const loading = ctx.renderExam();
  await ctx.renderExam();
  ctx.selectOption(1, 'A'); await ctx.submitExam();
  assert.match(nodes.get('examContainer').innerHTML, /正在加载/);
  assert.equal(posts().length, 0);
  release(); await loading;
  assert.deepEqual(draft().answers, {});
  ctx.selectOption(1, 'B');
  await ctx.renderExam();
  assert.deepEqual(draft().answers, { 1: 'B' });
  assert.equal(state.requests.length, 2);
});

for (const failure of ['network', 'http', 'json', 'detail', 'empty', 'unknown', 'duplicate', 'options']) {
  test('加载异常禁止作答提交且可重试：' + failure, async () => {
    const { ctx, nodes, posts, draft } = remoteExamEnvironment();
    const authFetch = ctx.AppAuth.authFetch;
    ctx.AppAuth.authFetch = async (url, options) => {
      if (failure === 'network') throw new Error('断网');
      if (failure === 'http') return response({}, false);
      if (failure === 'json') return { ok: true, json: async () => { throw new Error('JSON解析失败'); } };
      if (url.includes('detail')) return failure === 'detail' ? response({ exam: {} }) : examDetail(ctx);
      const q = { id: 1, question_type: 1, score: 20, content: '实际题干', options: ['A'] };
      return response({ questions: failure === 'empty' ? [] : failure === 'unknown' ? [{ ...q, question_type: 2 }]
        : failure === 'duplicate' ? [q, q] : [{ ...q, options: {} }] });
    };
    await ctx.renderExam();
    assert.match(nodes.get('examContainer').innerHTML, /重试加载/);
    assert.doesNotMatch(nodes.get('examContainer').innerHTML, /onclick="submitExam/);
    ctx.selectOption(1, 'A'); await ctx.submitExam();
    assert.equal(posts().length, 0);
    ctx.AppAuth.authFetch = authFetch;
    await ctx.renderExam();
    assert.deepEqual(draft().answers, {});
    ctx.selectOption(1, 'B'); await ctx.submitExam();
    assert.equal(posts().length, 1);
  });
}

test('未知结果快照断网刷新直接重放，确认后重考加载新题并使用新幂等键', async () => {
  const { ctx, state, nodes, reload, posts, draft } = remoteExamEnvironment();
  await ctx.renderExam(); ctx.selectOption(1, 'B');
  state.failPost = true; await ctx.submitExam();
  const original = posts()[0].options;
  state.questions = [{ id: 9, question_type: 1, score: 30, content: '新版试题', options: ['新版选项'] }];
  state.failGet = true;
  const getCount = state.requests.length;
  reload(); await ctx.renderExam();
  assert.equal(state.requests.length, getCount);
  assert.match(nodes.get('examContainer').innerHTML, /实际题干/);
  assert.doesNotMatch(nodes.get('examContainer').innerHTML, /新版试题/);
  ctx.selectOption(1, 'A'); await ctx.restartExam();
  await ctx.submitExam();
  assert.equal(posts()[1].options.body, original.body);
  assert.deepEqual(posts()[1].options.headers, original.headers);
  state.failPost = false; await ctx.submitExam();
  assert.equal(posts()[2].options.body, original.body);
  assert.equal(draft().result.exam_record_id, 91);
  await ctx.restartExam();
  assert.match(nodes.get('examContainer').innerHTML, /重试加载/);
  state.failGet = false; await ctx.renderExam();
  assert.match(nodes.get('examContainer').innerHTML, /新版试题/);
  assert.deepEqual(draft().answers, {});
  ctx.selectOption(9, 'A'); await ctx.submitExam();
  assert.notEqual(posts()[3].options.headers['Idempotency-Key'], original.headers['Idempotency-Key']);
  assert.deepEqual(JSON.parse(posts()[3].options.body).answers, { 9: 'A' });
});

for (const change of ['content', 'options', 'score', 'type', 'id', 'count']) {
  test('作答后刷新保留试卷快照，提交时拦截题库变化：' + change, async () => {
    const { ctx, state, reload, posts, draft } = remoteExamEnvironment();
    const alerts = []; ctx.alert = message => alerts.push(message);
    await ctx.renderExam(); ctx.selectOption(1, 'B');
    if (change === 'content') state.questions[0].content = '变更题干';
    if (change === 'options') state.questions[0].options = ['改过选项'];
    if (change === 'score') state.questions[0].score = 50;
    if (change === 'type') state.questions[0].question_type = 4;
    if (change === 'id') state.questions[0].id = 8;
    if (change === 'count') state.questions.push({ ...state.questions[0], id: 8 });
    reload(); await ctx.renderExam(); await ctx.submitExam();
    assert.equal(posts().length, 0);
    assert.match(alerts.at(-1), /试卷已变更/);
    assert.equal(draft().paper.questions[0].content, '实际题干');
    assert.deepEqual(draft().answers, { 1: 'B' });
    assert.equal(draft().pending, null);
  });
}

test('旧版无试卷快照的未知请求断网仍重放原正文，结果不引用静态题目答案', async () => {
  const { ctx, state, storage, nodes, posts } = remoteExamEnvironment();
  const pending = { key: 'legacy-key', createdAt: Date.now(), body: { exam_id: 1, source_exam_id: 1, selected_exam_id: 1,
    answers: { 1: 'B' }, time_spent: 42 } };
  storage.set('training-exam:77:1', JSON.stringify({ answers: { 1: 'B' }, pending, result: null, startedAt: 1 }));
  state.failGet = true;
  await ctx.renderExam();
  assert.match(nodes.get('examContainer').innerHTML, /重试提交/);
  assert.equal(state.requests.length, 0);
  await ctx.submitExam();
  assert.equal(posts()[0].options.body, JSON.stringify(pending.body));
  assert.equal(posts()[0].options.headers['Idempotency-Key'], 'legacy-key');
  assert.match(nodes.get('examContainer').innerHTML, /题目 1/);
  assert.doesNotMatch(nodes.get('examContainer').innerHTML, /静态题干|旧答案/);
});

test('判断和文本作答跨刷新保留，文本部分分采用服务端结果', async () => {
  const { ctx, state, nodes, reload, posts } = remoteExamEnvironment();
  state.questions = [{ id: 2, question_type: 3, content: '判断题', score: 10, options: [] },
    { id: 3, question_type: 4, content: '问答题', score: 30, options: [] }];
  state.result.question_results = [{ question_id: 2, question_type: 3, earned_score: 10, max_score: 10, is_correct: true },
    { question_id: 3, question_type: 4, earned_score: 12, max_score: 30, is_correct: true }];
  await ctx.renderExam(); ctx.selectJudge(2, 'V');
  nodes.get('answer-3').value = '用户的实际回答'; ctx.saveTextAnswer(3);
  reload(); await ctx.renderExam();
  assert.equal(nodes.get('answer-3').value, '用户的实际回答');
  await ctx.submitExam();
  assert.deepEqual(JSON.parse(posts()[0].options.body).answers, { 2: 'V', 3: '用户的实际回答' });
  assert.match(nodes.get('examContainer').innerHTML, /12\/30分/);
  assert.match(nodes.get('examContainer').innerHTML, /你的答案：正确/);
  assert.match(nodes.get('examContainer').innerHTML, /用户的实际回答/);
});

test('请求快照存储失败禁止 POST，存储恢复后继续提交原答案', async () => {
  const { ctx, posts, draft } = remoteExamEnvironment();
  await ctx.renderExam(); ctx.selectOption(1, 'B');
  const setItem = ctx.sessionStorage.setItem;
  ctx.sessionStorage.setItem = () => { throw new Error('storage full'); };
  await ctx.submitExam();
  assert.equal(posts().length, 0);
  assert.equal(draft().pending, null);
  ctx.sessionStorage.setItem = setItem;
  ctx.selectOption(1, 'A');
  await ctx.submitExam();
  assert.deepEqual(JSON.parse(posts()[0].options.body).answers, { 1: 'B' });
  assert.equal(draft().result.exam_record_id, 91);
});

test('旧版请求明确回滚后恢复加载，旧答案不套用新题目', async () => {
  const { ctx, storage, nodes, posts, draft } = remoteExamEnvironment();
  const pending = { key: 'legacy-rollback', createdAt: Date.now(), body: { exam_id: 1, source_exam_id: 1, selected_exam_id: 1,
    answers: { 1: 'B' }, time_spent: 42 } };
  storage.set('training-exam:77:1', JSON.stringify({ answers: { 1: 'B' }, pending, result: null, startedAt: 1 }));
  const authFetch = ctx.AppAuth.authFetch;
  let rolledBack = false;
  ctx.AppAuth.authFetch = async (url, options) => {
    if (options?.method === 'POST' && !rolledBack) {
      rolledBack = true;
      return response({}, false, 'rolled_back');
    }
    return authFetch(url, options);
  };
  await ctx.renderExam(); await ctx.submitExam();
  assert.match(nodes.get('examContainer').innerHTML, /实际题干/);
  assert.deepEqual(draft().answers, {});
  assert.equal(draft().pending, null);
  ctx.selectOption(1, 'A'); await ctx.submitExam();
  assert.equal(posts().length, 1);
  assert.notEqual(posts()[0].options.headers['Idempotency-Key'], pending.key);
  assert.deepEqual(JSON.parse(posts()[0].options.body).answers, { 1: 'A' });
});
