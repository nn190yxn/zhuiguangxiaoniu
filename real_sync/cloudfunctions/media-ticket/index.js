const https = require('https');
const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');

const DEFAULT_UPSTREAM_ORIGIN = 'https://supercalf.com/api';
const MAX_RESPONSE_BYTES = 256 * 1024;
const PURPOSE_LIMITS = {
  workload_evidence: { minBytes: 512, maxBytes: 5 * 1024 * 1024, mimes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'] },
  profile_avatar: { minBytes: 512, maxBytes: 5 * 1024 * 1024, mimes: ['image/jpeg', 'image/png', 'image/webp'] },
  knowledge_media: { minBytes: 512, maxBytes: 20 * 1024 * 1024, mimes: ['image/jpeg', 'image/png', 'image/webp', 'audio/mpeg', 'audio/mp4', 'video/mp4'] },
  drill_audio: { minBytes: 512, maxBytes: 50 * 1024 * 1024, mimes: ['audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/aac'] }
};

function response(statusCode, body, extra = {}) {
  return Object.assign({
    upstream_status: statusCode,
    body,
    request_id: extra.requestId || '',
    route_id: 'POST /cloud/media-ingest.php',
    domain: 'media'
  }, extra.meta || {});
}

function createNonce() {
  return crypto.randomBytes(16).toString('hex');
}

function appendGatewaySignature(headers, method, path, body, secret, now) {
  if (!secret) return headers;
  const timestamp = String(now || Math.floor(Date.now() / 1000));
  const nonce = createNonce();
  const bodyHash = crypto.createHash('sha256').update(String(body || '')).digest('hex');
  const version = 'v1';
  const canonical = [method, path, timestamp, nonce, bodyHash, version].join('\n');
  const signature = crypto.createHmac('sha256', secret).update(canonical).digest('hex');
  return Object.assign({}, headers, {
    'x-cloud-signature-version': version,
    'x-cloud-timestamp': timestamp,
    'x-cloud-nonce': nonce,
    'x-cloud-body-sha256': bodyHash,
    'x-cloud-signature': signature
  });
}

function requireToken(value, code, maxLength = 128) {
  const token = String(value || '').trim();
  if (!token || token.length > maxLength || !/^[a-zA-Z0-9_:\/.@-]+$/.test(token)) {
    throw Object.assign(new Error('媒体参数无效'), { statusCode: 400, code });
  }
  return token;
}

function validateFile(file, purpose) {
  const policy = PURPOSE_LIMITS[purpose];
  if (!policy) throw Object.assign(new Error('媒体用途未登记'), { statusCode: 400, code: 'purpose_not_allowed' });
  const fileID = requireToken(file && (file.fileID || file.file_id), 'invalid_file_id', 256);
  const mimeType = String((file && (file.mime_type || file.mimeType)) || '').trim().toLowerCase();
  const byteSize = Number(file && (file.byte_size || file.size || 0));
  const sha256 = String((file && (file.sha256 || file.file_sha256)) || '').trim().toLowerCase();
  if (!policy.mimes.includes(mimeType)) throw Object.assign(new Error('媒体类型不支持'), { statusCode: 415, code: 'mime_not_allowed' });
  if (!Number.isFinite(byteSize) || byteSize < policy.minBytes || byteSize > policy.maxBytes) throw Object.assign(new Error('媒体大小不符合限制'), { statusCode: 413, code: 'file_size_invalid' });
  if (!/^[a-f0-9]{64}$/.test(sha256)) throw Object.assign(new Error('媒体摘要无效'), { statusCode: 400, code: 'invalid_sha256' });
  return { fileID, mime_type: mimeType, byte_size: byteSize, sha256 };
}

function validateEvent(event) {
  if (!event || typeof event !== 'object') throw Object.assign(new Error('事件不能为空'), { statusCode: 400, code: 'invalid_event' });
  if (Number(event.protocol_version) !== 1) throw Object.assign(new Error('协议版本不支持'), { statusCode: 400, code: 'invalid_protocol' });
  if (event.type !== 'media_ticket') throw Object.assign(new Error('事件类型不支持'), { statusCode: 400, code: 'invalid_type' });
  const purpose = requireToken(event.purpose, 'invalid_purpose', 64);
  const businessType = requireToken(event.business_type, 'invalid_business_type', 64);
  const businessId = requireToken(event.business_id, 'invalid_business_id', 128);
  const idempotencyKey = requireToken(event.idempotency_key, 'invalid_idempotency_key', 160);
  const file = validateFile(event.file || {}, purpose);
  return { purpose, business_type: businessType, business_id: businessId, idempotency_key: idempotencyKey, file };
}

function defaultTransport(requestOptions, body) {
  return new Promise((resolve, reject) => {
    const client = requestOptions.protocol === 'http:' ? http : https;
    const req = client.request(requestOptions, (res) => {
      const chunks = [];
      let total = 0;
      res.on('data', (chunk) => {
        total += chunk.length;
        if (total > MAX_RESPONSE_BYTES) {
          req.destroy(Object.assign(new Error('响应体过大'), { statusCode: 502, code: 'response_too_large' }));
          return;
        }
        chunks.push(chunk);
      });
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        let parsed = null;
        try { parsed = raw ? JSON.parse(raw) : null; } catch (error) { parsed = { code: 'invalid_json', message: '上游响应格式错误' }; }
        resolve({ statusCode: res.statusCode || 502, body: parsed });
      });
    });
    req.setTimeout(Number(requestOptions.timeout || 30000), () => req.destroy(Object.assign(new Error('上游请求超时'), { statusCode: 504, code: 'upstream_timeout' })));
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

function createHandler(options = {}) {
  const transport = options.transport || defaultTransport;
  const upstreamOrigin = options.upstreamOrigin || process.env.CLOUD_UPSTREAM_ORIGIN || DEFAULT_UPSTREAM_ORIGIN;
  const gatewaySecret = options.gatewaySecret || process.env.CLOUD_GATEWAY_SECRET || '';
  return async function main(event) {
    let payload;
    try {
      payload = validateEvent(event);
    } catch (error) {
      return response(error.statusCode || 400, { code: error.code || 'bad_request', message: error.message }, { requestId: event && event.request_id });
    }
    const requestId = String(event.request_id || '');
    const upstreamBase = new URL(upstreamOrigin);
    const upstreamBasePath = upstreamBase.pathname.replace(/\/$/, '');
    const path = `${upstreamBasePath}/cloud/media-ingest.php`;
    const body = JSON.stringify(payload);
    let headers = {
      'content-type': 'application/json',
      'content-length': Buffer.byteLength(body),
      'x-request-id': requestId,
      'idempotency-key': payload.idempotency_key
    };
    const normalizedHeaders = Object.fromEntries(Object.entries(event.header || {}).map(([key, value]) => [String(key).toLowerCase(), value]));
    if (normalizedHeaders.authorization) headers.authorization = normalizedHeaders.authorization;
    headers = appendGatewaySignature(headers, 'POST', path, body, gatewaySecret, options.now);
    const requestOptions = { protocol: upstreamBase.protocol, hostname: upstreamBase.hostname, port: upstreamBase.port || undefined, path, method: 'POST', timeout: Number(event.timeout || 30000), headers };
    try {
      const upstream = await transport(requestOptions, body, { id: 'POST /cloud/media-ingest.php', domain: 'media' });
      return response(upstream.statusCode || 502, upstream.body || null, { requestId });
    } catch (error) {
      return response(error.statusCode || 502, { code: error.code || 'upstream_error', message: error.message || '上游请求失败' }, { requestId });
    }
  };
}

exports.main = createHandler();
exports.createHandler = createHandler;
exports.validateEvent = validateEvent;
exports.appendGatewaySignature = appendGatewaySignature;
exports.PURPOSE_LIMITS = PURPOSE_LIMITS;
