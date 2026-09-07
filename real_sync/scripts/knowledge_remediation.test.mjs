import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import vm from 'node:vm';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const flush = () => new Promise(resolve => setImmediate(resolve));
function page(script = 'knowledge/knowledge.js', search = '') {
  const nodes = new Map();
  function element(id) {
    if (!nodes.has(id)) nodes.set(id, {
      innerHTML: '', textContent: '', value: '', hidden: false, options: [], dataset: {},
      classList: { toggle() {} }, listeners: {},
      addEventListener(name, fn) { this.listeners[name] = fn; },
      querySelectorAll() { return []; },
      insertAdjacentHTML(_, html) { this.innerHTML += html; },
    });
    return nodes.get(id);
  }
  const primary = ['professional', 'sales'].map(value => Object.assign(element(value), { dataset: { primaryCategory: value } }));
  const requests = [];
  const window = {
    location: { hostname: 'localhost', pathname: '/knowledge/', search },
    history: { replaceState(_a, _b, url) { window.url = url; } },
    addEventListener(_event, init) { window.init = init; },
    authFetch(url) { return new Promise((resolve, reject) => requests.push({ url, resolve, reject })); },
  };
  vm.runInNewContext(readFileSync(new URL(script, root), 'utf8'), {
    window, document: { getElementById: element, querySelectorAll: s => s === '[data-primary-category]' ? primary : [] }, URLSearchParams,
  });
  window.init();
  const respond = (index, ids, total = ids.length, pageSize = 2) => requests[index].resolve({ ok: true, json: async () => ({ code: 0, data: { list: ids.map(id => ({ id, title: `card-${id}` })), total, page_size: pageSize } }) });
  return { element, requests, respond, window };
}

test('新筛选覆盖旧请求，旧 finally 不解锁加载更多，分页去重且失败可重试', async () => {
  const p = page();
  p.element('searchInput').value = '新搜索';
  p.element('searchForm').listeners.submit({ preventDefault() {} });
  assert.equal(p.requests.length, 2);
  p.respond(0, [99], 4);
  await flush();
  assert.equal(p.element('loadMore').disabled, true);
  assert.doesNotMatch(p.element('knowledgeList').innerHTML, /card-99/);
  p.respond(1, [1, 2], 5);
  await flush();
  p.element('loadMore').listeners.click();
  p.element('loadMore').listeners.click();
  assert.equal(p.requests.length, 3);
  p.requests[2].reject(new Error('offline'));
  await flush();
  p.element('loadMore').listeners.click();
  assert.equal(new URL(p.requests[3].url, 'https://local').searchParams.get('page'), '2');
  p.respond(3, [2, 3], 5);
  await flush();
  assert.equal((p.element('knowledgeList').innerHTML.match(/card-2</g) || []).length, 1);
  p.element('loadMore').listeners.click();
  p.element('ageCode').value = 'age_3_4';
  p.element('ageCode').listeners.change();
  p.respond(5, [7]);
  p.respond(4, [88], 5);
  await flush();
  assert.match(p.element('knowledgeList').innerHTML, /card-7/);
  assert.doesNotMatch(p.element('knowledgeList').innerHTML, /card-88|card-1</);
  assert.match(p.window.url, /age_code=age_3_4/);
  assert.equal(p.element('loadWrap').hidden, true);
});

test('分类使用规范代码，切换主分类清除子分类', async () => {
  const p = page('knowledge/knowledge.js', '?topic=child_development');
  assert.match(p.requests[0].url, /subcategory_code=child_development/);
  p.element('sales').listeners.click();
  assert.doesNotMatch(p.requests[1].url, /subcategory_code/);
  assert.match(p.element('topicList').innerHTML, /data-topic="needs_analysis"/);
  p.respond(1, []);
  p.respond(0, [1]);
  await flush();
  assert.match(p.element('knowledgeList').innerHTML, /暂无匹配内容/);
});

test('详情传递固定版本，展示历史提示与版本缺失状态', async () => {
  const p = page('knowledge/detail.js', '?id=1&version_id=11');
  assert.match(p.requests[0].url, /id=1&version_id=11/);
  p.requests[0].resolve({ ok: true, json: async () => ({ code: 0, data: { item: { title: 'V1正文', content: '旧内容', version_no: 1, is_historical_version: true } } }) });
  await flush();
  assert.match(p.element('content').innerHTML, /历史引用版本 V1/);
  assert.match(p.element('content').innerHTML, /旧内容/);
  const missing = page('knowledge/detail.js', '?id=1&version_id=12');
  missing.requests[0].resolve({ ok: false, status: 404, json: async () => ({ code: 1 }) });
  await flush();
  assert.match(missing.element('content').innerHTML, /引用的知识版本不可用/);
  assert.equal(page('knowledge/detail.js', '?id=1&version_id=0').requests.length, 0);
  const current = page('knowledge/detail.js', '?id=1');
  assert.doesNotMatch(current.requests[0].url, /version_id/);
});

test('隔离数据库验证分类、年龄同义词和历史版本归属', () => {
  const result = spawnSync('php', ['scripts/knowledge_remediation.test.php'], { cwd: root, encoding: 'utf8', timeout: 20000 });
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /PASS/);
});
