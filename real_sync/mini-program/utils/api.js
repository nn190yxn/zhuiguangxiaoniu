const auth = require('./auth');

const DEFAULT_TIMEOUT = 15000;
const DEFAULT_UPLOAD_TIMEOUT = 60000;

function normalizeError(res, fallbackMessage) {
  const data = res && res.data ? res.data : null;
  const err = new Error((data && data.message) || fallbackMessage || '请求失败');
  err.statusCode = res ? res.statusCode : 0;
  err.code = data && typeof data.code !== 'undefined' ? data.code : err.statusCode;
  err.data = data;
  return err;
}

function request(options) {
  options = options || {};
  const app = getApp ? getApp() : null;
  const apiBase = options.apiBase || (app && app.globalData && app.globalData.apiBase) || 'https://supercalf.com/api';
  const url = /^https?:\/\//.test(options.url || '') ? options.url : `${apiBase}${options.url || ''}`;
  const route = getCurrentRoute();
  const requestId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  const token = auth.getToken();
  const header = Object.assign({ 'Content-Type': 'application/json' }, options.header || {});
  if (token) header.Authorization = `Bearer ${token}`;

  if (token && auth.isTokenExpired()) {
    if (options.redirectOnUnauthorized !== false) auth.redirectToLogin();
    return Promise.reject(normalizeError({ statusCode: 401, data: { code: 401, message: '登录已过期，请重新登录', data: null } }));
  }

  return new Promise((resolve, reject) => {
    const startedAt = Date.now();
    wx.request({
      url,
      method: options.method || 'GET',
      data: options.data || {},
      header,
      timeout: options.timeout || DEFAULT_TIMEOUT,
      success(res) {
        const data = res.data || {};
        if (res.statusCode === 401 || Number(data.code) === 401) {
          if (options.redirectOnUnauthorized !== false) auth.redirectToLogin();
          reject(normalizeError(res, '登录已过期，请重新登录'));
          return;
        }
        if (res.statusCode >= 200 && res.statusCode < 300 && Number(data.code) === 0) {
          resolve(data);
          return;
        }
        reject(normalizeError(res, `请求失败：${res.statusCode}`));
      },
      fail(err) {
        const isTimeout = err && err.errMsg && err.errMsg.indexOf('timeout') >= 0;
        const error = {
          message: isTimeout ? '请求超时，请稍后重试' : '网络请求失败，请检查网络后重试',
          code: isTimeout ? 'timeout' : 'network_error',
          statusCode: 0,
          url,
          route,
          requestId,
          duration: Date.now() - startedAt,
          original: err
        };
        console.error('[api.request.fail]', {
          requestId,
          code: error.code,
          url,
          route,
          duration: error.duration,
          errMsg: err && err.errMsg
        });
        reject(error);
      },
    });
  });
}

function uploadFile(options) {
  options = options || {};
  const app = getApp ? getApp() : null;
  const apiBase = options.apiBase || (app && app.globalData && app.globalData.apiBase) || 'https://supercalf.com/api';
  const url = /^https?:\/\//.test(options.url || '') ? options.url : `${apiBase}${options.url || ''}`;
  const route = getCurrentRoute();
  const requestId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  const token = auth.getToken();
  const header = Object.assign({}, options.header || {});
  if (token && !header.Authorization) header.Authorization = `Bearer ${token}`;

  const startedAt = Date.now();
  let uploadTask = null;
  const promise = new Promise((resolve, reject) => {
    uploadTask = wx.uploadFile({
      url,
      filePath: options.filePath,
      name: options.name || 'file',
      formData: options.formData || {},
      header,
      timeout: options.timeout || DEFAULT_UPLOAD_TIMEOUT,
      success(res) {
        let data = null;
        try {
          data = typeof res.data === 'string' ? JSON.parse(res.data) : res.data;
        } catch (err) {
          reject(normalizeError({ statusCode: res.statusCode, data: { code: 'parse_error', message: '上传返回异常', data: res.data } }));
          return;
        }
        if (res.statusCode === 401 || Number(data.code) === 401) {
          if (options.redirectOnUnauthorized !== false) auth.redirectToLogin();
          reject(normalizeError({ statusCode: res.statusCode, data }, '登录已过期，请重新登录'));
          return;
        }
        if (res.statusCode >= 200 && res.statusCode < 300 && Number(data.code) === 0) {
          resolve(data);
          return;
        }
        reject(normalizeError({ statusCode: res.statusCode, data }, `上传失败：${res.statusCode}`));
      },
      fail(err) {
        const isTimeout = err && err.errMsg && err.errMsg.indexOf('timeout') >= 0;
        const error = {
          message: isTimeout ? '上传超时，请稍后重试' : '上传失败，请检查网络后重试',
          code: isTimeout ? 'timeout' : 'network_error',
          statusCode: 0,
          url,
          route,
          requestId,
          duration: Date.now() - startedAt,
          original: err
        };
        console.error('[api.uploadFile.fail]', {
          requestId,
          code: error.code,
          url,
          route,
          duration: error.duration,
          errMsg: err && err.errMsg
        });
        reject(error);
      },
    });

    if (uploadTask && typeof options.onProgressUpdate === 'function') {
      uploadTask.onProgressUpdate(options.onProgressUpdate);
    }
  });

  promise.task = uploadTask;
  return promise;
}

function getCurrentRoute() {
  try {
    const pages = getCurrentPages ? getCurrentPages() : [];
    const current = pages[pages.length - 1];
    return current && current.route ? current.route : '';
  } catch (err) {
    return '';
  }
}

function get(url, options) {
  return request(Object.assign({}, options || {}, { url, method: 'GET' }));
}

function post(url, data, options) {
  return request(Object.assign({}, options || {}, { url, data, method: 'POST' }));
}

module.exports = {
  request,
  uploadFile,
  get,
  post,
  normalizeError,
};
