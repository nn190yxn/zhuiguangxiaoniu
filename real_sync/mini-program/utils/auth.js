function getToken() {
  return wx.getStorageSync('token') || wx.getStorageSync('jwt_token') || '';
}

function setToken(token) {
  if (token) {
    wx.setStorageSync('token', token);
    wx.setStorageSync('jwt_token', token);
  }
}

function getRefreshToken() {
  return wx.getStorageSync('session_refresh_token') || '';
}

function getSession() {
  return {
    refreshToken: getRefreshToken(),
    sessionId: wx.getStorageSync('session_id') || '',
    sessionVersion: Number(wx.getStorageSync('session_version') || 0),
    sessionType: wx.getStorageSync('session_type') || 'legacy'
  };
}

function setSession(session) {
  session = session || {};
  if (session.token) setToken(session.token);
  const completeDeviceSession = session.session_type === 'device'
    && session.refresh_token
    && session.session_id;
  if (completeDeviceSession) {
    wx.setStorageSync('session_refresh_token', session.refresh_token);
    wx.setStorageSync('session_id', session.session_id || '');
    wx.setStorageSync('session_version', Number(session.session_version || 0));
    wx.setStorageSync('session_type', session.session_type || 'device');
  } else {
    clearDeviceSession();
  }
  wx.setStorageSync('auth_state', 'authenticated');
}

function clearDeviceSession() {
  wx.removeStorageSync('session_refresh_token');
  wx.removeStorageSync('session_id');
  wx.removeStorageSync('session_version');
  wx.removeStorageSync('session_type');
}

function hasRefreshSession() {
  return getRefreshToken() !== '' && getSession().sessionType === 'device';
}

function getUserInfo() {
  return wx.getStorageSync('userInfo') || wx.getStorageSync('user_info') || null;
}

function setUserInfo(userInfo) {
  wx.setStorageSync('userInfo', userInfo || null);
  wx.setStorageSync('user_info', userInfo || null);
}

function clearAuth() {
  wx.removeStorageSync('token');
  wx.removeStorageSync('jwt_token');
  wx.removeStorageSync('userInfo');
  wx.removeStorageSync('user_info');
  wx.removeStorageSync('auth_state');
  clearDeviceSession();
  try {
    const app = typeof getApp === 'function' ? getApp() : null;
    if (app && app.globalData) {
      app.globalData.token = null;
      app.globalData.userInfo = null;
    }
  } catch (e) {
    // App may not be initialized while storage is being reset.
  }
}

function isTokenExpired(bufferSeconds) {
  const token = getToken();
  if (!token) return true;
  const parts = token.split('.');
  if (parts.length !== 3) return true;
  try {
    const payload = JSON.parse(base64UrlDecode(parts[1]));
    if (!payload || !payload.exp) return true;
    const buffer = typeof bufferSeconds === 'number' ? bufferSeconds : 60;
    return payload.exp <= Math.floor(Date.now() / 1000) + buffer;
  } catch (e) {
    return true;
  }
}

function base64UrlDecode(value) {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
  let input = value.replace(/-/g, '+').replace(/_/g, '/');
  while (input.length % 4) input += '=';

  let output = '';
  let buffer = 0;
  let bits = 0;
  for (let i = 0; i < input.length; i += 1) {
    const char = input.charAt(i);
    if (char === '=') break;
    const index = chars.indexOf(char);
    if (index < 0) continue;
    buffer = (buffer << 6) | index;
    bits += 6;
    if (bits >= 8) {
      bits -= 8;
      output += String.fromCharCode((buffer >> bits) & 0xff);
    }
  }

  return decodeURIComponent(output.split('').map(function (char) {
    return '%' + ('00' + char.charCodeAt(0).toString(16)).slice(-2);
  }).join(''));
}

function redirectToLogin() {
  const alreadyRedirecting = wx.getStorageSync('auth_state') === 'reauthentication';
  clearAuth();
  wx.setStorageSync('auth_state', 'reauthentication');
  if (!alreadyRedirecting) wx.reLaunch({ url: '/pages/login/login' });
}

module.exports = {
  getToken,
  setToken,
  getRefreshToken,
  getSession,
  setSession,
  hasRefreshSession,
  getUserInfo,
  setUserInfo,
  clearAuth,
  isTokenExpired,
  redirectToLogin,
};
