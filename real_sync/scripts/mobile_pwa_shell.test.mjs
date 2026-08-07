import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = fileURLToPath(new URL('..', import.meta.url));
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const manifest = JSON.parse(read('manifest.webmanifest'));
const worker = read('sw.js');
const pwa = read('js/mobile-pwa.js');
const entryPage = read('mobile/index.html');
const entryScript = read('js/mobile-entry.js');
const shell = read('css/mobile-shell.css');

function loadMobileEntry() {
  const context = { URL };
  context.globalThis = context;
  vm.runInNewContext(entryScript, context);
  return context.MobileEntry;
}

test('手机 H5 提供可安装的独立窗口应用清单', () => {
  assert.equal(manifest.name, '追光小牛员工端');
  assert.equal(manifest.id, '/mobile/');
  assert.equal(manifest.start_url, '/mobile/?source=pwa');
  assert.equal(manifest.scope, '/mobile/');
  assert.equal(manifest.display, 'standalone');
  assert.equal(manifest.orientation, undefined);
  assert.equal(manifest.theme_color, '#ff6b35');
  assert.deepEqual(manifest.icons.map((icon) => icon.sizes), ['192x192', '512x512']);
  assert.ok(manifest.icons.every((icon) => icon.src === '/assets/pwa/icon.svg'));
  assert.match(read('assets/pwa/icon.svg'), /viewBox="0 0 512 512"/);
});

test('统一启动路由只接受同源 mobile 白名单目标', () => {
  const entry = loadMobileEntry();
  const origin = 'https://supercalf.com';

  assert.equal(entry.resolveTarget('', origin), '/mobile/mine.html');
  assert.equal(entry.resolveTarget('/mobile/drill.html?assignment=7#latest', origin), '/mobile/drill.html?assignment=7#latest');
  assert.equal(entry.resolveTarget('https://supercalf.com/mobile/workload-v2.html', origin), '/mobile/workload-v2.html');
  assert.equal(entry.resolveTarget('https://attacker.example/mobile/drill.html', origin), '/mobile/mine.html');
  assert.equal(entry.resolveTarget('/admin/dashboard.html', origin), '/mobile/mine.html');
  assert.equal(entry.createEntryUrl('/mobile/learning.html', origin), '/mobile/?redirect=%2Fmobile%2Flearning.html');
  assert.match(entryPage, /MobileEntry\.resolveTarget/);
  assert.match(entryPage, /window\.location\.replace\(target\)/);
});

test('核心手机入口共享 PWA 元数据与初始化脚本', () => {
  for (const page of ['mobile/mine.html', 'mobile/workload-v2.html', 'mobile/drill.html', 'mobile/learning.html']) {
    const source = read(page);
    assert.match(source, /rel="manifest" href="\/manifest\.webmanifest"/);
    assert.match(source, /apple-mobile-web-app-capable" content="yes"/);
    assert.match(source, /viewport-fit=cover/);
    assert.match(source, /src="\/js\/mobile-pwa\.js"/);
    assert.match(source, /href="\/css\/mobile-shell\.css"/);
    assert.match(source, /mobile-shell-nav/);
  }
});

test('共享应用壳固化手机、平板和桌面布局边界', () => {
  assert.match(shell, /@media \(max-width: 767px\)/);
  assert.match(shell, /@media \(min-width: 768px\) and \(max-width: 1023px\)/);
  assert.match(shell, /@media \(min-width: 1024px\)/);
  assert.match(shell, /min-width: 44px/);
  assert.match(shell, /min-height: 44px/);
  assert.match(shell, /env\(safe-area-inset-bottom\)/);
  assert.match(shell, /focus-visible/);
  assert.match(shell, /grid-template-columns: repeat\(2/);
});

test('浏览器兼容入口和登录默认路径收口到受控 mobile 路由', () => {
  const internal = read('internal.html');
  const login = read('mobile/login.html');

  assert.match(internal, /src="\/js\/mobile-entry\.js"/);
  assert.match(internal, /MobileEntry\.createEntryUrl/);
  assert.match(internal, /redirect=%2Fmobile%2F/);
  assert.match(login, /getQueryParam\('redirect'\) \|\| '\/mobile\/'/);
});

test('PWA 初始化覆盖安装、独立窗口、更新与网络状态基础能力', () => {
  assert.match(pwa, /beforeinstallprompt/);
  assert.match(pwa, /navigator\.standalone/);
  assert.match(pwa, /display-mode: standalone/);
  assert.match(pwa, /zgxn_pwa_install_dismissed_until/);
  assert.match(pwa, /7 \* 24 \* 60 \* 60 \* 1000/);
  assert.match(pwa, /serviceWorker\.register\('\/sw\.js'\)/);
  assert.match(pwa, /SKIP_WAITING/);
  assert.match(pwa, /GET_VERSION/);
  assert.match(pwa, /zgxn_pwa_update_recovery/);
  assert.match(pwa, /AppAuth\.ensureAccessToken/);
  assert.match(pwa, /pwa:network-restored/);
  assert.match(pwa, /window\.addEventListener\('online'/);
  assert.match(pwa, /window\.addEventListener\('offline'/);
  assert.match(pwa, /Chrome 右上角/);
});

test('Service Worker 仅缓存批准应用外壳并提供专用离线页', () => {
  for (const asset of ['/mobile/index.html', '/mobile/mine.html', '/mobile/workload-v2.html', '/mobile/drill.html', '/mobile/learning.html', '/mobile/offline.html', '/manifest.webmanifest', '/js/mobile-entry.js', '/js/mobile-pwa.js', '/js/api-client.js?v=4', '/js/draft-store.js', '/css/mobile-shell.css']) {
    assert.match(worker, new RegExp(asset.replace(/[.?]/g, '\\$&')));
  }
  assert.match(worker, /APP_VERSION = '11'/);
  assert.match(worker, /APPROVED_PATHS/);
  assert.match(worker, /SENSITIVE_PREFIXES/);
  assert.match(worker, /caches\.match\(OFFLINE_URL\)/);
  assert.match(worker, /CACHE_PREFIX = 'zgxn-pwa-shell-'/);
  assert.match(worker, /CACHE_NAME = 'zgxn-pwa-shell-v11'/);
  assert.doesNotMatch(worker, /install[\s\S]{0,180}skipWaiting/);
});

void root;
