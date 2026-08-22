const cloud = require('./cloud');
const direct = require('./direct');
const cloudConfig = require('../../config/cloud');

const SHADOW_STORAGE_KEY = 'shadow_request_diffs';
const SHADOW_MAX_RECORDS = 20;

function readStorage(key) {
  if (typeof wx === 'undefined' || typeof wx.getStorageSync !== 'function') return null;
  try {
    return wx.getStorageSync(key);
  } catch (error) {
    return null;
  }
}

function writeStorage(key, value) {
  if (typeof wx === 'undefined' || typeof wx.setStorageSync !== 'function') return;
  try {
    wx.setStorageSync(key, value);
  } catch (error) {
    console.error('写入影子核对记录失败:', error);
  }
}

function isReadOnlyRequest(options) {
  const method = String(options && options.method || 'GET').toUpperCase();
  return method === 'GET' || method === 'HEAD';
}

function stableValue(value, depth, sensitiveKey) {
  if (depth > 2) return '[depth-limit]';
  if (value === null || typeof value === 'undefined') return null;
  if (typeof value === 'string') {
    return /token|secret|password|phone|openid|authorization|cookie/i.test(String(sensitiveKey || ''))
      ? '[redacted]'
      : `string:${value.length}`;
  }
  if (typeof value === 'number' || typeof value === 'boolean') return `${typeof value}:${value}`;
  if (Array.isArray(value)) {
    return {
      kind: 'array',
      length: value.length,
      sample: value.slice(0, 3).map(item => stableValue(item, depth + 1))
    };
  }
  if (typeof value === 'object') {
    const keys = Object.keys(value).sort();
    const fields = {};
    for (const key of keys.slice(0, 20)) {
      fields[key] = stableValue(value[key], depth + 1, key);
    }
    return { kind: 'object', keys, fields };
  }
  return typeof value;
}

function responseDigest(response) {
  const payload = response && typeof response === 'object' ? response : {};
  const body = payload.data && typeof payload.data === 'object' ? payload.data : null;
  const businessCode = body && typeof body.code !== 'undefined' ? Number(body.code) : null;
  return {
    statusCode: Number(payload.statusCode || 0),
    businessCode: Number.isFinite(businessCode) ? businessCode : null,
    shape: stableValue(body, 0),
    summary: body && typeof body === 'object'
      ? {
        keys: Object.keys(body).sort(),
        code: typeof body.code !== 'undefined' ? Number(body.code) : null,
        message: typeof body.message === 'string' ? `string:${body.message.length}` : null,
        dataKeys: body.data && typeof body.data === 'object' ? Object.keys(body.data).sort() : []
      }
      : stableValue(body, 0)
  };
}

function classifyDifference(primaryDigest, shadowDigest) {
  const categories = [];
  if (primaryDigest.statusCode !== shadowDigest.statusCode) categories.push('http');
  if (primaryDigest.businessCode !== shadowDigest.businessCode) categories.push('business_code');
  if (JSON.stringify(primaryDigest.shape) !== JSON.stringify(shadowDigest.shape)) categories.push('field_structure');
  if ((primaryDigest.statusCode === 401 || primaryDigest.statusCode === 403 || primaryDigest.businessCode === 401 || primaryDigest.businessCode === 403)
    !== (shadowDigest.statusCode === 401 || shadowDigest.statusCode === 403 || shadowDigest.businessCode === 401 || shadowDigest.businessCode === 403)) {
    categories.push('permission_scope');
  }
  if (JSON.stringify(primaryDigest.summary) !== JSON.stringify(shadowDigest.summary)) categories.push('redacted_summary');
  return categories;
}

function persistShadowRecord(record) {
  const storedHistory = readStorage(SHADOW_STORAGE_KEY);
  const history = Array.isArray(storedHistory) ? storedHistory : [];
  const next = history.concat(record).slice(-SHADOW_MAX_RECORDS);
  writeStorage(SHADOW_STORAGE_KEY, next);
  writeStorage('last_shadow_request_diff', record);
}

function recordShadowComparison(options, primaryResponse, shadowResponse, status) {
  const primaryDigest = responseDigest(primaryResponse);
  const shadowDigest = responseDigest(shadowResponse);
  const record = {
    at: Date.now(),
    requestId: options && options.requestId ? String(options.requestId) : '',
    route: String(options && (options.route || options.url) || ''),
    method: String(options && options.method || 'GET').toUpperCase(),
    diffCategories: classifyDifference(primaryDigest, shadowDigest),
    primary: primaryDigest,
    shadow: shadowDigest
  };
  record.status = status || (record.diffCategories.length > 0 ? 'different' : 'matched');
  persistShadowRecord(record);
  return record;
}

function shouldShadow(options) {
  if (options && options.shadow === false) return false;
  const rate = Number(cloudConfig.SHADOW_SAMPLE_RATE || 0);
  return rate > 0 && Math.random() < rate;
}

function request(options) {
  const primary = cloud.request(options);
  if (!isReadOnlyRequest(options) || !shouldShadow(options)) {
    return primary;
  }
  Promise.resolve(primary).then(
    primaryResponse => Promise.resolve(direct.request(Object.assign({}, options, { shadow: false }))).then(
      shadowResponse => recordShadowComparison(options, primaryResponse, shadowResponse, 'matched'),
      error => recordShadowComparison(options, primaryResponse, { statusCode: 0, data: { code: 'shadow_request_failed', message: error && error.errMsg ? error.errMsg : '影子请求失败' } }, 'shadow_failed')
    ),
    primaryError => Promise.resolve(direct.request(Object.assign({}, options, { shadow: false }))).then(
      shadowResponse => recordShadowComparison(options, { statusCode: 0, data: { code: 'primary_request_failed', message: primaryError && primaryError.message ? primaryError.message : '主请求失败' } }, shadowResponse, 'primary_failed'),
      shadowError => recordShadowComparison(options, { statusCode: 0, data: { code: 'primary_request_failed', message: primaryError && primaryError.message ? primaryError.message : '主请求失败' } }, { statusCode: 0, data: { code: 'shadow_request_failed', message: shadowError && shadowError.errMsg ? shadowError.errMsg : '影子请求失败' } }, 'both_failed')
    )
  ).catch(() => {});
  return primary;
}

function uploadFile(options, onProgress) {
  return cloud.uploadFile(options, onProgress);
}

module.exports = { request, uploadFile };
