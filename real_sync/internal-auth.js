(() => {
  const LOGIN_PATH = '/mobile/login.html';
  const LOGIN_VERSION = '20260620h6';
  const APP_AUTH_PATH = '/js/app-auth.js?v=20260904-preview-auth';
  const redirectKey = 'mc_internal_auth_redirect_once';
  const path = window.location.pathname || '/';
  const isPreviewHost = /\.monkeycode-ai\.online$/.test(window.location.hostname);
  const shouldSkipAutoInternalAuth = !!window.__SKIP_AUTO_INTERNAL_AUTH__ || isPreviewHost;
  const previewUser = {
    staff_name: '预览员工',
    role: 'admin',
    store_name: '预览环境',
    is_admin: true
  };
  let appAuthLoadPromise = null;

  function readCookie(name) {
    const prefix = `${name}=`;
    const parts = document.cookie ? document.cookie.split('; ') : [];
    for (const part of parts) {
      if (part.indexOf(prefix) === 0) {
        return decodeURIComponent(part.slice(prefix.length));
      }
    }
    return '';
  }

  function writeCookie(name, value, maxAgeSeconds) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=${maxAgeSeconds}; SameSite=Lax${secure}`;
  }

  function clearCookie(name) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=; Path=/; Max-Age=0; SameSite=Lax${secure}`;
  }

  function readStoredValue(keys) {
    const keyList = Array.isArray(keys) ? keys : [keys];
    for (const key of keyList) {
      try {
        const localValue = localStorage.getItem(key);
        if (localValue) {
          return localValue;
        }
      } catch (error) {}
      try {
        const sessionValue = sessionStorage.getItem(key);
        if (sessionValue) {
          return sessionValue;
        }
      } catch (error) {}
      const cookieValue = readCookie(key);
      if (cookieValue) {
        return cookieValue;
      }
    }
    return '';
  }

  function writeStoredValue(key, value, options = {}) {
    let stored = false;
    try {
      localStorage.setItem(key, value);
      stored = true;
    } catch (error) {}
    try {
      sessionStorage.setItem(key, value);
      stored = true;
    } catch (error) {}
    if (options.cookie) {
      try {
        writeCookie(key, value, options.maxAgeSeconds || 604800);
        stored = true;
      } catch (error) {}
    }
    return stored;
  }

  function removeStoredValue(keys) {
    const keyList = Array.isArray(keys) ? keys : [keys];
    for (const key of keyList) {
      try { localStorage.removeItem(key); } catch (error) {}
      try { sessionStorage.removeItem(key); } catch (error) {}
      clearCookie(key);
    }
  }
  const OPS_STYLES_PATH = '/assets/internal-ops.css?v=20260904-complex-pages';
  const UNIFIED_NAV_ITEMS = [
    { code: 'home', label: '内网首页', shortLabel: '首', href: '/internal.html' },
    { code: 'policy', label: '制度中心', shortLabel: '制', href: '/制度标准/' },
    { code: 'knowledge', label: '知识中心', shortLabel: '知', href: '/knowledge/' },
    { code: 'drill', label: '演练中心', shortLabel: '练', href: '/mobile/drill.html' },
    { code: 'learning', label: '学习中心', shortLabel: '学', href: '/learning/' },
    { code: 'tools', label: '业务工具', shortLabel: '工', href: '/internal.html#tools' },
    { code: 'mine', label: '我的', shortLabel: '我', href: '/mobile/mine.html', className: 'staff-link' },
    { code: 'admin', label: '管理中心', shortLabel: '管', href: '/admin/dashboard.html', adminOnly: true }
  ];
  const ROLE_LABELS = {
    admin: '管理员',
    ceo: '总经理',
    operation: '总部运营',
    finance: '财务',
    manager: '店长',
    teaching_supervisor: '教学主管',
    supervisor: '督导',
    coach: '教练',
    sales: '顾问',
    consultant: '顾问',
    newbie: '新员工',
    staff: '员工'
  };

  function navIsCurrent(targetHref, currentPath, currentHash = '') {
    if (targetHref === '/internal.html') {
      return (currentPath === '/internal.html' || currentPath === '/internal.html/') && currentHash !== '#tools';
    }
    if (targetHref === '/internal.html#tools') {
      return (currentPath === '/internal.html' || currentPath === '/internal.html/') && currentHash === '#tools';
    }
    if (targetHref === '/admin/dashboard.html') {
      return currentPath.startsWith('/admin/');
    }
    if (targetHref.endsWith('/')) {
      return currentPath.startsWith(targetHref);
    }
    return currentPath === targetHref;
  }

  function firstIdentityValue(values, fallback) {
    const value = values.find((candidate) => candidate !== null && candidate !== undefined && String(candidate).trim() !== '');
    return value === undefined ? fallback : String(value).trim();
  }

  function adaptUserIdentity(user) {
    const resolvedUser = user || getStoredUser() || {};
    const staff = resolvedUser.staff && typeof resolvedUser.staff === 'object' ? resolvedUser.staff : {};
    const role = firstIdentityValue([
      resolvedUser.role,
      staff.role,
      resolvedUser.staff_context?.role
    ], 'staff').toLowerCase();
    const roleLabel = firstIdentityValue([
      resolvedUser.role_name,
      staff.role_name,
      ROLE_LABELS[role]
    ], role || '内网成员');
    const storeName = firstIdentityValue([
      resolvedUser.store_name,
      staff.store_name,
      resolvedUser.staff_context?.store_name
    ], '追光小牛');
    const name = firstIdentityValue([
      resolvedUser.staff_name,
      staff.name,
      resolvedUser.name,
      resolvedUser.display_name,
      resolvedUser.nickname,
      resolvedUser.username
    ], '员工账号');
    const permissions = resolvedUser.permissions && typeof resolvedUser.permissions === 'object'
      ? resolvedUser.permissions
      : {};
    const capabilities = Array.isArray(resolvedUser.capabilities) ? [...resolvedUser.capabilities] : [];
    return {
      name,
      role,
      roleLabel,
      storeName,
      isAdmin: !!resolvedUser.is_admin,
      isHq: !!resolvedUser.is_hq,
      isManager: !!resolvedUser.is_manager || role === 'manager',
      permissions,
      capabilities,
      meta: `${storeName} · ${roleLabel}`
    };
  }

  function canShowAdminDashboardEntry(user) {
    const identity = adaptUserIdentity(user);
    return identity.isHq || identity.isAdmin || identity.permissions.can_view_hq === true
      || ['admin', 'ceo', 'operation', 'finance'].includes(identity.role);
  }

  function getVisibleNavigationItems(user) {
    const identity = adaptUserIdentity(user);
    const allowedNavigation = Array.isArray(identity.permissions.allowed_navigation)
      ? identity.permissions.allowed_navigation.map((code) => String(code))
      : null;
    return UNIFIED_NAV_ITEMS.filter((item) => {
      if (allowedNavigation && !allowedNavigation.includes(item.code)) return false;
      return !item.adminOnly || canShowAdminDashboardEntry(user);
    });
  }

  function getOpsCenter(currentPath, currentHash = '') {
    if ((currentPath === '/internal.html' || currentPath === '/internal.html/') && currentHash === '#tools') {
      return 'tools';
    }
    if (currentPath === '/internal.html' || currentPath === '/internal.html/') {
      return 'home';
    }
    if (currentPath.startsWith('/制度标准/') || currentPath.startsWith('/mobile/policy')) {
      return 'policy';
    }
    if (currentPath.startsWith('/knowledge/') || currentPath.startsWith('/mobile/knowledge') || currentPath.startsWith('/action-library/')) {
      return 'knowledge';
    }
    if (currentPath.startsWith('/mobile/drill') || currentPath.startsWith('/ai-drill') || currentPath.startsWith('/skill-review')) {
      return 'drill';
    }
    if (currentPath.startsWith('/learning/') || currentPath.startsWith('/mobile/learning') || currentPath.startsWith('/training')) {
      return 'learning';
    }
    if (currentPath.startsWith('/mobile/mine')) {
      return 'mine';
    }
    if (currentPath.startsWith('/admin/')) {
      return 'admin';
    }
    return 'tools';
  }

  function applyOpsCenterClass() {
    const center = getOpsCenter(window.location.pathname || '/', window.location.hash || '');
    for (const code of ['home', 'policy', 'knowledge', 'drill', 'learning', 'tools', 'mine', 'admin']) {
      document.body.classList.remove(`mc-ops-center--${code}`);
    }
    document.body.classList.add('mc-ops-center-page', `mc-ops-center--${center}`);
    document.body.dataset.mcOpsCenter = center;
  }

  function ensureOpsStyles() {
    if (!document.head || typeof document.createElement !== 'function') {
      return;
    }
    const existing = typeof document.getElementById === 'function'
      ? document.getElementById('mcOpsStyles')
      : null;
    if (existing) {
      return;
    }
    const link = document.createElement('link');
    link.id = 'mcOpsStyles';
    link.rel = 'stylesheet';
    link.href = OPS_STYLES_PATH;
    document.head.appendChild(link);
  }

  function setElementText(element, value) {
    if (element) {
      element.textContent = value;
    }
  }

  function updateOpsNavigation(nav, user) {
    const currentPath = window.location.pathname || '/';
    const currentHash = window.location.hash || '';
    while (nav.firstChild) {
      nav.removeChild(nav.firstChild);
    }
    for (const item of getVisibleNavigationItems(user)) {
      const link = document.createElement('a');
      link.href = item.href;
      link.textContent = item.label;
      link.dataset.shortLabel = item.shortLabel;
      if (navIsCurrent(item.href, currentPath, currentHash)) {
        link.classList.add('current');
        link.setAttribute('aria-current', 'page');
      }
      if (item.className) {
        link.classList.add(item.className);
      }
      nav.appendChild(link);
    }
  }

  function buildOpsShell() {
    const shell = document.createElement('aside');
    shell.id = 'mcOpsShell';
    shell.className = 'mc-ops-shell';
    shell.setAttribute('aria-label', '员工运营中枢');

    const brand = document.createElement('a');
    brand.className = 'mc-ops-shell__brand';
    brand.href = '/internal.html';
    brand.innerHTML = '<span class="mc-ops-shell__brand-mark">MC</span><span class="mc-ops-shell__brand-copy"><strong>追光小牛</strong><span>Operations Hub</span></span>';

    const search = document.createElement('a');
    search.className = 'mc-ops-shell__search';
    search.href = '/search.html';
    search.textContent = '搜索内网内容';

    const nav = document.createElement('nav');
    nav.className = 'mc-persistent-staff-nav';
    nav.setAttribute('aria-label', '员工中心导航');

    const identity = document.createElement('div');
    identity.className = 'mc-ops-shell__identity';
    identity.innerHTML = '<div class="mc-ops-shell__identity-label">当前身份</div><strong data-mc-ops-name>员工账号</strong><span data-mc-ops-meta>追光小牛 · 内网成员</span>';

    shell.appendChild(brand);
    shell.appendChild(search);
    shell.appendChild(nav);
    shell.appendChild(identity);
    return shell;
  }

  function unifyTopNav(user = null) {
    if (!document.body || typeof document.createElement !== 'function') {
      return null;
    }
    ensureOpsStyles();
    document.body.classList.add('mc-ops-interface');
    applyOpsCenterClass();
    let shell = typeof document.getElementById === 'function' ? document.getElementById('mcOpsShell') : null;
    if (!shell) {
      shell = buildOpsShell();
      document.body.insertBefore(shell, document.body.firstChild);
    }
    const nav = shell.querySelector('.mc-persistent-staff-nav');
    updateOpsNavigation(nav, user);
    const identity = adaptUserIdentity(user);
    setElementText(shell.querySelector('[data-mc-ops-name]'), identity.name);
    setElementText(shell.querySelector('[data-mc-ops-meta]'), identity.meta);
    return shell;
  }

  function scheduleOpsShell(user = null) {
    if (document.body) {
      return unifyTopNav(user);
    }
    if (typeof document.addEventListener === 'function') {
      document.addEventListener('DOMContentLoaded', () => unifyTopNav(user), { once: true });
    }
    return null;
  }

  function getToken() {
    if (window.AppAuth && typeof window.AppAuth.getToken === 'function') {
      return window.AppAuth.getToken();
    }
    return readStoredValue(['jwt_token', 'token']);
  }

  function ensureAppAuthAvailable() {
    if (window.AppAuth && typeof window.AppAuth.ensureAccessToken === 'function') {
      return Promise.resolve(window.AppAuth);
    }
    if (!readCookie('platform_csrf')) {
      return Promise.resolve(null);
    }
    if (appAuthLoadPromise) {
      return appAuthLoadPromise;
    }

    appAuthLoadPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = APP_AUTH_PATH;
      script.async = false;
      script.dataset.internalAuthLoader = 'true';
      script.onload = () => {
        if (window.AppAuth && typeof window.AppAuth.ensureAccessToken === 'function') {
          resolve(window.AppAuth);
          return;
        }
        reject(new Error('app_auth_unavailable'));
      };
      script.onerror = () => reject(new Error('app_auth_load_failed'));
      document.head.appendChild(script);
    });
    return appAuthLoadPromise;
  }

  function getStoredUser() {
    try {
      const userInfo = readStoredValue('user_info');
      return userInfo ? JSON.parse(userInfo) : null;
    } catch (error) {
      return null;
    }
  }

  function clearAuth() {
    if (window.AppAuth && typeof window.AppAuth.clearAuth === 'function') {
      window.AppAuth.clearAuth('internal-auth-clear');
    }
    removeStoredValue(['jwt_token', 'token', 'user_info']);
  }

  function getRedirectPath() {
    return `${window.location.pathname}${window.location.search || ''}${window.location.hash || ''}`;
  }

  function getLoginUrl() {
    return `${LOGIN_PATH}?v=${encodeURIComponent(LOGIN_VERSION)}&redirect=${encodeURIComponent(getRedirectPath())}`;
  }

  function showAuthNotice(message, loginUrl, onLoginClick) {
    if (document.querySelector('.mc-auth-notice')) {
      return;
    }

    const notice = document.createElement('div');
    notice.className = 'mc-auth-notice';
    notice.innerHTML = [
      '<div class="mc-auth-notice-card">',
      '<strong>需要重新确认登录状态</strong>',
      '<p>' + message + '</p>',
      '<div class="mc-auth-notice-actions">',
      '<button type="button" class="mc-auth-login-btn">前往手机号登录</button>',
      '<button type="button" class="mc-auth-retry-btn">重新检查</button>',
      '</div>',
      '</div>'
    ].join('');

    const style = document.createElement('style');
    style.textContent = '.mc-auth-notice{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(31,26,23,.28);backdrop-filter:blur(8px)}.mc-auth-notice-card{width:min(420px,100%);border-radius:20px;background:#fff;padding:24px;box-shadow:0 18px 50px rgba(0,0,0,.18);font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;color:#1f1a17}.mc-auth-notice-card strong{display:block;font-size:18px}.mc-auth-notice-card p{margin:10px 0 0;color:#6b625c;line-height:1.7}.mc-auth-notice-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.mc-auth-notice-actions button{min-height:40px;border-radius:10px;padding:0 14px;border:0;font-weight:700;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.mc-auth-login-btn{background:#ff6b35;color:#fff}.mc-auth-retry-btn{background:#f6f3ee;color:#1f1a17}';

    notice.querySelector('.mc-auth-login-btn').addEventListener('click', () => {
      if (typeof onLoginClick === 'function') {
        onLoginClick();
      }
      const redirect = getRedirectPath();
      writeStoredValue(redirectKey, redirect);
      window.location.href = loginUrl;
    });

    notice.querySelector('.mc-auth-retry-btn').addEventListener('click', () => {
      removeStoredValue(redirectKey);
      const existingNotice = document.querySelector('.mc-auth-notice');
      if (existingNotice) {
        existingNotice.remove();
      }
      window.location.reload();
    });

    document.head.appendChild(style);
    document.body.appendChild(notice);
  }

  function authHeaders(extraHeaders = {}) {
    if (window.AppAuth && typeof window.AppAuth.authHeaders === 'function') {
      return window.AppAuth.authHeaders(extraHeaders);
    }
    const token = getToken();
    return token ? { ...extraHeaders, Authorization: `Bearer ${token}` } : { ...extraHeaders };
  }

  async function fetchCurrentUser() {
    try {
      await ensureAppAuthAvailable();
    } catch (error) {
      return { ok: false, reason: 'network_error', error };
    }

    if (window.AppAuth && typeof window.AppAuth.ensureAccessToken === 'function') {
      try {
        await window.AppAuth.ensureAccessToken(false);
      } catch (error) {
        return { ok: false, reason: 'network_error', error };
      }
    }

    const token = getToken();
    if (!token) {
      return { ok: false, reason: 'missing_token' };
    }

    try {
      const response = await fetch('/api/auth/me.php', {
        method: 'GET',
        cache: 'no-store',
        headers: authHeaders()
      });
      const text = await response.text();
      let data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch (error) {
        data = null;
      }
      if (response.ok && data && data.code === 0 && data.data) {
        writeStoredValue('user_info', JSON.stringify(data.data));
        return { ok: true, user: data.data };
      }
      if (response.status === 429) {
        return { ok: true, user: getStoredUser(), rateLimited: true };
      }
      return { ok: false, reason: 'invalid_token', response: data };
    } catch (error) {
      return { ok: false, reason: 'network_error', error };
    }
  }

  async function requirePageAuth(options = {}) {
    if (isPreviewHost) {
      unifyTopNav(previewUser);
      if (typeof options.onAuthed === 'function') {
        await options.onAuthed(previewUser);
      }
      return previewUser;
    }

    const maxRetries = options.maxRetries || 3;
    const retryDelay = options.retryDelay || 1000;
    
    let retryCount = 0;
    let result = null;

    while (retryCount < maxRetries) {
      result = await fetchCurrentUser();
      if (result.ok) {
        break;
      }
      
      if (result.reason === 'network_error' && retryCount < maxRetries - 1) {
        retryCount++;
        await new Promise(resolve => setTimeout(resolve, retryDelay));
        continue;
      }
      
      break;
    }

    if (!result.ok) {
      const loginUrl = getLoginUrl();
      const errorMessage = result.reason === 'network_error' 
        ? '网络连接失败，请检查网络后重试或前往登录页面' 
        : result.reason === 'missing_token' 
        ? '未找到登录凭证，请前往登录页面'
        : '登录凭证已失效，请重新登录';
      
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
          showAuthNotice(errorMessage, loginUrl, () => {
            clearAuth();
          });
        });
      } else {
        showAuthNotice(errorMessage, loginUrl, () => {
          clearAuth();
        });
      }
      return null;
    }

    removeStoredValue(redirectKey);
    if (result.user) {
      unifyTopNav(result.user);
    }
    if (typeof options.onAuthed === 'function') {
      await options.onAuthed(result.user);
    }
    return result.user;
  }

  window.authHeaders = authHeaders;
  window.fetchCurrentUser = fetchCurrentUser;
  window.requirePageAuth = requirePageAuth;
  window.clearAuth = clearAuth;
  window.InternalAuth = Object.freeze({
    adaptUserIdentity,
    canShowAdminDashboardEntry,
    getVisibleNavigationItems,
    getRedirectPath,
    getLoginUrl,
    renderShellForUser: unifyTopNav
  });

  if (path === '/mobile/login.html' || path === '/mobile/login.html/') {
    return;
  }

  scheduleOpsShell(isPreviewHost ? previewUser : null);

  if (typeof window.addEventListener === 'function') {
    window.addEventListener('hashchange', () => {
      unifyTopNav(isPreviewHost ? previewUser : getStoredUser());
    });
  }

  if (shouldSkipAutoInternalAuth) {
    return;
  }

  requirePageAuth();
})();
