(function (window) {
  'use strict';

  // 管理后台公共工具：转义、轻提示、带鉴权的 fetch。
  // 新页面优先使用这里的能力，避免再复制各页内联实现。

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
    });
  }

  function ensureToastHost() {
    var host = document.getElementById('adminToastHost');
    if (!host) {
      host = document.createElement('div');
      host.id = 'adminToastHost';
      host.setAttribute('style', 'position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:9999;display:grid;gap:8px;max-width:min(92vw,480px)');
      document.body.appendChild(host);
    }
    return host;
  }

  function adminToast(message, isError) {
    var host = ensureToastHost();
    var item = document.createElement('div');
    item.textContent = String(message == null ? '' : message);
    item.setAttribute('role', 'status');
    item.setAttribute('style', 'padding:10px 16px;border-radius:12px;font-size:13px;line-height:1.6;box-shadow:0 10px 28px rgba(31,26,23,.18);background:#fff;color:#1f1a17;border:1px solid rgba(31,26,23,.12)' + (isError ? ';background:#fff0f0;color:#b42318;border-color:#f3c4c4' : ''));
    host.appendChild(item);
    setTimeout(function () {
      if (item.parentNode) item.parentNode.removeChild(item);
    }, 3600);
  }

  function authFetch(url, options) {
    var config = Object.assign({ cache: 'no-store' }, options || {});
    var base = window.authHeaders ? window.authHeaders() : {};
    config.headers = Object.assign({}, base, (options && options.headers) || {});
    return fetch(url, config);
  }

  window.AdminCommon = {
    escapeHtml: escapeHtml,
    adminToast: adminToast,
    authFetch: authFetch
  };
})(window);
