function getAuthToken() {
  return localStorage.getItem('jwt_token')
    || localStorage.getItem('token')
    || sessionStorage.getItem('jwt_token')
    || sessionStorage.getItem('token')
    || '';
}

function authHeaders(options) {
  var token = getAuthToken();
  var headers = Object.assign({}, (options && options.headers) || {});
  if (token) {
    headers.Authorization = 'Bearer ' + token;
  }
  return Object.assign({}, options || {}, { headers: headers });
}

function authFetch(url, options) {
  return fetch(url, authHeaders(options));
}

(function mountUnifiedQuickNav() {
  if (window.__DISABLE_UNIFIED_QUICK_NAV__) {
    return;
  }
  if (!document || !document.body) {
    return;
  }

  function ensureMounted() {
    if (document.getElementById('mcUnifiedQuickNav')) {
      return;
    }

    var path = window.location.pathname || '/';
    var items = [
      { label: '内网首页', href: '/internal.html' },
      { label: '制度中心', href: '/制度标准/' },
      { label: '学习中心', href: '/新员工学习/' },
      { label: '培训中心', href: '/training/' },
      { label: '管理中心', href: '/admin/dashboard.html' },
      { label: '我的', href: '/mobile/mine.html' }
    ];

    var isCurrent = function(href) {
      if (href.endsWith('/')) {
        return path.indexOf(href) === 0;
      }
      return path === href;
    };

    var wrap = document.createElement('div');
    wrap.id = 'mcUnifiedQuickNav';
    wrap.className = 'mc-unified-quick-nav';
    wrap.innerHTML = '<div class="mc-unified-quick-nav-inner">' +
      items.map(function(item) {
        var cls = isCurrent(item.href) ? ' class="current"' : '';
        return '<a href="' + item.href + '"' + cls + '>' + item.label + '</a>';
      }).join('') +
      '</div>';

    var style = document.createElement('style');
    style.textContent = '.mc-unified-quick-nav{position:sticky;top:0;z-index:999;background:rgba(255,255,255,.97);backdrop-filter:blur(12px);border-bottom:1px solid rgba(0,0,0,.06)}.mc-unified-quick-nav-inner{display:flex;gap:6px;overflow:auto;padding:8px 10px}.mc-unified-quick-nav-inner a{flex:0 0 auto;padding:6px 12px;border-radius:999px;background:#fff;border:1px solid rgba(0,0,0,.08);font-size:12px;color:#6b625c;text-decoration:none;font-weight:600;white-space:nowrap}.mc-unified-quick-nav-inner a.current{background:#ff6b35;border-color:#ff6b35;color:#fff}';

    document.head.appendChild(style);
    if (document.body.firstChild) {
      document.body.insertBefore(wrap, document.body.firstChild);
    } else {
      document.body.appendChild(wrap);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ensureMounted);
  } else {
    ensureMounted();
  }
})();
