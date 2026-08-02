(function (global) {
  'use strict';

  var DEFAULT_TARGET = '/mobile/mine.html';
  var ALLOWED_PATHS = new Set([
    '/mobile/mine.html',
    '/mobile/workload.html',
    '/mobile/workload-v2.html',
    '/mobile/drill.html',
    '/mobile/learning.html'
  ]);

  function resolveTarget(rawTarget, origin) {
    if (!rawTarget) return DEFAULT_TARGET;

    try {
      var baseOrigin = origin || global.location.origin;
      var target = new URL(rawTarget, baseOrigin);
      if (target.origin !== baseOrigin || !ALLOWED_PATHS.has(target.pathname)) {
        return DEFAULT_TARGET;
      }
      return target.pathname + target.search + target.hash;
    } catch (error) {
      return DEFAULT_TARGET;
    }
  }

  function createEntryUrl(rawTarget, origin) {
    var target = resolveTarget(rawTarget, origin);
    return '/mobile/?redirect=' + encodeURIComponent(target);
  }

  global.MobileEntry = {
    DEFAULT_TARGET: DEFAULT_TARGET,
    resolveTarget: resolveTarget,
    createEntryUrl: createEntryUrl
  };
})(typeof window === 'undefined' ? globalThis : window);
