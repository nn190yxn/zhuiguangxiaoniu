import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const projectRoot = new URL('../', import.meta.url).pathname;
const read = relativePath => readFileSync(join(projectRoot, relativePath), 'utf8');

test('桌面与移动核心页面声明响应式布局', () => {
  const desktopPages = [
    ['knowledge/index.html', /@media\s*\(max-width:\s*760px\)/],
    ['lesson-library.html', /@media\s*\(max-width:\s*640px\)/],
  ];
  const mobilePages = [
    ['mobile/workload-v2.html', /@media\s*\(max-width:\s*640px\)/],
    ['mobile/drill.html', /@media\s*\(max-width:\s*380px\)/],
  ];
  for (const [path, pattern] of [...desktopPages, ...mobilePages]) assert.match(read(path), pattern, path);
});

test('核心页面声明加载、空结果和错误结果状态', () => {
  const knowledgePage = read('knowledge/index.html');
  const lessonScript = read('js/lesson-library.js');
  assert.match(knowledgePage, /id="knowledgeList"/);
  assert.match(knowledgePage, /class="state"/);
  assert.match(lessonScript, /status\('正在加载正式教案\.\.\.', 'loading'\)/);
  assert.match(lessonScript, /status\('加载失败：'/);
  assert.match(lessonScript, /status\('当前条件下暂无正式教案', 'empty'\)/);
});

test('弱网恢复、请求重试和缓存更新路径保持可验证', () => {
  const apiClient = read('js/api-client.js');
  const pwaRuntime = read('js/mobile-pwa.js');
  const knowledge = read('knowledge/knowledge.js');
  const workload = read('mobile/workload-v2.html');
  assert.match(apiClient, /请求超时，请稍后重试/);
  assert.match(apiClient, /网络请求失败，请检查网络后重试/);
  assert.match(apiClient, /If-None-Match/);
  assert.match(apiClient, /resp\.status===304/);
  assert.match(apiClient, /__conflictRetried/);
  assert.match(pwaRuntime, /pwa:network-restored/);
  assert.match(pwaRuntime, /pwa:session-restored/);
  assert.match(knowledge, /loadPublishedStaticIndex/);
  assert.match(workload, /上传超时，请换网络或压缩图片后重试/);
  assert.match(workload, /retryEvidence/);
});
