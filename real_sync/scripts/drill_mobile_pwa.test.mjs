import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

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
  for (const endpoint of ['home.php', 'catalog.php', 'assignments.php', 'attempts.php', 'turns.php', 'attempt-status.php', 'results.php', 'learning.php', 'progress.php', 'audio-assets.php', 'audio-chunks.php', 'turns/finalize.php']) assert.match(drill, new RegExp('/api/drill/v2/'+endpoint.replace(/[/.]/g, '\\$&')));
  assert.match(drill, /ApiClient\.get/);
  assert.match(drill, /ApiClient\.post[\s\S]*Idempotency-Key/);
  assert.doesNotMatch(drill, /fetch\('\/api\/drill/);
});

test('演练流程涵盖学习、文本与语音、恢复、评分、反馈和认证详情', () => {
  for (const marker of ['准备学习', '文本', 'getUserMedia', 'MediaRecorder', 'audio-chunks.php', 'audio-access.php', 'playLatestAudio', 'online', 'pollStatus', '四段反馈', '学习推荐', '认证详情', '历史与认证']) assert.match(drill, new RegExp(marker));
  assert.match(drill, /create_self_practice/);
  assert.match(drill, /startSelfPractice/);
  assert.match(drill, /attempt\.attempt_id/);
  assert.match(drill, /@media\(max-width:380px\)/);
  assert.match(drill, /@media\(min-width:700px\)/);
});

test('Service Worker 缓存应用外壳，所有写请求直连网络', () => {
  assert.match(sw, /CACHE_NAME = 'zgxn-pwa-shell-v\d+'/);
  assert.match(sw, /cache\.addAll\(SHELL\)/);
  assert.match(sw, /if \(request\.method !== 'GET'\) return/);
  assert.match(sw, /url\.pathname\.startsWith\('\/api\/'\)/);
  assert.match(drill, /src="\/js\/mobile-pwa\.js"/);
  assert.match(mobilePwa, /addEventListener\('updatefound'/);
});
