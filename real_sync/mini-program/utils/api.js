const auth = require('./auth');

const REQUEST_TIMEOUT = 15000;
const UPLOAD_TIMEOUT = 60000;
let refreshPromise = null;

function createOperationId(prefix) {
  const random = Math.random().toString(36).slice(2, 12);
  return `${prefix || 'req'}_${Date.now().toString(36)}_${random}`;
}

function createRequestId() {
  return createOperationId('mp');
}

function createIdempotencyKey(action) {
  return createOperationId(String(action || 'operation').replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 32));
}

function errorCategory(statusCode, code) {
  const status = Number(statusCode || code || 0);
  if (status === 401) return 'unauthorized';
  if (status === 403) return 'forbidden';
  if (status === 409) return 'conflict';
  if (status === 400 || status === 422) return 'validation';
  if (status >= 500) return 'server';
  return 'http';
}

function normalizeError(res, fallbackMessage, context) {
  context = context || {};
  const data = res && res.data ? res.data : null;
  const err = new Error((data && data.message) || fallbackMessage || '请求失败');
  err.statusCode = res ? res.statusCode : 0;
  err.code = data && typeof data.code !== 'undefined' ? data.code : err.statusCode;
  err.data = data;
  err.category = errorCategory(err.statusCode, err.code);
  err.requestId = (data && data.request_id) || context.requestId || '';
  err.url = context.url || '';
  err.retryable = err.category === 'server' || err.category === 'conflict';
  if (err.category === 'conflict' && data && data.data) {
    err.conflictType = data.data.conflict_type || data.data.code || '';
    err.baseVersion = data.data.base_version;
    err.currentVersion = data.data.current_version;
    err.authoritativeState = data.data.authoritative_state || null;
    err.recoveryAction = data.data.recovery_action || null;
  }
  return err;
}

function transportError(err, messages, context) {
  const timedOut = Boolean(err && err.errMsg && err.errMsg.indexOf('timeout') >= 0);
  const error = new Error(timedOut ? messages.timeout : messages.network);
  error.original = err;
  error.url = context.url;
  error.requestId = context.requestId;
  error.statusCode = 0;
  error.code = timedOut ? 'timeout' : 'network_error';
  error.category = timedOut ? 'timeout' : 'network';
  error.retryable = true;
  return error;
}

function timeoutOption(options, fallback) {
  return typeof options.timeout === 'number' ? options.timeout : fallback;
}

function requestHeaders(options, token, includeJsonContentType) {
  const defaults = { 'X-Request-ID': options.requestId };
  if (includeJsonContentType !== false) defaults['Content-Type'] = 'application/json';
  const header = Object.assign(defaults, options.header || {});
  if (options.idempotencyKey) header['Idempotency-Key'] = options.idempotencyKey;
  if (token && options.auth !== false) header.Authorization = `Bearer ${token}`;
  return header;
}

function requestData(options) {
  const data = Object.assign({}, options.data || {});
  if (typeof options.stateVersion !== 'undefined') {
    data[options.stateVersionField || 'state_version'] = options.stateVersion;
  }
  return data;
}

function recordRequestError(url, err) {
  try {
    wx.setStorageSync('last_request_error', {
      url,
      errMsg: err && err.errMsg ? err.errMsg : '',
      at: Date.now()
    });
  } catch (storageErr) {
    console.error('写入请求失败日志失败:', storageErr);
  }
}

function resolveApiBase(options) {
  const app = typeof getApp === 'function' ? getApp() : null;
  return options.apiBase || (app && app.globalData && app.globalData.apiBase) || 'https://supercalf.com/api';
}

function requireReauthentication(options) {
  if (options.redirectOnUnauthorized !== false) {
    auth.redirectToLogin();
    return;
  }
  auth.clearAuth();
  wx.setStorageSync('auth_state', 'reauthentication');
}

function refreshSession(apiBase) {
  if (refreshPromise) return refreshPromise;
  const requestId = createRequestId();
  const refreshToken = auth.getRefreshToken();
  const deviceId = wx.getStorageSync('device_id') || '';
  if (!refreshToken || !deviceId) {
    return Promise.reject(normalizeError({ statusCode: 401, data: { code: 401, message: '登录已过期，请重新登录' } }));
  }

  refreshPromise = new Promise((resolve, reject) => {
    wx.request({
      url: `${apiBase}/auth/mini-program-session.php?action=refresh`,
      method: 'POST',
      data: { refresh_token: refreshToken, device_id: deviceId },
      header: { 'Content-Type': 'application/json', 'X-Request-ID': requestId },
      timeout: REQUEST_TIMEOUT,
      success(res) {
        const data = res.data || {};
        const session = data.data || null;
        const validSession = session
          && typeof session.token === 'string' && session.token
          && typeof session.refresh_token === 'string' && session.refresh_token
          && typeof session.session_id === 'string' && session.session_id
          && session.session_type === 'device';
        if (res.statusCode >= 200 && res.statusCode < 300 && Number(data.code) === 0 && validSession) {
          auth.setSession(session);
          const app = typeof getApp === 'function' ? getApp() : null;
          if (app && app.globalData) app.globalData.token = session.token;
          resolve(session.token);
          return;
        }
        reject(normalizeError(res, '会话刷新失败，请重新登录'));
      },
      fail(err) {
        const error = new Error(err && err.errMsg && err.errMsg.indexOf('timeout') >= 0 ? '会话刷新超时' : '会话刷新失败');
        error.original = err;
        reject(error);
      }
    });
  });

  refreshPromise = refreshPromise.then(
    token => {
      refreshPromise = null;
      return token;
    },
    error => {
      refreshPromise = null;
      throw error;
    }
  );
  return refreshPromise;
}

function ensureFreshToken(apiBase, options) {
  if (options.auth === false) return Promise.resolve('');
  const token = auth.getToken();
  if (!token) {
    requireReauthentication(options);
    return Promise.reject(normalizeError({ statusCode: 401, data: { code: 401, message: '请先登录' } }));
  }
  if (!auth.isTokenExpired()) return Promise.resolve(token);
  if (auth.hasRefreshSession()) {
    return refreshSession(apiBase).catch(error => {
      requireReauthentication(options);
      throw error;
    });
  }
  requireReauthentication(options);
  return Promise.reject(normalizeError({ statusCode: 401, data: { code: 401, message: '登录已过期，请重新登录' } }));
}

function retryAfterUnauthorized(options, apiBase, retried, operation, response, attemptedToken) {
  if (options.auth === false) {
    return Promise.reject(normalizeError(response, '请求未获授权', options));
  }
  if (!retried && attemptedToken && auth.getToken() && auth.getToken() !== attemptedToken) {
    return operation();
  }
  if (!retried && auth.hasRefreshSession()) {
    return refreshSession(apiBase).catch(error => {
      requireReauthentication(options);
      throw error;
    }).then(operation);
  }
  requireReauthentication(options);
  return Promise.reject(normalizeError(response, '登录已过期，请重新登录'));
}

function request(options, retried) {
  options = Object.assign({}, options || {});
  if (!options.requestId) options.requestId = createRequestId();
  const apiBase = resolveApiBase(options);
  const url = /^https?:\/\//.test(options.url || '') ? options.url : `${apiBase}${options.url || ''}`;
  return ensureFreshToken(apiBase, options).then(() => new Promise((resolve, reject) => {
    const token = options.auth === false ? '' : auth.getToken();
    const header = requestHeaders(options, token);
    wx.request({
      url,
      method: options.method || 'GET',
      data: requestData(options),
      header,
      timeout: timeoutOption(options, REQUEST_TIMEOUT),
      success(res) {
        const data = res.data || {};
        if (res.statusCode === 401 || Number(data.code) === 401) {
          retryAfterUnauthorized(options, apiBase, retried, () => request(options, true), res, token).then(resolve, reject);
          return;
        }
        if (res.statusCode >= 200 && res.statusCode < 300 && Number(data.code) === 0) {
          resolve(data);
          return;
        }
        reject(normalizeError(res, `请求失败：${res.statusCode}`, { requestId: options.requestId, url }));
      },
      fail(err) {
        console.error('请求失败:', url, err);
        recordRequestError(url, err);
        reject(transportError(err, {
          timeout: '请求超时，请稍后重试',
          network: '网络请求失败，请检查网络后重试',
        }, { requestId: options.requestId, url }));
      },
    });
  }));
}

function get(url, options) {
  return request(Object.assign({}, options || {}, { url, method: 'GET' }));
}

function post(url, data, options) {
  return request(Object.assign({}, options || {}, { url, data, method: 'POST' }));
}

function resolveUploadDigest(options) {
  const provided = String(options.uploadDigest || '').toLowerCase();
  if (/^[a-f0-9]{64}$/.test(provided)) return Promise.resolve(provided);
  if (typeof wx.getFileInfo !== 'function') return Promise.resolve('');
  return new Promise(resolve => {
    wx.getFileInfo({
      filePath: options.filePath,
      digestAlgorithm: 'sha256',
      success(result) {
        const digest = String((result && result.digest) || '').toLowerCase();
        resolve(/^[a-f0-9]{64}$/.test(digest) ? digest : '');
      },
      fail() {
        resolve('');
      },
    });
  });
}

function uploadFile(options, retried) {
  options = Object.assign({}, options || {});
  if (!options.requestId) options.requestId = createRequestId();
  const apiBase = resolveApiBase(options);
  const url = /^https?:\/\//.test(options.url || '') ? options.url : `${apiBase}${options.url || ''}`;
  return Promise.all([ensureFreshToken(apiBase, options), resolveUploadDigest(options)]).then(([, digest]) => new Promise((resolve, reject) => {
    const token = options.auth === false ? '' : auth.getToken();
    const formData = requestData(Object.assign({}, options, { data: options.formData || {} }));
    if (digest) formData.file_sha256 = digest;
    const uploadTask = wx.uploadFile({
      url,
      filePath: options.filePath,
      name: options.name || 'file',
      formData,
      header: requestHeaders(options, token, false),
      timeout: timeoutOption(options, UPLOAD_TIMEOUT),
      success(res) {
        let parsedData = null;
        try {
          parsedData = typeof res.data === 'string' ? JSON.parse(res.data) : res.data;
        } catch (e) {
          parsedData = null;
        }
        if (res.statusCode === 401 || Number(parsedData && parsedData.code) === 401) {
          const authResponse = { statusCode: res.statusCode, data: parsedData };
          retryAfterUnauthorized(options, apiBase, retried, () => uploadFile(options, true), authResponse, token).then(resolve, reject);
          return;
        }
        if (res.statusCode >= 200 && res.statusCode < 300) {
          if (!parsedData) {
            const error = new Error('上传响应格式错误，请重试');
            error.statusCode = res.statusCode;
            error.code = 'invalid_response';
            error.category = 'protocol';
            error.requestId = options.requestId;
            error.url = url;
            error.retryable = true;
            reject(error);
            return;
          }
          if (Number(parsedData.code) === 0) {
            resolve(parsedData);
            return;
          }
          reject(normalizeError({ statusCode: res.statusCode, data: parsedData }, `上传失败：${parsedData.message || '未知错误'}`, { requestId: options.requestId, url }));
          return;
        }
        reject(normalizeError({ statusCode: res.statusCode, data: parsedData || res.data }, `上传失败：${res.statusCode}`, { requestId: options.requestId, url }));
      },
      fail(err) {
        console.error('上传失败:', url, err);
        recordRequestError(url, err);
        reject(transportError(err, {
          timeout: '上传超时，请稍后重试',
          network: '上传失败，请检查网络后重试',
        }, { requestId: options.requestId, url }));
      }
    });
    if (uploadTask && typeof uploadTask.onProgressUpdate === 'function' && typeof options.onProgress === 'function') {
      uploadTask.onProgressUpdate((progress) => options.onProgress(Number(progress.progress || 0)));
    }
  }));
}

function logoutSession(options) {
  options = options || {};
  const requestId = options.requestId || createRequestId();
  const apiBase = resolveApiBase(options);
  const refreshToken = auth.getRefreshToken();
  const deviceId = wx.getStorageSync('device_id') || '';
  auth.clearAuth();
  if (!refreshToken || !deviceId) return Promise.resolve();

  return new Promise(resolve => {
    wx.request({
      url: `${apiBase}/auth/mini-program-session.php?action=logout`,
      method: 'POST',
      data: { refresh_token: refreshToken, device_id: deviceId },
      header: { 'Content-Type': 'application/json', 'X-Request-ID': requestId },
      timeout: timeoutOption(options, REQUEST_TIMEOUT),
      success: resolve,
      fail: resolve,
    });
  });
}

module.exports = {
  request,
  get,
  post,
  uploadFile,
  normalizeError,
  createRequestId,
  createIdempotencyKey,
  refreshSession,
  logoutSession,
};
