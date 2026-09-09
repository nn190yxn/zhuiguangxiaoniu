import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

function editor() {
  const nodes = new Map();
  const node = (id) => {
    if (!nodes.has(id)) nodes.set(id, { value: 'fixture', textContent: id === 'startButton' ? '创建并解析' : '', files: [], dataset: {}, style: {}, classList: { add() {}, toggle() {} }, focus() { this.focused = true; }, addEventListener(name, handler) { this[name] = handler; } });
    return nodes.get(id);
  };
  const calls = [];
  const uploads = [];
  const context = {
    document: { getElementById: node, querySelectorAll: () => [], body: { classList: { toggle() {} } }, createElement: () => ({ textContent: '', innerHTML: '' }) },
    window: { addEventListener() {}, requirePageAuth() {}, AppAuth: { authHeaders: () => ({ Authorization: 'Bearer fixture' }), authFetch: async (url, options) => {
      calls.push({ url, options, body: options?.body ? JSON.parse(options.body) : null });
      return { ok: true, json: async () => ({ code: 0, data: url.includes('create.php') ? { id: 12 } : {} }) };
    } } },
    crypto: { randomUUID: (() => { let key = 0; return () => `fixture-key-${++key}`; })() },
    FormData: class { append() {} },
    XMLHttpRequest: class {
      constructor() { this.upload = {}; this.headers = {}; uploads.push(this); }
      open() {} addEventListener() {} setRequestHeader(key, value) { this.headers[key] = value; }
      send() { queueMicrotask(() => this.onerror()); }
    },
    AbortController, URL, URLSearchParams, location: { origin: 'https://example.test' }, history: { replaceState() {} },
    setTimeout: () => 1, clearTimeout() {}, console,
  };
  const source = readFileSync(new URL('../js/lesson-submission.js', import.meta.url), 'utf8');
  runInNewContext(source.replace('}());', 'window.test = { state, fillEditor, readContent, renderPhases, uploadFile, createAndParse, decideSuggestion, run, optimize }; }());'), context);
  return { ...context.window.test, context, node, calls, uploads };
}

test('生产服务在隔离 SQLite 中完成建议、版本与审核恢复', () => {
  const result = spawnSync('php', ['scripts/lesson_remediation_service.test.php'], { cwd: new URL('..', import.meta.url), encoding: 'utf8', timeout: 20000 });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.match(result.stdout, /PASS: DTO/);
  assert.match(result.stdout, /PASS: parse automatically/);
});

test('编辑器仅修改反思后保存，activity 建议保持 ignored 且实际修改阶段会重新 pending', () => {
  const invoke = (mode, input) => {
    const result = spawnSync('php', ['scripts/lesson_remediation_service.test.php', mode], { cwd: new URL('..', import.meta.url), input: input && JSON.stringify(input), encoding: 'utf8', timeout: 20000 });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout);
  };
  const original = invoke('--editor-fixture');
  const app = editor();
  app.fillEditor(original);
  const rows = original.phases.map((phase, index) => ({ dataset: { index: String(index) }, querySelector: (selector) => ({ value: selector.includes('"name"') ? phase.name : selector.includes('"duration"') ? String(phase.duration_minutes) : phase.activity }) }));
  const reflection = { dataset: { path: 'reflection.athletic' }, value: '仅补充课后反思' };
  app.context.document.querySelectorAll = (selector) => selector === '.phase' ? rows : selector === '[data-path]' ? [reflection] : [];
  const saved = JSON.parse(JSON.stringify(app.readContent()));
  assert.deepEqual(saved.phases, original.phases);
  assert.equal(Object.hasOwn(saved.phases[0], 'content'), false);
  const result = invoke('--editor-save', saved);
  assert.equal(result.unchanged_decision, 'ignored');
  assert.equal(result.changed_decision, 'pending');
  assert.equal(result.field_path, 'phases.0.activity');
});

test('JWT 上传保留进度、认证头且失败重试复用教案', async () => {
  const app = editor();
  app.state.authenticatedAuthorName = '测试教练';
  app.node('sourceFile').files = [{ name: 'lesson.docx', size: 1024 }];
  await assert.rejects(app.createAndParse(), /网络/);
  await assert.rejects(app.createAndParse(), /网络/);
  assert.equal(app.calls.filter(({ url }) => url.includes('create.php')).length, 1);
  assert.equal(app.state.uploadAttempt.id, 12);
  assert.equal(app.uploads[1].headers.Authorization, 'Bearer fixture');
  assert.equal(app.uploads[1].headers['Content-Type'], undefined);
  app.uploads[1].upload.onprogress({ lengthComputable: true, loaded: 5, total: 10 });
  assert.equal(app.node('uploadProgress').value, 50);
});

test('上传过期身份有明确提示', async () => {
  const app = editor();
  app.context.XMLHttpRequest.prototype.send = function () { this.status = 401; this.responseText = '{}'; queueMicrotask(() => this.onload()); };
  await assert.rejects(app.uploadFile('/upload', {}, 12), /登录已过期/);
});

test('采纳仅追加 DTO 正文，双击只有一次请求', async () => {
  const app = editor();
  app.state.submission = { id: 12, status_version: 2 };
  app.state.version = { id: 3 };
  app.state.content = { safety: { physical: '保持距离', psychological: '鼓励' } };
  app.state.suggestions = [{ id: 4, version_id: 3, decision: 'pending', field_path: 'safety.physical', apply_content: '增设软垫', reason: '理由不可混入', issue: '问题不可混入' }];
  const card = { dataset: { suggestionId: '4' } };
  await Promise.all([app.run(() => app.decideSuggestion(card, 'accepted')), app.run(() => app.decideSuggestion(card, 'accepted'))]);
  const decisions = app.calls.filter(({ url }) => url.includes('suggestion-decision'));
  assert.equal(decisions.length, 1);
  assert.deepEqual(decisions[0].body.content, { safety: { physical: '保持距离\n增设软垫', psychological: '鼓励' } });
});

test('数组建议去重且旧版本建议拒绝应用', async () => {
  const app = editor();
  app.state.submission = { id: 1, status_version: 1 };
  app.state.version = { id: 3 };
  app.state.content = { equipment: ['软垫'] };
  app.state.suggestions = [{ id: 4, version_id: 2, decision: 'pending', field_path: 'equipment', apply_content: '软垫、标志桶' }];
  await assert.rejects(app.decideSuggestion({ dataset: { suggestionId: '4' } }, 'accepted'), /建议已变化/);
  app.state.suggestions[0].version_id = 3;
  await app.run(() => app.decideSuggestion({ dataset: { suggestionId: '4' } }, 'accepted'));
  assert.deepEqual(app.calls[0].body.content.equipment, ['软垫', '标志桶']);
});

test('延迟刷新响应不会覆盖新版本', async () => {
  const app = editor();
  app.state.submission = { id: 1 };
  app.state.version = { id: 3 };
  app.state.suggestions = [{ id: 9 }];
  let resolve;
  app.context.window.AppAuth.authFetch = () => new Promise((done) => { resolve = done; });
  const refreshing = app.optimize();
  app.state.version = { id: 4 };
  resolve({ ok: true, json: async () => ({ code: 0, data: { version_id: 3, suggestions: [] } }) });
  await refreshing;
  assert.equal(app.state.suggestions[0].id, 9);
});

function readyEditor() {
  const app = editor();
  app.state.authenticatedAuthorName = '测试教练';
  app.node('sourceFile').files = [{ name: 'lesson.docx', size: 1024 }];
  return app;
}

test('创建必填逐项提示并聚焦，附近状态持久且按钮恢复', async () => {
  for (const [id, message] of [['createStore', '门店名称'], ['createCourse', '课程线'], ['createAge', '年龄段'], ['createStage', '班级阶段'], ['createDate', '上课日期'], ['createTitle', '教案标题']]) {
    const app = readyEditor();
    app.node(id).value = ' ';
    await app.run(app.createAndParse);
    assert.match(app.node('uploadProgressLabel').textContent, new RegExp(message));
    assert.equal(app.node(id).focused, true);
    assert.equal(app.node('startButton').disabled, false);
    assert.equal(app.node('startButton').textContent, '创建并解析');
    assert.equal(app.calls.length, 0);
  }
});

test('缺失登录身份及适配器默认姓名明确提示，认证回调异常可见', async () => {
  for (const name of ['', '员工账号']) {
    const app = readyEditor();
    app.state.authenticatedAuthorName = name;
    await app.run(app.createAndParse);
    assert.match(app.node('uploadProgressLabel').textContent, /登录身份/);
    assert.equal(app.calls.length, 0);
  }
  const app = editor();
  app.context.window.requirePageAuth = () => { throw new Error('adapter unavailable'); };
  await new Promise(setImmediate);
  assert.match(app.node('uploadProgressLabel').textContent, /登录身份加载失败/);
});

test('文件缺失、类型及大小在创建前检查，50MB 边界允许', async () => {
  for (const [file, message] of [[null, /请选择原始/], [{ name: 'lesson.pdf', size: 1 }, /XLSX/], [{ name: 'lesson.doc', size: 0 }, /50MB/], [{ name: 'lesson.docx', size: 50 * 1024 * 1024 + 1 }, /50MB/]]) {
    const app = readyEditor();
    app.node('sourceFile').files = file ? [file] : [];
    await app.run(app.createAndParse);
    assert.match(app.node('uploadProgressLabel').textContent, message);
    assert.equal(app.node('sourceFile').focused, true);
    assert.equal(app.calls.length, 0);
  }
  const app = readyEditor();
  app.node('sourceFile').files[0].size = 50 * 1024 * 1024;
  await app.run(app.createAndParse);
  assert.equal(app.uploads.length, 1);
});

test('真实点击即刻禁用且双击只创建一次，上传失败可重试', async () => {
  const app = readyEditor();
  let resolve;
  let calls = 0;
  app.context.document.body.classList.toggle = () => assert.fail('创建流程不得全局禁用页面');
  app.context.window.AppAuth.authFetch = () => { calls++; return new Promise(done => { resolve = done; }); };
  app.node('startButton').click();
  app.node('startButton').click();
  assert.equal(app.node('startButton').disabled, true);
  assert.match(app.node('startButton').textContent, /创建中/);
  assert.match(app.node('uploadProgressLabel').textContent, /正在创建/);
  assert.equal(calls, 1);
  resolve({ ok: true, json: async () => ({ code: 0, data: { id: 12 } }) });
  await new Promise(setImmediate);
  assert.equal(app.node('startButton').disabled, false);
  assert.match(app.node('uploadProgressLabel').textContent, /上传失败.*重试原教案/);
  assert.equal(app.state.uploadAttempt.id, 12);
});

test('创建明确失败后修改元数据使用新内容及新 key', async () => {
  const app = readyEditor();
  const requests = [];
  app.context.window.AppAuth.authFetch = async (url, options) => {
    requests.push(options);
    return { ok: false, status: 422, json: async () => ({ code: 422, message: '上课日期无效' }) };
  };
  await app.run(app.createAndParse);
  assert.match(app.node('uploadProgressLabel').textContent, /上课日期无效/);
  assert.equal(app.state.uploadAttempt, null);
  app.node('createTitle').value = '修改后的标题';
  await app.run(app.createAndParse);
  assert.equal(JSON.parse(requests[1].body).title, '修改后的标题');
  assert.notEqual(requests[0].headers['Idempotency-Key'], requests[1].headers['Idempotency-Key']);
});

test('创建结果未知时修改元数据仍重放原请求和 key', async () => {
  for (const response of [null, { ok: false, status: 409, json: async () => ({ code: 409, message: '处理中' }) }, { ok: false, status: 502, json: async () => { throw new Error('invalid JSON'); } }]) {
    const app = readyEditor();
    const requests = [];
    app.context.window.AppAuth.authFetch = async (url, options) => { requests.push(options); if (!response) throw new TypeError('Failed to fetch'); return response; };
    await app.run(app.createAndParse);
    app.node('createTitle').value = '修改后的标题';
    await app.run(app.createAndParse);
    assert.equal(requests[0].body, requests[1].body);
    assert.equal(requests[0].headers['Idempotency-Key'], requests[1].headers['Idempotency-Key']);
  }
});

test('创建结果未知后登录过期仍保留原 key，避免恢复登录后重复创建', async () => {
  const app = readyEditor();
  app.context.window.AppAuth.authFetch = async () => { throw new TypeError('Failed to fetch'); };
  await app.run(app.createAndParse);
  const key = app.state.uploadAttempt.key;
  app.context.window.AppAuth.authFetch = async () => ({ ok: false, status: 401, json: async () => ({ code: 401, message: 'expired' }) });
  await app.run(app.createAndParse);
  assert.equal(app.state.uploadAttempt.key, key);
  assert.match(app.node('uploadProgressLabel').textContent, /登录已过期/);
});

test('解析等待与超时可见，保留认证 options、原教案和已上传文件以重试', async () => {
  const app = readyEditor();
  const timers = [];
  app.context.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  app.context.XMLHttpRequest.prototype.send = function () { this.status = 200; this.responseText = JSON.stringify({ code: 0, data: { id: 34 } }); queueMicrotask(() => this.onload()); };
  const originalFetch = app.context.window.AppAuth.authFetch;
  let parseOptions;
  app.context.window.AppAuth.authFetch = (url, options) => {
    if (!url.includes('parse.php')) return originalFetch(url, options);
    parseOptions = options;
    return new Promise(() => {});
  };
  const pending = app.run(app.createAndParse);
  await new Promise(setImmediate);
  assert.match(app.node('startButton').textContent, /解析中/);
  assert.match(app.node('uploadProgressLabel').textContent, /已上传.*正在解析/);
  assert.equal(app.node('startButton').disabled, true);
  assert.equal(parseOptions.method, 'POST');
  assert.equal(parseOptions.headers['Content-Type'], 'application/json');
  assert.deepEqual(JSON.parse(parseOptions.body), { submission_id: 12, source_file_id: 34 });
  timers.find(timer => timer.ms === 180000).fn();
  await pending;
  assert.equal(parseOptions.signal.aborted, true);
  assert.match(app.node('uploadProgressLabel').textContent, /超时.*重试原教案/);
  assert.equal(app.node('startButton').disabled, false);
  app.context.window.AppAuth.authFetch = originalFetch;
  await app.run(app.createAndParse);
  assert.equal(app.calls.filter(call => call.url.includes('create.php')).length, 1);
  assert.equal(app.uploads.length, 1);
  assert.equal(app.state.uploadAttempt, null);
  assert.match(app.node('uploadProgressLabel').textContent, /解析完成/);
});

test('认证刷新等待及响应体读取均有界，刷新异常转为登录提示', async () => {
  for (const stalled of [() => new Promise(() => {}), async () => ({ ok: true, json: () => new Promise(() => {}) })]) {
    const app = readyEditor();
    let expire;
    app.context.setTimeout = (fn, ms) => { if (ms === 30000) expire = fn; return 1; };
    app.context.window.AppAuth.authFetch = stalled;
    const pending = app.run(app.createAndParse);
    expire();
    await pending;
    assert.match(app.node('uploadProgressLabel').textContent, /超时/);
    assert.ok(app.state.uploadAttempt.key);
    assert.equal(app.node('startButton').disabled, false);
  }
  const app = readyEditor();
  app.context.window.AppAuth.authFetch = async () => { throw new Error('refresh_unavailable'); };
  await app.run(app.createAndParse);
  assert.match(app.node('uploadProgressLabel').textContent, /登录状态无法刷新/);
});
