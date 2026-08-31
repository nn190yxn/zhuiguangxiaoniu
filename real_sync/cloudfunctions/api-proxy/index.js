const https = require('https');
const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');
const matrix = require('./business-domain-matrix.json');

const DEFAULT_UPSTREAM_ORIGIN = 'https://supercalf.com/api';
const MAX_REQUEST_BYTES = 256 * 1024;
const MAX_RESPONSE_BYTES = 1024 * 1024;
const FORWARDED_HEADERS = ['authorization', 'x-request-id', 'idempotency-key', 'x-state-version'];

function endpointSpec(endpoint) {
  const parsed = new URL(String(endpoint || ''), DEFAULT_UPSTREAM_ORIGIN);
  return {
    path: parsed.pathname,
    action: parsed.searchParams.get('action') || ''
  };
}

function buildRouteRegistry() {
  const entries = [];
  const seen = new Set();
  for (const domain of matrix.migration_domains || []) {
    for (const endpoint of domain.endpoints || []) {
      const method = String(endpoint.method || 'GET').toUpperCase();
      if (method === 'UPLOAD') continue;
      const spec = endpointSpec(endpoint.path);
      const id = `${method} ${spec.path}${spec.action ? `?action=${spec.action}` : ''}`;
      if (seen.has(id)) continue;
      seen.add(id);
      entries.push({
        id,
        path: spec.path,
        action: spec.action,
        method,
        domain: domain.id,
        auth: endpoint.auth !== false,
        idempotency: endpoint.idempotency === true,
        sideEffects: endpoint.side_effect ? [domain.id] : []
      });
    }
  }
  return entries;
}

const ROUTES = buildRouteRegistry();
const DEFAULT_IDEMPOTENCY_STORE = createMemoryIdempotencyStore();

function response(statusCode, body, extra = {}) {
  return Object.assign({
    upstream_status: statusCode,
    body,
    request_id: extra.requestId || '',
    route_id: extra.routeId || '',
    domain: extra.domain || ''
  }, extra.meta || {});
}

function normalizeHeaders(headers) {
  const normalized = {};
  for (const [key, value] of Object.entries(headers || {})) {
    normalized[String(key).toLowerCase()] = value;
  }
  return normalized;
}

function forwardedHeaders(headers) {
  const normalized = normalizeHeaders(headers);
  const result = {};
  for (const key of FORWARDED_HEADERS) {
    if (typeof normalized[key] !== 'undefined' && normalized[key] !== '') {
      result[key] = normalized[key];
    }
  }
  return result;
}

function createNonce() {
  return crypto.randomBytes(16).toString('hex');
}

function staffDigest(headers, data) {
  const source = JSON.stringify({
    authorization: headers.authorization ? 'present' : '',
    staff_id: data && data.staff_id ? data.staff_id : '',
    employee_id: data && data.employee_id ? data.employee_id : ''
  });
  return crypto.createHash('sha256').update(source).digest('hex').slice(0, 16);
}

function requestDigest(method, path, body) {
  return crypto.createHash('sha256').update(JSON.stringify({ method, path, body: body || '' })).digest('hex');
}

function createMemoryIdempotencyStore() {
  const rows = new Map();
  return {
    get(key) {
      return rows.get(key) || null;
    },
    set(key, value) {
      rows.set(key, value);
    },
    clear() {
      rows.clear();
    }
  };
}

function writeLog(logger, entry) {
  if (typeof logger !== 'function') return;
  logger({
    route_id: entry.routeId || '',
    domain: entry.domain || '',
    status: Number(entry.status || 0),
    duration_ms: Number(entry.durationMs || 0),
    request_id: entry.requestId || '',
    staff_digest: entry.staffDigest || ''
  });
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

function parseRoute(route) {
  const raw = String(route || '').trim();
  if (!raw || !raw.startsWith('/')) throw Object.assign(new Error('route 必须是相对路径'), { statusCode: 400, code: 'invalid_route' });
  if (/^\/\//.test(raw) || /^https?:\/\//i.test(raw)) throw Object.assign(new Error('route 不能是外部地址'), { statusCode: 400, code: 'invalid_route' });
  const parsed = new URL(raw, DEFAULT_UPSTREAM_ORIGIN);
  return { raw, path: parsed.pathname, search: parsed.search, action: parsed.searchParams.get('action') || '' };
}

function findRoute(method, parsedRoute) {
  return ROUTES.find((route) => route.method === method && route.path === parsedRoute.path && route.action === parsedRoute.action) || null;
}

function byteLength(value) {
  return Buffer.byteLength(JSON.stringify(value || {}));
}

function validateEvent(event) {
  if (!event || typeof event !== 'object') throw Object.assign(new Error('事件不能为空'), { statusCode: 400, code: 'invalid_event' });
  if (Number(event.protocol_version) !== 1) throw Object.assign(new Error('协议版本不支持'), { statusCode: 400, code: 'invalid_protocol' });
  if (event.type !== 'request') throw Object.assign(new Error('事件类型不支持'), { statusCode: 400, code: 'invalid_type' });
  const method = String(event.method || 'GET').toUpperCase();
  const parsedRoute = parseRoute(event.route);
  const route = findRoute(method, parsedRoute);
  if (!route) throw Object.assign(new Error('路由未登记'), { statusCode: 404, code: 'route_not_allowed' });
  if (byteLength(event.data) > MAX_REQUEST_BYTES) throw Object.assign(new Error('请求体过大'), { statusCode: 413, code: 'request_too_large' });
  return { method, parsedRoute, route };
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
        try {
          parsed = raw ? JSON.parse(raw) : null;
        } catch (error) {
          parsed = { code: 'invalid_json', message: '上游响应格式错误' };
        }
        resolve({ statusCode: res.statusCode || 502, body: parsed });
      });
    });
    req.setTimeout(Number(requestOptions.timeout || 15000), () => req.destroy(Object.assign(new Error('上游请求超时'), { statusCode: 504, code: 'upstream_timeout' })));
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

function createHandler(options = {}) {
  const transport = options.transport || defaultTransport;
  const upstreamOrigin = options.upstreamOrigin || process.env.CLOUD_UPSTREAM_ORIGIN || DEFAULT_UPSTREAM_ORIGIN;
  const gatewaySecret = options.gatewaySecret || process.env.CLOUD_GATEWAY_SECRET || '';
  const logger = options.logger || null;
  const idempotencyStore = options.idempotencyStore || DEFAULT_IDEMPOTENCY_STORE;
  return async function main(event) {
    const startedAt = Date.now();
    let validated;
    try {
      validated = validateEvent(event);
    } catch (error) {
      return response(error.statusCode || 400, { code: error.code || 'bad_request', message: error.message }, { requestId: event && event.request_id });
    }

    const requestId = String(event.request_id || (event.header && event.header['X-Request-ID']) || '');
    const upstreamBase = new URL(upstreamOrigin);
    const upstreamBasePath = upstreamBase.pathname.replace(/\/$/, '');
    const upstreamUrl = new URL(`${upstreamBasePath}${validated.parsedRoute.path}${validated.parsedRoute.search}`, `${upstreamBase.protocol}//${upstreamBase.host}`);
    const body = validated.method === 'GET' ? '' : JSON.stringify(event.data || {});
    let headers = Object.assign({
      'content-type': 'application/json',
      'x-request-id': requestId
    }, forwardedHeaders(event.header || {}));
    if (body) headers['content-length'] = Buffer.byteLength(body);
    headers = appendGatewaySignature(headers, validated.method, `${upstreamUrl.pathname}${upstreamUrl.search}`, body, gatewaySecret, options.now);
    const digest = staffDigest(headers, event.data || {});
    const idempotencyKey = headers['idempotency-key'] || '';
    const idempotencyDigest = requestDigest(validated.method, `${upstreamUrl.pathname}${upstreamUrl.search}`, body);

    if (validated.route.idempotency && idempotencyKey && idempotencyStore) {
      const prior = idempotencyStore.get(idempotencyKey);
      if (prior && prior.digest === idempotencyDigest) {
        writeLog(logger, { routeId: validated.route.id, domain: validated.route.domain, status: prior.result.upstream_status, durationMs: Date.now() - startedAt, requestId, staffDigest: digest });
        return prior.result;
      }
      if (prior && prior.digest !== idempotencyDigest) {
        writeLog(logger, { routeId: validated.route.id, domain: validated.route.domain, status: 409, durationMs: Date.now() - startedAt, requestId, staffDigest: digest });
        return response(409, {
          code: 409,
          message: '幂等键已用于不同请求',
          data: {
            conflict_type: 'idempotency_key_reuse',
            recovery_action: 'reload'
          }
        }, { requestId, routeId: validated.route.id, domain: validated.route.domain });
      }
    }

    const requestOptions = {
      protocol: upstreamUrl.protocol,
      hostname: upstreamUrl.hostname,
      port: upstreamUrl.port || undefined,
      path: `${upstreamUrl.pathname}${upstreamUrl.search}`,
      method: validated.method,
      timeout: Number(event.timeout || 15000),
      headers
    };

    try {
      const upstream = await transport(requestOptions, body, validated.route);
      if (byteLength(upstream.body) > MAX_RESPONSE_BYTES) {
        writeLog(logger, { routeId: validated.route.id, domain: validated.route.domain, status: 502, durationMs: Date.now() - startedAt, requestId, staffDigest: digest });
        return response(502, { code: 'response_too_large', message: '上游响应体过大' }, { requestId, routeId: validated.route.id, domain: validated.route.domain });
      }
      writeLog(logger, { routeId: validated.route.id, domain: validated.route.domain, status: upstream.statusCode || 502, durationMs: Date.now() - startedAt, requestId, staffDigest: digest });
      const result = response(upstream.statusCode || 502, upstream.body || null, { requestId, routeId: validated.route.id, domain: validated.route.domain });
      if (validated.route.idempotency && idempotencyKey && idempotencyStore && result.upstream_status >= 200 && result.upstream_status < 300) {
        idempotencyStore.set(idempotencyKey, { digest: idempotencyDigest, result });
      }
      return result;
    } catch (error) {
      writeLog(logger, { routeId: validated.route.id, domain: validated.route.domain, status: error.statusCode || 502, durationMs: Date.now() - startedAt, requestId, staffDigest: digest });
      return response(error.statusCode || 502, { code: error.code || 'upstream_error', message: error.message || '上游请求失败' }, { requestId, routeId: validated.route.id, domain: validated.route.domain });
    }
  };
}

exports.main = createHandler();
exports.createHandler = createHandler;
exports.validateEvent = validateEvent;
exports.buildRouteRegistry = buildRouteRegistry;
exports.appendGatewaySignature = appendGatewaySignature;
exports.staffDigest = staffDigest;
exports.requestDigest = requestDigest;
exports.createMemoryIdempotencyStore = createMemoryIdempotencyStore;
