(function (window) {
  'use strict';

  // 全站唯一的 cookie / storage 工具实现。
  // 供 app-auth.js、internal-auth.js 及页面内联脚本共用；认证脚本带兜底逻辑，
  // 本文件未加载时它们会自行定义同一套 API，页面因此无需担心脚本顺序。
  function readCookie(name) {
    var prefix = name + '=';
    var parts = document.cookie ? document.cookie.split('; ') : [];
    for (var i = 0; i < parts.length; i++) {
      if (parts[i].indexOf(prefix) === 0) {
        return decodeURIComponent(parts[i].slice(prefix.length));
      }
    }
    return '';
  }

  function writeCookie(name, value, maxAgeSeconds) {
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; Max-Age=' + (maxAgeSeconds || 604800) + '; SameSite=Lax' + secure;
  }

  function clearCookie(name) {
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=; Path=/; Max-Age=0; SameSite=Lax' + secure;
  }

  function readStoredValue(keys) {
    var keyList = Array.isArray(keys) ? keys : [keys];
    for (var i = 0; i < keyList.length; i++) {
      var key = keyList[i];
      try {
        var localValue = localStorage.getItem(key);
        if (localValue) return localValue;
      } catch (err) {}
      try {
        var sessionValue = sessionStorage.getItem(key);
        if (sessionValue) return sessionValue;
      } catch (err) {}
      var cookieValue = readCookie(key);
      if (cookieValue) return cookieValue;
    }
    return '';
  }

  function writeStoredValue(key, value, options) {
    options = options || {};
    var stored = false;
    try { localStorage.setItem(key, value); stored = true; } catch (err) {}
    try { sessionStorage.setItem(key, value); stored = true; } catch (err) {}
    if (options.cookie) {
      try { writeCookie(key, value, options.maxAgeSeconds || 604800); stored = true; } catch (err) {}
    }
    return stored;
  }

  function removeStoredValue(keys) {
    var keyList = Array.isArray(keys) ? keys : [keys];
    keyList.forEach(function (key) {
      try { localStorage.removeItem(key); } catch (err) {}
      try { sessionStorage.removeItem(key); } catch (err) {}
      clearCookie(key);
    });
  }

  window.WebStorage = {
    readCookie: readCookie,
    writeCookie: writeCookie,
    clearCookie: clearCookie,
    readStoredValue: readStoredValue,
    writeStoredValue: writeStoredValue,
    removeStoredValue: removeStoredValue
  };
})(window);
