import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const manifest = JSON.parse(read('manifest.webmanifest'));
const worker = read('sw.js');
const pwa = read('js/mobile-pwa.js');

test('手机 H5 提供可安装的独立窗口应用清单', () => {
  assert.equal(manifest.name, '追光小牛员工端');
  assert.equal(manifest.start_url, '/mobile/mine.html?source=pwa');
  assert.equal(manifest.scope, '/mobile/');
  assert.equal(manifest.display, 'standalone');
  assert.equal(manifest.theme_color, '#ff6b35');
  assert.deepEqual(manifest.icons.map((icon) => icon.sizes), ['192x192', '512x512']);
  assert.ok(manifest.icons.every((icon) => icon.src === '/assets/pwa/icon.svg'));
  assert.match(read('assets/pwa/icon.svg'), /viewBox="0 0 512 512"/);
});

test('核心手机入口共享 PWA 元数据与初始化脚本', () => {
  for (const page of ['mobile/mine.html', 'mobile/workload-v2.html', 'mobile/drill.html', 'mobile/learning.html']) {
    const source = read(page);
    assert.match(source, /rel="manifest" href="\/manifest\.webmanifest"/);
    assert.match(source, /apple-mobile-web-app-capable" content="yes"/);
    assert.match(source, /viewport-fit=cover/);
    assert.match(source, /src="\/js\/mobile-pwa\.js"/);
  }
});

test('PWA 初始化覆盖安装、独立窗口、更新与网络状态基础能力', () => {
  assert.match(pwa, /beforeinstallprompt/);
  assert.match(pwa, /navigator\.standalone/);
  assert.match(pwa, /display-mode: standalone/);
  assert.match(pwa, /zgxn_pwa_install_dismissed_until/);
  assert.match(pwa, /7 \* 24 \* 60 \* 60 \* 1000/);
  assert.match(pwa, /serviceWorker\.register\('\/sw\.js'\)/);
  assert.match(pwa, /SKIP_WAITING/);
  assert.match(pwa, /Chrome 右上角/);
});

test('Service Worker 缓存应用外壳并保留 API 网络路径', () => {
  for (const asset of ['/mobile/mine.html', '/mobile/workload-v2.html', '/mobile/drill.html', '/mobile/learning.html', '/manifest.webmanifest', '/js/mobile-pwa.js']) {
    assert.match(worker, new RegExp(asset.replace(/[.?]/g, '\\$&')));
  }
  assert.match(worker, /url\.pathname\.startsWith\('\/api\/'\)/);
  assert.match(worker, /caches\.match\('\/mobile\/mine\.html'\)/);
  assert.match(worker, /zgxn-pwa-shell-v2/);
});

void root;
