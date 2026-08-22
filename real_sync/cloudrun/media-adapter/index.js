const http = require('http');
const crypto = require('crypto');
const { Readable } = require('stream');

const MAX_JSON_BYTES = 1024 * 1024;
const MAX_FILE_BYTES = 50 * 1024 * 1024;
const DEFAULT_MAX_ATTEMPTS = 3;
const DRILL_CHUNK_BYTES = 5 * 1024 * 1024;
const PURPOSE_POLICIES = {
  workload_evidence: { maxBytes: 5 * 1024 * 1024, mimes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], adapter: 'workloadImage' },
  profile_avatar: { maxBytes: 5 * 1024 * 1024, mimes: ['image/jpeg', 'image/png', 'image/webp'], adapter: 'genericMedia' },
  knowledge_media: { maxBytes: 20 * 1024 * 1024, mimes: ['image/jpeg', 'image/png', 'image/webp', 'audio/mpeg', 'audio/mp4', 'video/mp4'], adapter: 'genericMedia' },
  drill_audio: { maxBytes: 50 * 1024 * 1024, mimes: ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/x-m4a'], adapter: 'drillAudio' }
};

function json(statusCode, body) {
  return { statusCode, body };
}

function detectMime(buffer) {
  if (buffer.length >= 3 && buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) return 'image/jpeg';
  if (buffer.length >= 8 && buffer.slice(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) return 'image/png';
  if (buffer.length >= 6 && ['GIF87a', 'GIF89a'].includes(buffer.slice(0, 6).toString('ascii'))) return 'image/gif';
  if (buffer.length >= 12 && buffer.slice(0, 4).toString('ascii') === 'RIFF' && buffer.slice(8, 12).toString('ascii') === 'WEBP') return 'image/webp';
  if (buffer.length >= 12 && buffer.slice(4, 8).toString('ascii') === 'ftyp' && buffer.slice(8, 11).toString('ascii') === 'M4A') return 'audio/x-m4a';
  if (buffer.length >= 12 && buffer.slice(4, 8).toString('ascii') === 'ftyp') return 'video/mp4';
  if (buffer.length >= 3 && buffer.slice(0, 3).toString('ascii') === 'ID3') return 'audio/mpeg';
  if (buffer.length >= 2 && buffer[0] === 0xff && (buffer[1] & 0xf6) === 0xf0) return 'audio/aac';
  if (buffer.length >= 12 && buffer.slice(0, 4).toString('ascii') === 'RIFF' && buffer.slice(8, 12).toString('ascii') === 'WAVE') return 'audio/wav';
  if (buffer.length >= 12 && buffer.slice(0, 4).toString('ascii') === 'OggS') return 'audio/ogg';
  if (buffer.length >= 4 && buffer.slice(0, 4).equals(Buffer.from([0x1a, 0x45, 0xdf, 0xa3]))) return 'audio/webm';
  return 'application/octet-stream';
}

function normalizeMime(mimeType) {
  const value = String(mimeType || '').toLowerCase();
  if (value === 'audio/x-wav') return 'audio/wav';
  if (value === 'audio/m4a') return 'audio/x-m4a';
  if (value === 'audio/aac') return 'audio/aac';
  return value;
}

function purposePolicy(purpose) {
  const policy = PURPOSE_POLICIES[purpose];
  if (!policy) throw Object.assign(new Error('媒体用途未登记'), { statusCode: 400, code: 'purpose_not_allowed' });
  return policy;
}

function toReadable(value) {
  if (!value) return Readable.from([]);
  if (Buffer.isBuffer(value)) return Readable.from([value]);
  if (typeof value.pipe === 'function') return value;
  if (typeof value === 'string') return Readable.from([Buffer.from(value)]);
  return Readable.from(value);
}

async function readLimitedStream(stream, maxBytes = MAX_FILE_BYTES) {
  const chunks = [];
  let total = 0;
  for await (const chunk of toReadable(stream)) {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    total += buffer.length;
    if (total > maxBytes) {
      throw Object.assign(new Error('媒体内容大小无效'), { statusCode: 413, code: 'file_size_invalid' });
    }
    chunks.push(buffer);
  }
  return Buffer.concat(chunks);
}

function verifyPayload(payload) {
  const policy = purposePolicy(String(payload.purpose || ''));
  const content = Buffer.from(String(payload.content_base64 || ''), 'base64');
  if (!content.length || content.length > policy.maxBytes) {
    throw Object.assign(new Error('媒体内容大小无效'), { statusCode: 413, code: 'file_size_invalid' });
  }
  const actualSha256 = crypto.createHash('sha256').update(content).digest('hex');
  const declaredSha256 = String(payload.sha256 || '').toLowerCase();
  if (!/^[a-f0-9]{64}$/.test(declaredSha256) || declaredSha256 !== actualSha256) {
    throw Object.assign(new Error('媒体摘要不一致'), { statusCode: 409, code: 'sha256_mismatch' });
  }
  const actualMime = detectMime(content);
  const declaredMime = normalizeMime(payload.mime_type);
  if (declaredMime && declaredMime !== actualMime) {
    throw Object.assign(new Error('媒体类型不一致'), { statusCode: 415, code: 'mime_mismatch' });
  }
  if (!policy.mimes.includes(actualMime)) {
    throw Object.assign(new Error('媒体类型不支持'), { statusCode: 415, code: 'mime_not_allowed' });
  }
  return {
    asset_key: String(payload.asset_key || ''),
    purpose: String(payload.purpose || ''),
    business_type: String(payload.business_type || ''),
    business_id: String(payload.business_id || ''),
    mime_type: actualMime,
    byte_size: content.length,
    sha256: actualSha256,
    content
  };
}

function createAdapterPayload(verified) {
  const policy = purposePolicy(verified.purpose);
  const common = adapterForwardPayload(verified);
  const adapters = {
    workloadImage: Object.assign({}, common, { target_field: 'image_file', retention_policy: 'workload_evidence' }),
    drillAudio: Object.assign({}, common, { target_field: 'audio_file', retention_policy: 'drill_audio', chunk_contract: 'drill-v2' }),
    genericMedia: Object.assign({}, common, { target_field: 'media_file', retention_policy: 'controlled_media' })
  };
  return adapters[policy.adapter] || adapters.genericMedia;
}

function positiveInteger(value, code) {
  const number = Number(value);
  if (!Number.isInteger(number) || number <= 0) {
    throw Object.assign(new Error('Drill 音频参数无效'), { statusCode: 400, code });
  }
  return number;
}

function nonNegativeInteger(value, code) {
  const number = Number(value);
  if (!Number.isInteger(number) || number < 0) {
    throw Object.assign(new Error('Drill 音频参数无效'), { statusCode: 400, code });
  }
  return number;
}

function drillOperationKey(assetKey, step) {
  return `drill-audio:${assetKey}:${step}`;
}

function buildDrillV2Operations(verified, task = {}) {
  if (verified.purpose !== 'drill_audio') {
    throw Object.assign(new Error('媒体用途不是 Drill 音频'), { statusCode: 400, code: 'invalid_drill_media' });
  }
  const attemptId = positiveInteger(task.attempt_id || task.attemptId || verified.business_id, 'invalid_attempt_id');
  const statusVersion = nonNegativeInteger(task.status_version || task.statusVersion || 0, 'invalid_status_version');
  const durationMs = nonNegativeInteger(task.duration_ms || task.durationMs || 0, 'invalid_duration_ms');
  const finalTranscriptText = String(task.final_transcript_text || task.finalTranscriptText || '');
  const provider = String(task.provider || 'wechat_recorder');
  const assetKey = verified.asset_key || `drill-${verified.sha256.slice(0, 32)}`;
  const chunks = [];
  const expectedChunks = Math.max(1, Math.ceil(verified.content.length / DRILL_CHUNK_BYTES));
  for (let index = 0; index < expectedChunks; index += 1) {
    const content = verified.content.slice(index * DRILL_CHUNK_BYTES, Math.min(verified.content.length, (index + 1) * DRILL_CHUNK_BYTES));
    const checksum = crypto.createHash('sha256').update(content).digest('hex');
    chunks.push({
      endpoint: '/drill/v2/audio-chunks.php',
      idempotency_key: drillOperationKey(assetKey, `chunk-${index + 1}`),
      chunk_content: content,
      body: {
        audio_asset_id: '$audio_asset_id',
        chunk_no: index + 1,
        checksum,
        byte_size: content.length,
        cloud_media_asset_key: assetKey
      }
    });
  }
  return [
    {
      endpoint: '/drill/v2/audio-assets.php',
      idempotency_key: drillOperationKey(assetKey, 'asset'),
      body: {
        attempt_id: attemptId,
        mime_type: verified.mime_type,
        byte_size: verified.byte_size,
        checksum: verified.sha256,
        duration_ms: durationMs,
        consent_status: String(task.consent_status || 'not_required'),
        cloud_media_asset_key: assetKey,
        cloud_file_id: String(task.fileID || task.file_id || '')
      }
    },
    ...chunks,
    {
      endpoint: '/drill/v2/turns/finalize.php',
      idempotency_key: drillOperationKey(assetKey, 'finalize'),
      body: {
        audio_asset_id: '$audio_asset_id',
        attempt_id: attemptId,
        status_version: statusVersion,
        expected_chunks: expectedChunks,
        provider,
        final_transcript_text: finalTranscriptText,
        cloud_media_asset_key: assetKey
      }
    }
  ];
}

async function forwardDrillV2Audio(verified, task = {}, options = {}) {
  const operations = buildDrillV2Operations(verified, task);
  if (typeof options.forwardDrillOperation !== 'function') {
    return { status: 'ready', operations: operations.map(({ chunk_content, ...operation }) => operation), asset_key: verified.asset_key, attempt: options.attempt || 1 };
  }
  let audioAssetId = null;
  const results = [];
  for (const operation of operations) {
    const body = Object.assign({}, operation.body);
    if (body.audio_asset_id === '$audio_asset_id') body.audio_asset_id = audioAssetId;
    const result = await options.forwardDrillOperation(Object.assign({}, operation, { body }));
    if (!audioAssetId && result && (result.audio_asset_id || result.id)) audioAssetId = result.audio_asset_id || result.id;
    results.push(result || null);
  }
  return { status: 'ready', audio_asset_id: audioAssetId, operations: operations.length, results, attempt: options.attempt || 1 };
}

async function retryOperation(operation, options = {}) {
  const maxAttempts = Number(options.maxAttempts || DEFAULT_MAX_ATTEMPTS);
  let lastError = null;
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      return await operation(attempt);
    } catch (error) {
      lastError = error;
      if (attempt >= maxAttempts || error.retryable === false) break;
    }
  }
  throw lastError;
}

async function processMediaTask(task, options = {}) {
  const policy = purposePolicy(String(task.purpose || ''));
  const content = task.content_base64
    ? Buffer.from(String(task.content_base64), 'base64')
    : await readLimitedStream(await options.readCloudFile(String(task.fileID || task.file_id || '')), policy.maxBytes);
  const verified = verifyPayload(Object.assign({}, task, { content_base64: content.toString('base64') }));
  const forwardPayload = createAdapterPayload(verified);
  const result = await retryOperation(async (attempt) => {
    if (verified.purpose === 'drill_audio') {
      return forwardDrillV2Audio(verified, task, Object.assign({}, options, { attempt }));
    }
    if (typeof options.forward !== 'function') {
      return { status: 'ready', asset_key: verified.asset_key, attempt };
    }
    return options.forward(forwardPayload, { attempt, maxAttempts: Number(options.maxAttempts || DEFAULT_MAX_ATTEMPTS) });
  }, { maxAttempts: options.maxAttempts });
  return {
    status: 'ready',
    retry_count: Math.max(0, Number(result && result.attempt || 1) - 1),
    adapter: purposePolicy(verified.purpose).adapter,
    media: Object.assign({}, forwardPayload, { content_base64: undefined }),
    upstream: result
  };
}

function adapterForwardPayload(verified) {
  return {
    asset_key: verified.asset_key,
    purpose: verified.purpose,
    business_type: verified.business_type,
    business_id: verified.business_id,
    mime_type: verified.mime_type,
    byte_size: verified.byte_size,
    sha256: verified.sha256,
    content_base64: verified.content.toString('base64')
  };
}

function stableHash(value) {
  return crypto.createHash('sha256').update(String(value || '')).digest('hex');
}

function sourceFingerprint(source = {}) {
  const sourceUrl = String(source.source_url || source.url || '').trim();
  const version = String(source.source_version || source.updated_at || source.etag || '').trim();
  const declaredSize = String(source.byte_size || source.size || '').trim();
  if (!sourceUrl || sourceUrl.length > 2048 || !/^https?:\/\//.test(sourceUrl)) {
    throw Object.assign(new Error('历史媒体来源无效'), { statusCode: 400, code: 'invalid_source_url' });
  }
  return stableHash([sourceUrl, version, declaredSize].join('\n'));
}

function createMemoryMappingStore(initial = []) {
  const rows = new Map();
  initial.forEach((row) => rows.set(row.source_fingerprint, Object.assign({}, row)));
  return {
    findByFingerprint(fingerprint) {
      return rows.get(fingerprint) || null;
    },
    savePending(row) {
      const existing = rows.get(row.source_fingerprint);
      if (existing) return existing;
      const stored = Object.assign({ status: 'pending', retry_count: 0, error_code: '' }, row);
      rows.set(stored.source_fingerprint, stored);
      return stored;
    },
    all() {
      return Array.from(rows.values());
    }
  };
}

function createMirrorTask(input = {}, store = createMemoryMappingStore()) {
  const fingerprint = sourceFingerprint(input);
  const existing = store.findByFingerprint(fingerprint);
  if (existing && existing.status === 'ready') return Object.assign({ reused: true, recovery_required: false }, existing);
  if (existing && ['failed', 'expired'].includes(existing.status)) return Object.assign({ reused: true, recovery_required: true }, existing);
  if (existing) return Object.assign({ reused: true, recovery_required: false }, existing);
  const assetKey = input.asset_key || `legacy-media-${fingerprint.slice(0, 32)}`;
  return store.savePending({
    asset_key: assetKey,
    source_fingerprint: fingerprint,
    source_url: String(input.source_url || input.url || '').trim(),
    purpose: String(input.purpose || 'knowledge_media'),
    business_type: String(input.business_type || input.businessType || 'legacy_media'),
    business_id: String(input.business_id || input.businessId || assetKey),
    cloud_file_id: String(input.cloud_file_id || input.fileID || ''),
    mime_type: String(input.mime_type || '').toLowerCase(),
    byte_size: Number(input.byte_size || input.size || 0),
    sha256: String(input.sha256 || '').toLowerCase(),
    status: 'pending',
    retry_count: 0,
    error_code: ''
  });
}

function prewarmHistoricalMedia(items = [], options = {}) {
  const limit = Math.min(Number(options.limit || 100), 500);
  const store = options.store || createMemoryMappingStore();
  if (!Array.isArray(items)) throw Object.assign(new Error('预热清单格式无效'), { statusCode: 400, code: 'invalid_manifest' });
  return items.slice(0, limit).map((item) => createMirrorTask(item, store));
}

function mediaTaskStatus(row = {}, options = {}) {
  const status = String(row.status || 'pending');
  const retryCount = Number(row.retry_count || 0);
  const maxAttempts = Number(options.maxAttempts || DEFAULT_MAX_ATTEMPTS);
  const expiresAt = row.expires_at ? Date.parse(row.expires_at) : 0;
  const now = Number(options.now || Date.now());
  if (expiresAt && expiresAt <= now) {
    return { status: 'expired', retry_count: retryCount, error_code: 'media_expired', retryable: true, recovery_required: true };
  }
  if (status === 'ready') {
    return { status, retry_count: retryCount, error_code: '', retryable: false, recovery_required: false };
  }
  if (status === 'failed') {
    return { status, retry_count: retryCount, error_code: String(row.error_code || 'media_failed'), retryable: retryCount < maxAttempts, recovery_required: true };
  }
  if (status === 'expired') {
    return { status, retry_count: retryCount, error_code: String(row.error_code || 'media_expired'), retryable: true, recovery_required: true };
  }
  return { status: 'pending', retry_count: retryCount, error_code: String(row.error_code || ''), retryable: true, recovery_required: false };
}

function recoverMediaTask(row = {}, options = {}) {
  const current = mediaTaskStatus(row, options);
  if (!current.recovery_required) return Object.assign({}, row, current, { action: 'reuse' });
  if (!current.retryable) return Object.assign({}, row, current, { action: 'manual_review' });
  return Object.assign({}, row, current, {
    action: 'retry',
    status: 'pending',
    retry_count: Number(row.retry_count || 0) + 1,
    error_code: ''
  });
}

async function handleAdapterRequest(payload, options = {}) {
  try {
    const result = await processMediaTask(payload || {}, options);
    return json(200, { code: 0, data: result.upstream || result });
  } catch (error) {
    return json(error.statusCode || 400, { code: error.code || 'media_adapter_error', message: error.message, retryable: error.retryable !== false });
  }
}

function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let total = 0;
    req.on('data', (chunk) => {
      total += chunk.length;
      if (total > MAX_JSON_BYTES) {
        reject(Object.assign(new Error('请求体过大'), { statusCode: 413 }));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}')); } catch (error) { reject(Object.assign(new Error('JSON格式无效'), { statusCode: 400 })); }
    });
    req.on('error', reject);
  });
}

function createServer(options = {}) {
  return http.createServer(async (req, res) => {
    if (req.method === 'GET' && req.url === '/health') {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ status: 'ok' }));
      return;
    }
    if (req.method !== 'POST' || req.url !== '/media/process') {
      res.writeHead(404, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ code: 'not_found', message: '路由不存在' }));
      return;
    }
    const payload = await readJsonBody(req);
    const result = await handleAdapterRequest(payload, options);
    res.writeHead(result.statusCode, { 'content-type': 'application/json' });
    res.end(JSON.stringify(result.body));
  });
}

if (require.main === module) {
  const port = Number(process.env.PORT || 8080);
  createServer().listen(port, () => console.log(`media-adapter listening on ${port}`));
}

module.exports = {
  detectMime,
  normalizeMime,
  readLimitedStream,
  verifyPayload,
  adapterForwardPayload,
  buildDrillV2Operations,
  forwardDrillV2Audio,
  stableHash,
  sourceFingerprint,
  createMemoryMappingStore,
  createMirrorTask,
  prewarmHistoricalMedia,
  mediaTaskStatus,
  recoverMediaTask,
  createAdapterPayload,
  retryOperation,
  processMediaTask,
  PURPOSE_POLICIES,
  handleAdapterRequest,
  createServer,
};
