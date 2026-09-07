import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

function editor() {
  const nodes = new Map();
  const node = (id) => {
    if (!nodes.has(id)) nodes.set(id, { value: 'fixture', files: [], dataset: {}, style: {}, classList: { add() {}, toggle() {} }, addEventListener() {} });
    return nodes.get(id);
  };
  const calls = [];
  const uploads = [];
  const context = {
    document: { getElementById: node, querySelectorAll: () => [], body: { classList: { toggle() {} } }, createElement: () => ({ textContent: '', innerHTML: '' }) },
    window: { addEventListener() {}, requirePageAuth() {}, AppAuth: { authHeaders: () => ({ Authorization: 'Bearer fixture' }), authFetch: async (url, options) => {
      calls.push({ url, body: options?.body ? JSON.parse(options.body) : null });
      return { ok: true, json: async () => ({ code: 0, data: url.includes('create.php') ? { id: 12 } : {} }) };
    } } },
    crypto: { randomUUID: () => 'fixture-key' },
    FormData: class { append() {} },
    XMLHttpRequest: class {
      constructor() { this.upload = {}; this.headers = {}; uploads.push(this); }
      open() {} addEventListener() {} setRequestHeader(key, value) { this.headers[key] = value; }
      send() { queueMicrotask(() => this.onerror()); }
    },
    URL, URLSearchParams, location: { origin: 'https://example.test' },
    setTimeout: () => 1, clearTimeout() {}, console,
  };
  const source = readFileSync(new URL('../js/lesson-submission.js', import.meta.url), 'utf8');
  runInNewContext(source.replace('}());', 'window.test = { state, fillEditor, readContent, renderPhases, uploadFile, createAndParse, decideSuggestion, run, optimize, bindKnowledgeLinks }; }());'), context);
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
  app.node('sourceFile').files = [{ name: 'lesson.docx' }];
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

test('历史知识引用同时携带两种版本参数', () => {
  const app = editor();
  app.state.suggestions = [{ id: 4, knowledge_item_id: 7, knowledge_version_id: 101 }];
  const link = { href: 'https://example.test/knowledge/detail.html?id=7', closest: () => ({ dataset: { suggestionId: 4 } }) };
  app.context.document.querySelectorAll = () => [link];
  app.bindKnowledgeLinks();
  assert.equal(link.href, '/knowledge/detail.html?id=7&knowledge_version_id=101&version_id=101');
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
