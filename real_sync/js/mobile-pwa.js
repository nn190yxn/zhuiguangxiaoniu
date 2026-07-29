(function () {
  'use strict';

  var installEvent = null;
  var refreshPending = false;
  var dismissKey = 'zgxn_pwa_install_dismissed_until';

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  }

  function isAppleMobile() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent);
  }

  function dismissed() {
    try {
      return Number(window.localStorage.getItem(dismissKey) || 0) > Date.now();
    } catch (error) {
      return false;
    }
  }

  function dismiss() {
    try {
      window.localStorage.setItem(dismissKey, String(Date.now() + 7 * 24 * 60 * 60 * 1000));
    } catch (error) {
      // The prompt remains dismissible when browser storage is unavailable.
    }
    removeInstallPrompt();
  }

  function removeInstallPrompt() {
    var prompt = document.getElementById('mobilePwaInstallPrompt');
    if (prompt) prompt.remove();
  }

  function ensureStyles() {
    if (document.getElementById('mobilePwaStyles')) return;
    var style = document.createElement('style');
    style.id = 'mobilePwaStyles';
    style.textContent = '.mobile-pwa-prompt{position:fixed;left:12px;right:12px;bottom:calc(82px + env(safe-area-inset-bottom));z-index:9999;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 14px;border:1px solid rgba(255,107,53,.24);border-radius:16px;background:#fffaf7;color:#1f1a17;box-shadow:0 14px 34px rgba(31,26,23,.18);font:13px -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}.mobile-pwa-prompt strong,.mobile-pwa-prompt span{display:block}.mobile-pwa-prompt strong{font-size:14px}.mobile-pwa-prompt span{margin-top:3px;color:#6b625c;line-height:1.45}.mobile-pwa-prompt-actions{display:flex;gap:7px;flex-shrink:0}.mobile-pwa-prompt button{min-height:36px;border:0;border-radius:10px;padding:0 11px;background:#ff6b35;color:#fff;font:700 12px inherit}.mobile-pwa-prompt [data-pwa-dismiss]{background:#f0ebe7;color:#5d554f}.mobile-pwa-update{bottom:calc(82px + env(safe-area-inset-bottom));border-color:rgba(22,40,66,.2)}html[data-pwa-mode="standalone"] .mobile-pwa-prompt{bottom:calc(16px + env(safe-area-inset-bottom))}';
    document.head.appendChild(style);
  }

  function installPrompt() {
    if (isStandalone() || dismissed() || document.getElementById('mobilePwaInstallPrompt')) return;
    var appleMobile = isAppleMobile();
    var instruction = appleMobile
      ? '点击浏览器分享按钮，选择“添加到主屏幕”。'
      : installEvent
        ? '安装后可从桌面全屏打开员工端。'
        : '点击 Chrome 右上角“⋮”，选择“安装应用”或“添加到主屏幕”。';

    var prompt = document.createElement('section');
    prompt.id = 'mobilePwaInstallPrompt';
    prompt.className = 'mobile-pwa-prompt';
    prompt.setAttribute('role', 'status');
    prompt.innerHTML = '<div><strong>固定到手机桌面</strong><span>' + instruction + '</span></div><div class="mobile-pwa-prompt-actions"><button type="button" data-pwa-install>' + (installEvent ? '安装' : '查看方法') + '</button><button type="button" data-pwa-dismiss aria-label="关闭安装提示">稍后</button></div>';
    prompt.querySelector('[data-pwa-dismiss]').addEventListener('click', dismiss);
    prompt.querySelector('[data-pwa-install]').addEventListener('click', function () {
      if (installEvent) {
        installEvent.prompt();
        installEvent.userChoice.finally(function () {
          installEvent = null;
          removeInstallPrompt();
        });
        return;
      }
      window.alert(appleMobile ? '请点击浏览器底部或顶部的分享按钮，然后选择“添加到主屏幕”。' : '请点击 Chrome 右上角“⋮”，选择“安装应用”或“添加到主屏幕”。');
    });
    document.body.appendChild(prompt);
  }

  function updatePrompt(registration) {
    if (document.getElementById('mobilePwaUpdatePrompt')) return;
    var prompt = document.createElement('section');
    prompt.id = 'mobilePwaUpdatePrompt';
    prompt.className = 'mobile-pwa-prompt mobile-pwa-update';
    prompt.setAttribute('role', 'status');
    prompt.innerHTML = '<div><strong>应用已有新版本</strong><span>刷新后即可使用最新功能。</span></div><div class="mobile-pwa-prompt-actions"><button type="button" data-pwa-refresh>立即刷新</button></div>';
    prompt.querySelector('[data-pwa-refresh]').addEventListener('click', function () {
      refreshPending = true;
      if (registration.waiting) registration.waiting.postMessage('SKIP_WAITING');
      else window.location.reload();
    });
    document.body.appendChild(prompt);
  }

  function registerWorker() {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register('/sw.js').then(function (registration) {
      if (registration.waiting && navigator.serviceWorker.controller) updatePrompt(registration);
      registration.addEventListener('updatefound', function () {
        var worker = registration.installing;
        if (!worker) return;
        worker.addEventListener('statechange', function () {
          if (worker.state === 'installed' && navigator.serviceWorker.controller) updatePrompt(registration);
        });
      });
    }).catch(function () {
      document.documentElement.dataset.pwaWorker = 'unavailable';
    });
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (refreshPending) window.location.reload();
    });
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    installEvent = event;
    installPrompt();
  });

  window.addEventListener('appinstalled', function () {
    installEvent = null;
    removeInstallPrompt();
  });

  document.addEventListener('DOMContentLoaded', function () {
    ensureStyles();
    document.documentElement.dataset.pwaMode = isStandalone() ? 'standalone' : 'browser';
    installPrompt();
    registerWorker();
  });
}());
