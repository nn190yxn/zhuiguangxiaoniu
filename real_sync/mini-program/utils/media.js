const api = require('./api');
const tempFileCache = {};

function cleanPathPart(value, fallback = 'item') {
  const cleaned = String(value || '').replace(/[^a-zA-Z0-9_-]/g, '_').replace(/_+/g, '_').slice(0, 48);
  return cleaned || fallback;
}

function extensionFromPath(filePath) {
  const match = String(filePath || '').match(/\.([a-zA-Z0-9]+)(?:\?|$)/);
  return match ? match[1].toLowerCase() : 'bin';
}

function createCloudPath({ purpose, businessType, businessId, filePath }) {
  const date = new Date();
  const yyyy = date.getFullYear();
  const mm = String(date.getMonth() + 1).padStart(2, '0');
  const dd = String(date.getDate()).padStart(2, '0');
  const random = Math.random().toString(36).slice(2, 12);
  const ext = extensionFromPath(filePath);
  return [
    'mini-program-media',
    cleanPathPart(purpose, 'purpose'),
    `${yyyy}${mm}${dd}`,
    `${cleanPathPart(businessType, 'business')}_${cleanPathPart(businessId, '0')}_${Date.now()}_${random}.${ext}`
  ].join('/');
}

function fileInfo(filePath) {
  if (typeof wx.getFileInfo !== 'function') return Promise.resolve({ sha256: '', byte_size: 0 });
  return new Promise(resolve => {
    wx.getFileInfo({
      filePath,
      digestAlgorithm: 'sha256',
      success(result) {
        resolve({ sha256: String(result.digest || '').toLowerCase(), byte_size: Number(result.size || 0) });
      },
      fail() {
        resolve({ sha256: '', byte_size: 0 });
      }
    });
  });
}

function uploadFileToCloud(options = {}) {
  const cloudPath = options.cloudPath || createCloudPath(options);
  if (!wx.cloud || typeof wx.cloud.uploadFile !== 'function') {
    return Promise.reject(new Error('当前环境未开启云存储'));
  }
  return Promise.all([
    fileInfo(options.filePath),
    new Promise((resolve, reject) => {
      wx.cloud.uploadFile({
        cloudPath,
        filePath: options.filePath,
        success: resolve,
        fail: reject
      });
    })
  ]).then(([info, upload]) => ({
    cloudPath,
    fileID: upload.fileID,
    sha256: options.sha256 || info.sha256,
    byte_size: Number(options.byte_size || info.byte_size || 0),
    mime_type: String(options.mime_type || options.mimeType || '').trim()
  }));
}

function registerCloudMedia(options = {}) {
  const app = typeof getApp === 'function' ? getApp() : null;
  const requestId = api.createRequestId();
  const idempotencyKey = options.idempotencyKey || api.createIdempotencyKey(`media_${options.purpose || 'file'}`);
  const event = {
    protocol_version: 1,
    type: 'media_ticket',
    request_id: requestId,
    purpose: options.purpose,
    business_type: options.businessType || options.business_type,
    business_id: String(options.businessId || options.business_id || ''),
    idempotency_key: idempotencyKey,
    file: {
      fileID: options.fileID,
      mime_type: options.mime_type || options.mimeType,
      byte_size: options.byte_size,
      sha256: options.sha256
    },
    header: {
      'X-Request-ID': requestId,
      'Idempotency-Key': idempotencyKey,
      Authorization: app && app.globalData && app.globalData.token ? `Bearer ${app.globalData.token}` : ''
    }
  };
  return new Promise((resolve, reject) => {
    wx.cloud.callFunction({
      name: 'media-ticket',
      data: event,
      success(result) {
        const envelope = result && result.result ? result.result : {};
        if (envelope.upstream_status >= 200 && envelope.upstream_status < 300 && envelope.body && Number(envelope.body.code) === 0) {
          resolve(envelope.body.data || {});
          return;
        }
        reject(new Error((envelope.body && envelope.body.message) || '媒体登记失败'));
      },
      fail: reject
    });
  });
}

function uploadAndRegister(options = {}) {
  return uploadFileToCloud(options).then(file => registerCloudMedia(Object.assign({}, options, file)));
}

function normalizeMediaDescriptor(value, fieldName = '') {
  if (!value) {
    return { ready: false, source: 'empty', field: fieldName, url: '', fileID: '', asset_key: '' };
  }
  if (typeof value === 'string') {
    const url = value.trim();
    return {
      ready: !!url,
      source: url.startsWith('cloud://') ? 'cloud_file' : 'legacy_url',
      field: fieldName,
      url: url.startsWith('cloud://') ? '' : url,
      fileID: url.startsWith('cloud://') ? url : '',
      asset_key: ''
    };
  }
  if (typeof value === 'object') {
    const fileID = String(value.fileID || value.file_id || '').trim();
    const url = String(value.url || value.media_url || value.file_url || '').trim();
    const assetKey = String(value.asset_key || value.assetKey || '').trim();
    return {
      ready: Boolean(fileID || url || assetKey),
      source: fileID ? 'cloud_file' : (assetKey ? 'cloud_asset' : 'legacy_url'),
      field: fieldName,
      url,
      fileID,
      asset_key: assetKey,
      status: String(value.status || (fileID || url || assetKey ? 'ready' : 'pending')),
      retry_count: Number(value.retry_count || value.retryCount || 0),
      error_code: String(value.error_code || value.errorCode || ''),
      recovery_required: Boolean(value.recovery_required || value.recoveryRequired),
      mime_type: String(value.mime_type || value.mimeType || '').trim(),
      sha256: String(value.sha256 || value.file_sha256 || '').trim(),
      byte_size: Number(value.byte_size || value.size || 0)
    };
  }
  return { ready: false, source: 'unknown', field: fieldName, url: '', fileID: '', asset_key: '' };
}

function normalizeMediaField(record, fieldName) {
  const item = Object.assign({}, record || {});
  const descriptor = normalizeMediaDescriptor(item[fieldName], fieldName);
  item[`${fieldName}_media`] = descriptor;
  if (!item[fieldName] && descriptor.url) item[fieldName] = descriptor.url;
  return item;
}

function normalizeMediaFields(record, fieldNames = []) {
  return fieldNames.reduce((item, fieldName) => normalizeMediaField(item, fieldName), record || {});
}

function getPlayableTempFile(descriptor) {
  const media = normalizeMediaDescriptor(descriptor);
  const cacheKey = media.asset_key || media.fileID;
  if (!media.fileID) return Promise.reject(new Error('媒体文件未就绪'));
  if (cacheKey && tempFileCache[cacheKey]) return Promise.resolve(tempFileCache[cacheKey]);
  if (!wx.cloud || typeof wx.cloud.downloadFile !== 'function') return Promise.reject(new Error('当前环境未开启云文件下载'));
  return new Promise((resolve, reject) => {
    wx.cloud.downloadFile({
      fileID: media.fileID,
      success(result) {
        const tempFilePath = result.tempFilePath || '';
        if (cacheKey && tempFilePath) tempFileCache[cacheKey] = tempFilePath;
        resolve(tempFilePath);
      },
      fail: reject
    });
  });
}

function clearMediaCache(assetKey) {
  if (assetKey) {
    delete tempFileCache[assetKey];
    return;
  }
  Object.keys(tempFileCache).forEach(key => delete tempFileCache[key]);
}

module.exports = {
  createCloudPath,
  uploadFileToCloud,
  registerCloudMedia,
  uploadAndRegister,
  normalizeMediaDescriptor,
  normalizeMediaField,
  normalizeMediaFields,
  getPlayableTempFile,
  clearMediaCache,
};
