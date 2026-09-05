import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = relativePath => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const internalAuth = read('../internal-auth.js');
const identityService = read('../api/auth/IdentityContextService.php');
const internalPage = read('../internal.html');
const minePage = read('../mobile/mine.html');
const lessonSubmission = read('../js/lesson-submission.js');
const appAuth = read('../js/app-auth.js');

function throwingStorage() {
  return {
    getItem() { throw new Error('storage unavailable'); },
    setItem() { throw new Error('storage unavailable'); },
    removeItem() { throw new Error('storage unavailable'); },
  };
}

function loadInternalAuth(location = {}) {
  const context = {
    document: {
      cookie: '',
      head: {},
      readyState: 'complete',
      querySelector: () => null,
      addEventListener() {},
    },
    localStorage: throwingStorage(),
    sessionStorage: throwingStorage(),
    location: {
      hostname: 'supercalf.com',
      protocol: 'https:',
      pathname: '/mobile/login.html',
      search: '',
      hash: '',
      href: 'https://supercalf.com/mobile/login.html',
      ...location,
    },
    setTimeout,
    clearTimeout,
  };
  context.window = context;
  vm.runInNewContext(internalAuth, context, { filename: 'internal-auth.js' });
  return context;
}

function attachAuthNoticeDom(context) {
  let notice = null;
  const handlers = {};
  context.document.querySelector = selector => selector === '.mc-auth-notice' ? notice : null;
  context.document.createElement = tagName => {
    const element = {
      tagName,
      className: '',
      innerHTML: '',
      textContent: '',
      querySelector(selector) {
        return {
          addEventListener(eventName, handler) {
            handlers[`${selector}:${eventName}`] = handler;
          },
        };
      },
    };
    return element;
  };
  context.document.head = { appendChild() {} };
  context.document.body = { appendChild(element) { notice = element; } };
  return { getNotice: () => notice, handlers };
}

test('共享身份适配器统一姓名、角色、门店和权限字段', () => {
  const { InternalAuth } = loadInternalAuth();
  const identity = InternalAuth.adaptUserIdentity({
    display_name: '账号显示名',
    role: 'manager',
    role_name: '店长',
    store_name: '观山湖店',
    permissions: { can_view_store: true },
    staff: { name: '员工姓名', role: 'manager' },
  });

  assert.deepEqual(JSON.parse(JSON.stringify(identity)), {
    name: '员工姓名',
    role: 'manager',
    roleLabel: '店长',
    storeName: '观山湖店',
    isAdmin: false,
    isHq: false,
    isManager: true,
    permissions: { can_view_store: true },
    capabilities: [],
    meta: '观山湖店 · 店长',
  });
  assert.equal(InternalAuth.adaptUserIdentity({ nickname: '昵称' }).name, '昵称');
  assert.equal(InternalAuth.adaptUserIdentity({ username: '13800000000' }).name, '13800000000');
});

test('四类角色生成稳定导航集合和管理入口', () => {
  const { InternalAuth } = loadInternalAuth();
  const baseLabels = ['内网首页', '制度中心', '知识中心', '演练中心', '学习中心', '业务工具', '我的'];
  const cases = [
    [{ staff_name: '普通员工', role: 'coach' }, baseLabels, false],
    [{ staff_name: '店长', role: 'manager', is_manager: true }, baseLabels, false],
    [{ staff_name: '教学主管', role: 'teaching_supervisor' }, baseLabels, false],
    [{ staff_name: '总部管理员', role: 'operation', is_hq: true }, [...baseLabels, '管理中心'], true],
  ];

  for (const [user, expectedLabels, expectedAdmin] of cases) {
    assert.deepEqual(
      Array.from(InternalAuth.getVisibleNavigationItems(user), item => item.label),
      expectedLabels,
    );
    assert.equal(InternalAuth.canShowAdminDashboardEntry(user), expectedAdmin);
  }
});

test('显式导航权限仅展示共享契约允许的员工入口', () => {
  const { InternalAuth } = loadInternalAuth();
  const visible = InternalAuth.getVisibleNavigationItems({
    role: 'coach',
    permissions: { allowed_navigation: ['home', 'knowledge', 'mine'] },
  });
  assert.deepEqual(Array.from(visible, item => item.code), ['home', 'knowledge', 'mine']);
});

test('认证成功始终用本次用户刷新页面壳，跳过标志只控制自动调用', () => {
  assert.match(internalAuth, /if \(result\.user\) \{\s*unifyTopNav\(result\.user\);\s*\}/);
  assert.doesNotMatch(internalAuth, /if \(!shouldSkipAutoInternalAuth && result\.user\)/);
  assert.match(internalAuth, /if \(shouldSkipAutoInternalAuth\) \{\s*return;\s*\}\s*\n\s*requirePageAuth\(\)/);
});

test('身份接口提供页面壳需要的显示字段', () => {
  for (const field of ['staff_name', 'role_name', 'store_id', 'store_name']) {
    assert.match(identityService, new RegExp(`'${field}'\\s*=>`));
  }
});

test('页面级身份和管理入口复用共享适配器与权限函数', () => {
  assert.match(internalPage, /window\.InternalAuth\.adaptUserIdentity\(user\)/);
  assert.match(internalPage, /window\.InternalAuth\.canShowAdminDashboardEntry\(user\)/);
  assert.match(minePage, /window\.InternalAuth\.adaptUserIdentity\(user\)/);
  assert.match(minePage, /window\.InternalAuth\.canShowAdminDashboardEntry\(user\)/);
  assert.match(lessonSubmission, /window\.InternalAuth\.adaptUserIdentity\(user\)/);
});

test('无本地存储时仍生成保留完整返回路由的登录地址', () => {
  const context = loadInternalAuth();
  context.location.pathname = '/knowledge/detail.html';
  context.location.search = '?id=42';
  context.location.hash = '#relations';
  const loginUrl = context.InternalAuth.getLoginUrl();
  assert.match(loginUrl, /^\/mobile\/login\.html\?/);
  assert.equal(new URL(`https://supercalf.com${loginUrl}`).searchParams.get('redirect'), '/knowledge/detail.html?id=42#relations');
});

test('会话运行时复用统一登录地址并保留 pathname、search 和 hash', () => {
  assert.match(appAuth, /window\.InternalAuth\.getLoginUrl/);
  assert.match(appAuth, /window\.location\.pathname\+window\.location\.search\+window\.location\.hash/);
  for (const reason of ['network_error', 'missing_token', 'invalid_token']) {
    assert.ok(internalAuth.includes(reason));
  }
  assert.match(internalAuth, /需要重新确认登录状态/);
});

test('存储不可用且缺少凭证时显示统一重新登录提示', async () => {
  const context = loadInternalAuth();
  context.location.pathname = '/lesson-submission.html';
  context.location.search = '?id=8';
  const dom = attachAuthNoticeDom(context);

  const result = await context.requirePageAuth({ maxRetries: 1 });
  assert.equal(result, null);
  assert.equal(dom.getNotice().className, 'mc-auth-notice');
  assert.match(dom.getNotice().innerHTML, /未找到登录凭证/);

  dom.handlers['.mc-auth-login-btn:click']();
  assert.equal(
    new URL(`https://supercalf.com${context.location.href}`).searchParams.get('redirect'),
    '/lesson-submission.html?id=8',
  );
});

test('失效 token 使用标准提示并保留完整返回路由', async () => {
  const context = loadInternalAuth();
  context.location.pathname = '/knowledge/detail.html';
  context.location.search = '?id=42';
  context.location.hash = '#relations';
  context.localStorage = {
    getItem: key => key === 'jwt_token' ? 'expired-token' : null,
    setItem() {},
    removeItem() {},
  };
  context.fetch = async () => ({
    ok: false,
    status: 401,
    text: async () => JSON.stringify({ code: 401, message: 'expired' }),
  });
  const dom = attachAuthNoticeDom(context);

  const result = await context.requirePageAuth({ maxRetries: 1 });
  assert.equal(result, null);
  assert.match(dom.getNotice().innerHTML, /登录凭证已失效/);
  dom.handlers['.mc-auth-login-btn:click']();
  assert.equal(
    new URL(`https://supercalf.com${context.location.href}`).searchParams.get('redirect'),
    '/knowledge/detail.html?id=42#relations',
  );
});
