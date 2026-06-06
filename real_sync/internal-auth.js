(() => {
  const LOGIN_PATH = '/mobile/login.html';
  const redirectKey = 'mc_internal_auth_redirect_once';
  const path = window.location.pathname || '/';
  const shouldSkipAutoInternalAuth = !!window.__SKIP_AUTO_INTERNAL_AUTH__;
  const UNIFIED_NAV_ITEMS = [
    { label: '内网首页', href: '/internal.html' },
    { label: '制度中心', href: '/制度标准/' },
    { label: '学习中心', href: '/新员工学习/' },
    { label: '培训中心', href: '/training/' },
    { label: '管理中心', href: '/admin/dashboard.html' },
    { label: '我的', href: '/mobile/mine.html', className: 'staff-link' }
  ];

  function navIsCurrent(targetHref, currentPath) {
    if (targetHref === '/internal.html') {
      return currentPath === '/internal.html' || currentPath === '/internal.html/';
    }
    if (targetHref.endsWith('/')) {
      return currentPath.startsWith(targetHref);
    }
    return currentPath === targetHref;
  }

  function canShowAdminDashboardEntry(user) {
    const role = String(user?.role || '').toLowerCase();
    return !!user?.is_hq || !!user?.is_admin || ['admin', 'ceo', 'operation', 'finance'].includes(role);
  }

  function unifyTopNav(user = null) {
    const nav = document.querySelector('.site-header .topbar .nav');
    if (!nav) {
      return;
    }

    const currentPath = window.location.pathname || '/';
    const html = [];
    for (const item of UNIFIED_NAV_ITEMS) {
      if (item.href === '/admin/dashboard.html' && !canShowAdminDashboardEntry(user)) {
        continue;
      }
      const classes = [];
      if (navIsCurrent(item.href, currentPath)) {
        classes.push('current');
      }
      if (item.className) {
        classes.push(item.className);
      }
      html.push(`<a href="${item.href}"${classes.length ? ` class="${classes.join(' ')}"` : ''}>${item.label}</a>`);
    }
    nav.innerHTML = html.join('');
  }

  function getToken() {
    return localStorage.getItem('jwt_token') || localStorage.getItem('token') || '';
  }

  function clearAuth() {
    localStorage.removeItem('jwt_token');
    localStorage.removeItem('token');
    localStorage.removeItem('user_info');
  }

  function getRedirectPath() {
    return `${window.location.pathname}${window.location.search || ''}${window.location.hash || ''}`;
  }

  function getLoginUrl() {
    return `${LOGIN_PATH}?redirect=${encodeURIComponent(getRedirectPath())}`;
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
      sessionStorage.setItem(redirectKey, redirect);
      window.location.href = loginUrl;
    });

    notice.querySelector('.mc-auth-retry-btn').addEventListener('click', () => {
      sessionStorage.removeItem(redirectKey);
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
    const token = getToken();
    return token ? { ...extraHeaders, Authorization: `Bearer ${token}` } : { ...extraHeaders };
  }

  async function fetchCurrentUser() {
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
      const data = await response.json();
      if (response.ok && data && data.code === 0 && data.data) {
        localStorage.setItem('user_info', JSON.stringify(data.data));
        return { ok: true, user: data.data };
      }
      return { ok: false, reason: 'invalid_token', response: data };
    } catch (error) {
      return { ok: false, reason: 'network_error', error };
    }
  }

  async function requirePageAuth(options = {}) {
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

    sessionStorage.removeItem(redirectKey);
    if (!shouldSkipAutoInternalAuth) {
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

  if (path === LOGIN_PATH || path === '/mobile/login.html/') {
    return;
  }

  if (!shouldSkipAutoInternalAuth) {
    unifyTopNav();
  }

  if (shouldSkipAutoInternalAuth) {
    return;
  }

  requirePageAuth();
})();
