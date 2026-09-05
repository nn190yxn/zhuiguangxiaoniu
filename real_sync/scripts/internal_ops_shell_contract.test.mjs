import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = relativePath => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const auth = read('../internal-auth.js');
const styles = read('../assets/internal-ops.css');
const policy = read('../制度标准/index.html');
const knowledge = read('../knowledge/index.html');
const learning = read('../learning/index.html');
const drill = read('../mobile/drill.html');
const mine = read('../mobile/mine.html');

test('运营中枢页面壳由认证脚本安全加载且保持唯一', () => {
  assert.match(auth, /OPS_STYLES_PATH = '\/assets\/internal-ops\.css\?v=20260904-complex-pages'/);
  assert.match(auth, /link\.id = 'mcOpsStyles'/);
  assert.match(auth, /shell\.id = 'mcOpsShell'/);
  assert.match(auth, /document\.getElementById\('mcOpsShell'\)/);
  assert.match(auth, /document\.body\.insertBefore\(shell, document\.body\.firstChild\)/);
  assert.doesNotMatch(auth, /document\.body\.innerHTML\s*=/);
  assert.match(auth, /DOMContentLoaded/);
});

test('运营中枢提供六中心、身份和权限导航合同', () => {
  for (const label of ['制度中心', '知识中心', '演练中心', '学习中心', '业务工具', '我的']) {
    assert.match(auth, new RegExp(`label: '${label}'`));
  }
  assert.match(auth, /data-mc-ops-name/);
  assert.match(auth, /data-mc-ops-meta/);
  assert.match(auth, /adminOnly: true/);
  assert.match(auth, /canShowAdminDashboardEntry\(user\)/);
});

test('首页与业务工具根据 hash 形成唯一当前态', () => {
  assert.match(auth, /currentHash !== '#tools'/);
  assert.match(auth, /currentHash === '#tools'/);
  assert.match(auth, /link\.setAttribute\('aria-current', 'page'\)/);
  assert.match(auth, /window\.addEventListener\('hashchange'/);
});

test('方案 D 令牌和桌面移动布局均已定义', () => {
  for (const token of ['--mc-ops-graphite', '--mc-ops-canvas', '--mc-ops-panel', '--mc-ops-signal', '--mc-ops-line']) {
    assert.match(styles, new RegExp(token));
  }
  assert.match(styles, /--mc-ops-sidebar: 218px/);
  assert.match(styles, /body\.mc-ops-interface[\s\S]*padding-left: var\(--mc-ops-sidebar\)/);
  assert.match(styles, /@media \(max-width: 980px\)[\s\S]*padding-left: 0 !important/);
  assert.match(styles, /\.mc-ops-interface \.mobile-shell-nav[\s\S]*left: 0 !important/);
  assert.match(styles, /:focus-visible/);
});

test('内部路径映射到稳定且唯一的中心页面类', () => {
  for (const [path, center] of [
    ['/制度标准/', 'policy'],
    ['/knowledge/', 'knowledge'],
    ['/mobile/drill', 'drill'],
    ['/learning/', 'learning'],
    ['/mobile/mine', 'mine'],
    ['/admin/', 'admin'],
  ]) {
    assert.match(auth, new RegExp(`currentPath\\.startsWith\\('${path.replaceAll('/', '\\/')}`));
    assert.match(auth, new RegExp(`return '${center}'`));
  }
  assert.match(auth, /document\.body\.classList\.remove\(`mc-ops-center--\$\{code\}`\)/);
  assert.match(auth, /document\.body\.classList\.add\('mc-ops-center-page', `mc-ops-center--\$\{center\}`\)/);
  assert.match(auth, /document\.body\.dataset\.mcOpsCenter = center/);
});

test('制度、知识、演练、学习和我的共享内容表面', () => {
  for (const center of ['policy', 'knowledge', 'drill', 'learning', 'mine']) {
    assert.match(styles, new RegExp(`\\.mc-ops-center--${center}`));
  }
  for (const surface of ['.sidebar', '.toolbar', '.doc-card', '.knowledge-card', '.card', '.stat', '.profile-card', '.menu-section', '.row']) {
    assert.match(styles, new RegExp(surface.replace('.', '\\.')));
  }
  assert.match(styles, /\.mode\.active/);
  assert.match(styles, /\.favorite\.active/);
  assert.match(styles, /\.mc-ops-center-page :is\(\.search input,[\s\S]*textarea\)/);
});

test('代表页面加载共享资源且移动业务底栏保持存在', () => {
  for (const page of [policy, knowledge, learning, drill, mine]) {
    assert.match(page, /src="\/internal-auth\.js\?v=20260904-fixed-nav"/);
  }
  assert.match(drill, /class="nav mobile-shell-nav"/);
  assert.match(mine, /class="bottom-nav mobile-shell-nav"/);
  assert.match(styles, /\.mc-ops-center-page \.mobile-shell-nav[\s\S]*display: flex !important/);
  assert.match(styles, /@media \(max-width: 980px\)[\s\S]*\.mc-ops-interface \.mobile-shell-nav[\s\S]*left: 0 !important/);
});
