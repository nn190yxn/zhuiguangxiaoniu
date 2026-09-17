import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const html = readFileSync(new URL('../ai-drill.html', import.meta.url), 'utf8');

function inlineScript() {
  for (const match of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    if (!/\bsrc\s*=/i.test(match[1] || '')) return match[2];
  }
  throw new Error('ai-drill.html 缺少内联脚本');
}

function createElement(id) {
  return {
    id,
    className: '',
    innerHTML: '',
    textContent: '',
    value: '',
    disabled: false,
    isConnected: true,
    dataset: {},
    style: {},
    children: [],
    handlers: {},
    scrollTop: 0,
    scrollHeight: 0,
    classList: { add() {}, remove() {}, contains: () => false },
    addEventListener(type, handler) { this.handlers[type] = handler; },
    appendChild(child) { this.children.push(child); return child; },
    insertBefore(child) { this.children.push(child); return child; },
    remove() { this.isConnected = false; },
    focus() {},
    closest: () => null,
    querySelectorAll: () => [],
    setAttribute() {},
  };
}

function createHarness(replies) {
  const elements = new Map();
  const getElement = id => {
    if (!elements.has(id)) elements.set(id, createElement(id));
    return elements.get(id);
  };
  const pending = [...replies];
  const calls = [];
  const context = {
    console,
    Date,
    JSON,
    Math,
    alert() {},
    confirm: () => true,
    setTimeout,
    clearTimeout,
    async fetch(url, options) {
      calls.push({ url, body: JSON.parse(options.body) });
      const payload = pending.length > 1 ? pending.shift() : pending[0];
      return { json: async () => payload };
    },
    document: {
      getElementById: getElement,
      createElement: () => createElement(''),
      querySelectorAll: () => [],
      addEventListener() {},
      body: createElement('body'),
    },
  };
  context.window = context;
  vm.runInNewContext(inlineScript(), context, { filename: 'ai-drill.html' });
  return { context, getElement, calls };
}

const startReply = {
  code: 0,
  data: { session_id: 7, system_prompt: 'sp', total_questions: 5, welcome: '欢迎', first_question: '第一题' },
};
const continueReply = {
  code: 0,
  data: { type: 'continue', score: 80, feedback: '不错', next_question: '第二题', progress: { current: 2 } },
};

async function beginDrill(harness) {
  const grid = harness.getElement('scenarioGrid');
  grid.handlers.click({
    target: { closest: () => ({ dataset: { scenario: 'qa' }, classList: { add() {}, remove() {} } }) },
  });
  await harness.context.startDrill();
}

test('AI 对练每轮成功回复后发送按钮恢复可用', async () => {
  const harness = createHarness([startReply, continueReply]);
  await beginDrill(harness);
  const sendBtn = harness.getElement('sendBtn');
  const chatInput = harness.getElement('chatInput');

  chatInput.value = '第一轮回答';
  await harness.context.sendMessage();
  assert.equal(sendBtn.disabled, false, '第一轮回复成功后发送按钮应恢复可用');

  chatInput.value = '第二轮回答';
  await harness.context.sendMessage();
  assert.equal(sendBtn.disabled, false, '第二轮回复成功后发送按钮仍应可用');

  const chatCalls = harness.calls.filter(call => call.body.action === 'chat');
  assert.equal(chatCalls.length, 2, '两轮回复都应真正发出请求');
});

test('AI 对练结束后保持输入与发送按钮关闭', async () => {
  const endReply = {
    code: 0,
    data: { type: 'end', score: 90, feedback: '很好', summary: { final_message: '结束', avg_score: 90, level_name: '优秀' } },
  };
  const harness = createHarness([startReply, endReply]);
  await beginDrill(harness);
  const sendBtn = harness.getElement('sendBtn');

  harness.getElement('chatInput').value = '最后一轮';
  await harness.context.sendMessage();
  assert.equal(sendBtn.disabled, true, '对练结束后发送按钮应保持关闭');
  assert.equal(harness.getElement('chatInput').disabled, true, '对练结束后输入框应保持关闭');
});

test('AI 对练发送失败时恢复发送按钮并提示', async () => {
  const harness = createHarness([startReply, { code: 500, message: '服务异常' }]);
  await beginDrill(harness);
  const sendBtn = harness.getElement('sendBtn');

  harness.getElement('chatInput').value = '会失败的回答';
  await harness.context.sendMessage();
  assert.equal(sendBtn.disabled, false, '发送失败后应恢复发送按钮');
});
