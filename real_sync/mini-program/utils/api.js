const auth = require('./auth');

const DEFAULT_TIMEOUT = 30000;

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
  const token = auth.getToken();
  const header = Object.assign({ 'Content-Type': 'application/json' }, options.header || {});
  if (token) header.Authorization = `Bearer ${token}`;

  if (token && auth.isTokenExpired()) {
    if (options.redirectOnUnauthorized !== false) auth.redirectToLogin();
    return Promise.reject(normalizeError({ statusCode: 401, data: { code: 401, message: '登录已过期，请重新登录', data: null } }));
  }

  return new Promise((resolve, reject) => {
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
        console.error('请求失败:', url, err);
        const error = new Error(err && err.errMsg && err.errMsg.indexOf('timeout') >= 0 ? '请求超时，请稍后重试' : '网络请求失败，请检查网络后重试');
        error.original = err;
        error.url = url;
        reject(error);
      },
    });
  });
}

function get(url, options) {
  return request(Object.assign({}, options || {}, { url, method: 'GET' }));
}

function post(url, data, options) {
  return request(Object.assign({}, options || {}, { url, data, method: 'POST' }));
}

function uploadFile(options) {
  options = options || {};
  const app = getApp ? getApp() : null;
  const apiBase = options.apiBase || (app && app.globalData && app.globalData.apiBase) || 'https://supercalf.com/api';
  const url = /^https?:\/\//.test(options.url || '') ? options.url : `${apiBase}${options.url || ''}`;
  const token = auth.getToken();

  if (token && auth.isTokenExpired()) {
    if (options.redirectOnUnauthorized !== false) auth.redirectToLogin();
    return Promise.reject(normalizeError({ statusCode: 401, data: { code: 401, message: '登录已过期，请重新登录', data: null } }));
  }

  return new Promise((resolve, reject) => {
    wx.uploadFile({
      url,
      filePath: options.filePath,
      name: options.name || 'file',
      formData: options.formData || {},
      header: Object.assign({}, token ? { Authorization: `Bearer ${token}` } : {}, options.header || {}),
      timeout: options.timeout || 30000,
      success(res) {
        if (res.statusCode === 401) {
          if (options.redirectOnUnauthorized !== false) auth.redirectToLogin();
          reject(normalizeError(res, '登录已过期，请重新登录'));
          return;
        }
        if (res.statusCode >= 200 && res.statusCode < 300) {
          try {
            const data = JSON.parse(res.data);
            if (Number(data.code) === 0) {
              resolve(data);
              return;
            }
            reject(normalizeError({ statusCode: res.statusCode, data }, `上传失败：${data.message || '未知错误'}`));
          } catch (e) {
            resolve({ code: 0, message: 'success', data: { raw: res.data } });
          }
          return;
        }
        reject(normalizeError(res, `上传失败：${res.statusCode}`));
      },
      fail(err) {
        const error = new Error(err && err.errMsg && err.errMsg.indexOf('timeout') >= 0 ? '上传超时，请稍后重试' : '上传失败，请检查网络后重试');
        error.original = err;
        reject(error);
      }
    });
  });
}

module.exports = {
  request,
  get,
  post,
  normalizeError,
};
