import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(new URL('..', import.meta.url).pathname);
const read = (file) => readFileSync(resolve(root, file), 'utf8');
const app = JSON.parse(read('mini-program/app.json'));

assert.deepEqual(app.tabBar.list.map(item => item.text), ['工作量', '演练', '数据中心', '我的']);
assert(app.pages.includes('pages/data-center/index'));
assert(app.pages.includes('pages/exam/list'));
assert(app.tabBar.list.some(item => item.pagePath === 'pages/mine/mine'));

for (const route of ['mini-program/pages/data-center/index', 'mini-program/pages/exam/list', 'mini-program/pages/drill/qa/qa']) {
  assert(existsSync(resolve(root, `${route}.js`)));
  assert(existsSync(resolve(root, `${route}.wxml`)));
  assert(existsSync(resolve(root, `${route}.json`)));
  assert(existsSync(resolve(root, `${route}.wxss`)));
}

const drillList = read('mini-program/pages/drill/list/list.js');
assert.match(drillList, /pages\/drill\/qa\/qa/);
assert.match(drillList, /mode=flow/);
assert.match(drillList, /pages\/exam\/list/);
const freeChat = read('mini-program/pages/drill/free-chat/free-chat.js');
assert.match(freeChat, /sales_qa/);
assert.match(freeChat, /sales_flow/);
const adapter = read('api/drill/v2/services/DrillAiAdapter.php');
assert.match(adapter, /核心关键词覆盖、核心概念覆盖、答案准确性、答案完整性/);
assert.match(adapter, /需求挖掘、方案匹配、异议处理、推进成交/);

const contracts = read('scripts/check_miniprogram_contracts.mjs');
assert.match(contracts, /TAB_ROUTE_DRIFT/);
assert.match(contracts, /NATIVE_NETWORK_OUTSIDE_API_CLIENT/);

console.log('小程序体验升级静态契约测试通过');
