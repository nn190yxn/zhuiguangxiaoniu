const cloudConfig = require('../../config/cloud');

function normalizeResult(result) {
  const payload = result && typeof result === 'object' ? result : {};
  if (payload.upstream_status || payload.body) {
    return {
      statusCode: Number(payload.upstream_status || payload.statusCode || 200),
      data: payload.body || null
    };
  }
  if (payload.statusCode || payload.data) {
    return {
      statusCode: Number(payload.statusCode || 200),
      data: payload.data || null
    };
  }
  return { statusCode: 502, data: { code: 'cloud_protocol_error', message: '云函数响应格式错误' } };
}

function callFunction(name, data) {
  if (!wx.cloud || typeof wx.cloud.callFunction !== 'function') {
    return Promise.reject({ errMsg: 'wx.cloud.callFunction unavailable' });
  }
  return new Promise((resolve, reject) => {
    wx.cloud.callFunction({
      name,
      data,
      success(response) {
        resolve(normalizeResult(response && response.result));
      },
      fail: reject
    });
  });
}

function proxyName(url) {
  return String(url || '').includes('/auth/') || String(url || '').includes('/auth-jwt.php')
    ? cloudConfig.FUNCTIONS.AUTH_PROXY
    : cloudConfig.FUNCTIONS.API_PROXY;
}

function request(options) {
  return callFunction(proxyName(options.url), {
    protocol_version: 1,
    type: 'request',
    route: options.route || options.url,
    method: options.method || 'GET',
    data: options.data || {},
    header: options.header || {},
    timeout: options.timeout || 0,
    request_id: options.requestId || (options.header && options.header['X-Request-ID']) || ''
  });
}

function uploadFile(options) {
  return callFunction(cloudConfig.FUNCTIONS.MEDIA_TICKET, {
    protocol_version: 1,
    type: 'upload',
    route: options.route || options.url,
    filePath: options.filePath,
    name: options.name || 'file',
    formData: options.formData || {},
    header: options.header || {},
    timeout: options.timeout || 0,
    request_id: options.requestId || (options.header && options.header['X-Request-ID']) || ''
  });
}

module.exports = { request, uploadFile };
