const TAB_ROUTES = new Set([
  '/pages/workload/index',
  '/pages/drill/list/list',
  '/pages/mine/mine',
]);

function routePath(url) {
  return String(url || '').split('?')[0];
}

function isPageRoute(url) {
  return /^\/pages\/[a-zA-Z0-9/_-]+(?:\?[^#]*)?$/.test(String(url || ''));
}

function invalidRoute() {
  wx.showToast({ title: '页面暂不可用', icon: 'none' });
  return false;
}

function parseQuery(query) {
  return String(query || '').split('&').reduce((values, pair) => {
    if (!pair) return values;
    const separator = pair.indexOf('=');
    const rawKey = separator >= 0 ? pair.slice(0, separator) : pair;
    const rawValue = separator >= 0 ? pair.slice(separator + 1) : '';
    try {
      values[decodeURIComponent(rawKey)] = decodeURIComponent(rawValue.replace(/\+/g, ' '));
    } catch (error) {
      values[rawKey] = rawValue;
    }
    return values;
  }, {});
}

function consumeTabQuery(path) {
  const storageKey = `pending_tab_query:${path}`;
  const query = wx.getStorageSync(storageKey);
  if (!query) return {};
  wx.removeStorageSync(storageKey);
  return parseQuery(query);
}

function open(url) {
  if (!isPageRoute(url)) return invalidRoute();
  const path = routePath(url);
  if (TAB_ROUTES.has(path)) {
    if (url !== path) wx.setStorageSync(`pending_tab_query:${path}`, String(url).slice(path.length + 1));
    wx.switchTab({ url: path });
    return true;
  }
  wx.navigateTo({ url });
  return true;
}

function replace(url) {
  if (!isPageRoute(url)) return invalidRoute();
  if (TAB_ROUTES.has(routePath(url))) return open(url);
  wx.redirectTo({ url });
  return true;
}

function reLaunch(url) {
  if (!isPageRoute(url)) return invalidRoute();
  wx.reLaunch({ url });
  return true;
}

module.exports = {
  TAB_ROUTES,
  open,
  replace,
  reLaunch,
  consumeTabQuery,
};
