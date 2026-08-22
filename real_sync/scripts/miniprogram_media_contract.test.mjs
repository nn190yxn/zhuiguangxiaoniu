import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import test from 'node:test';

const require = createRequire(import.meta.url);
const projectRoot = new URL('../', import.meta.url).pathname;
const mediaTicket = require('../cloudfunctions/media-ticket/index.js');
const mediaAdapter = require('../cloudrun/media-adapter/index.js');

function validEvent(overrides = {}) {
  return Object.assign({
    protocol_version: 1,
    type: 'media_ticket',
    request_id: 'media-req-1',
    purpose: 'workload_evidence',
    business_type: 'workload_report',
    business_id: 'report-100',
    idempotency_key: 'media-idem-1',
    header: { Authorization: 'Bearer token' },
    file: {
      fileID: 'cloud://env/path/to/file.jpg',
      mime_type: 'image/jpeg',
      byte_size: 4096,
      sha256: 'a'.repeat(64)
    }
  }, overrides);
}

test('media-ticket 校验用途、MIME、大小和摘要边界', () => {
  assert.equal(mediaTicket.validateEvent(validEvent()).file.byte_size, 4096);
  assert.equal(mediaTicket.validateEvent(validEvent({ file: { ...validEvent().file, byte_size: 512 } })).file.byte_size, 512);
  assert.equal(mediaTicket.validateEvent(validEvent({ file: { ...validEvent().file, byte_size: 5 * 1024 * 1024 } })).file.byte_size, 5 * 1024 * 1024);

  assert.throws(() => mediaTicket.validateEvent(validEvent({ purpose: 'unknown' })), /媒体用途未登记/);
  assert.throws(() => mediaTicket.validateEvent(validEvent({ file: { ...validEvent().file, mime_type: 'text/plain' } })), /媒体类型不支持/);
  assert.throws(() => mediaTicket.validateEvent(validEvent({ file: { ...validEvent().file, byte_size: 511 } })), /媒体大小不符合限制/);
  assert.throws(() => mediaTicket.validateEvent(validEvent({ file: { ...validEvent().file, byte_size: 5 * 1024 * 1024 + 1 } })), /媒体大小不符合限制/);
  assert.throws(() => mediaTicket.validateEvent(validEvent({ file: { ...validEvent().file, sha256: 'bad' } })), /媒体摘要无效/);
});

test('media-ticket 追加网关签名并转发到 PHP 媒体接收入口', async () => {
  let capturedOptions = null;
  let capturedBody = null;
  const handler = mediaTicket.createHandler({
    upstreamOrigin: 'https://upstream.example/api',
    gatewaySecret: 'secret',
    now: 1787310000,
    transport(options, body) {
      capturedOptions = options;
      capturedBody = JSON.parse(body);
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { asset_key: 'cloud-media-key', status: 'pending' } } });
    }
  });

  const result = await handler(validEvent());

  assert.equal(result.upstream_status, 200);
  assert.equal(result.body.data.asset_key, 'cloud-media-key');
  assert.equal(capturedOptions.path, '/api/cloud/media-ingest.php');
  assert.equal(capturedOptions.method, 'POST');
  assert.equal(capturedOptions.headers.authorization, 'Bearer token');
  assert.equal(capturedOptions.headers['idempotency-key'], 'media-idem-1');
  assert.match(capturedOptions.headers['x-cloud-signature'], /^[a-f0-9]{64}$/);
  assert.equal(capturedBody.file.fileID, 'cloud://env/path/to/file.jpg');
});

test('PHP 媒体接收入口和映射迁移具备幂等与状态字段', () => {
  const ingest = readFileSync(join(projectRoot, 'api/cloud/media-ingest.php'), 'utf8');
  const migration = readFileSync(join(projectRoot, 'database/migrations/202608210003_platform_cloud_media_mappings.sql'), 'utf8');

  assert.ok(ingest.includes('GatewaySignature::verifyCurrentRequest()'));
  assert.ok(ingest.includes('mediaFindByIdempotency'));
  assert.ok(ingest.includes('platform_cloud_media_mappings'));
  assert.ok(migration.includes("status ENUM('pending','ready','failed','expired')"));
  assert.ok(migration.includes('UNIQUE KEY uniq_cloud_media_idempotency'));
  assert.ok(migration.includes('source_fingerprint CHAR(64) NULL DEFAULT NULL'));
  assert.ok(migration.includes('UNIQUE KEY uniq_cloud_media_source_fingerprint'));
  assert.ok(migration.includes('cloud_file_id'));
  assert.ok(migration.includes('sha256 CHAR(64)'));
});

test('小程序媒体工具提供云路径、上传登记和描述标准化入口', () => {
  const mediaUtil = readFileSync(join(projectRoot, 'mini-program/utils/media.js'), 'utf8');

  assert.ok(mediaUtil.includes('function createCloudPath'));
  assert.ok(mediaUtil.includes('wx.cloud.uploadFile'));
  assert.ok(mediaUtil.includes("name: 'media-ticket'"));
  assert.ok(mediaUtil.includes("type: 'media_ticket'"));
  assert.ok(mediaUtil.includes('uploadAndRegister'));
  assert.ok(mediaUtil.includes('normalizeMediaDescriptor'));
  assert.ok(mediaUtil.includes('getPlayableTempFile'));
  assert.ok(mediaUtil.includes('clearMediaCache'));
});

test('media-adapter 校验真实 MIME、摘要并生成 PHP 转发载荷', async () => {
  const png = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 1, 2, 3, 4]);
  const sha256 = createHash('sha256').update(png).digest('hex');
  const payload = {
    asset_key: 'cloud-media-key',
    purpose: 'workload_evidence',
    business_type: 'workload_report',
    business_id: 'report-100',
    mime_type: 'image/png',
    sha256,
    content_base64: png.toString('base64')
  };
  let forwarded = null;
  const result = await mediaAdapter.handleAdapterRequest(payload, {
    forward(body) {
      forwarded = body;
      return { status: 'ready', asset_key: body.asset_key };
    }
  });

  assert.equal(mediaAdapter.detectMime(png), 'image/png');
  assert.equal(result.statusCode, 200);
  assert.equal(result.body.data.status, 'ready');
  assert.equal(forwarded.mime_type, 'image/png');
  assert.equal(forwarded.sha256, sha256);

  const mismatch = await mediaAdapter.handleAdapterRequest({ ...payload, sha256: 'b'.repeat(64) });
  assert.equal(mismatch.statusCode, 409);
  assert.equal(mismatch.body.code, 'sha256_mismatch');
});

test('media-adapter 从云文件流读取、按用途适配并有限重试', async () => {
  const jpeg = Buffer.concat([Buffer.from([0xff, 0xd8, 0xff]), Buffer.alloc(509, 1)]);
  const sha256 = createHash('sha256').update(jpeg).digest('hex');
  let attempts = 0;
  const result = await mediaAdapter.processMediaTask({
    asset_key: 'workload-media-key',
    fileID: 'cloud://env/workload/file.jpg',
    purpose: 'workload_evidence',
    business_type: 'workload_report',
    business_id: 'report-200',
    mime_type: 'image/jpeg',
    sha256
  }, {
    readCloudFile(fileID) {
      assert.equal(fileID, 'cloud://env/workload/file.jpg');
      return Readable.from([jpeg.slice(0, 128), jpeg.slice(128)]);
    },
    forward(body, meta) {
      attempts += 1;
      assert.equal(body.target_field, 'image_file');
      assert.equal(body.retention_policy, 'workload_evidence');
      if (attempts === 1) throw Object.assign(new Error('临时失败'), { retryable: true });
      return { status: 'ready', asset_key: body.asset_key, attempt: meta.attempt };
    },
    maxAttempts: 2
  });

  assert.equal(attempts, 2);
  assert.equal(result.status, 'ready');
  assert.equal(result.retry_count, 1);
  assert.equal(result.adapter, 'workloadImage');
  assert.equal(result.media.mime_type, 'image/jpeg');
});

test('media-adapter 阻断超限云文件和未登记用途', async () => {
  await assert.rejects(
    mediaAdapter.processMediaTask({ purpose: 'unknown' }, { readCloudFile: () => Readable.from([]) }),
    /媒体用途未登记/
  );
  await assert.rejects(
    mediaAdapter.readLimitedStream(Readable.from([Buffer.alloc(8)]), 4),
    /媒体内容大小无效/
  );
});

test('media-adapter 幂等创建历史媒体镜像任务并复用 ready 映射', () => {
  const store = mediaAdapter.createMemoryMappingStore();
  const first = mediaAdapter.createMirrorTask({
    source_url: 'https://supercalf.com/uploads/course/a.jpg',
    updated_at: '2026-08-21T00:00:00Z',
    purpose: 'knowledge_media',
    business_type: 'course_media',
    business_id: 'course-1'
  }, store);
  const second = mediaAdapter.createMirrorTask({
    source_url: 'https://supercalf.com/uploads/course/a.jpg',
    updated_at: '2026-08-21T00:00:00Z',
    purpose: 'knowledge_media',
    business_type: 'course_media',
    business_id: 'course-1'
  }, store);
  const readyStore = mediaAdapter.createMemoryMappingStore([{ ...first, status: 'ready', cloud_file_id: 'cloud://env/course/a.jpg' }]);
  const ready = mediaAdapter.createMirrorTask({ source_url: first.source_url, updated_at: '2026-08-21T00:00:00Z' }, readyStore);

  assert.equal(first.status, 'pending');
  assert.equal(second.reused, true);
  assert.equal(second.asset_key, first.asset_key);
  assert.equal(ready.status, 'ready');
  assert.equal(ready.reused, true);
  assert.equal(ready.recovery_required, false);
});

test('media-adapter 对并发镜像、源变化和云文件失效给出稳定结果', async () => {
  const store = mediaAdapter.createMemoryMappingStore();
  const source = { source_url: 'https://supercalf.com/uploads/points/gift.jpg', updated_at: 'v1' };
  const tasks = Array.from({ length: 5 }, () => mediaAdapter.createMirrorTask(source, store));
  const changed = mediaAdapter.createMirrorTask({ ...source, updated_at: 'v2' }, store);
  const invalid = await mediaAdapter.handleAdapterRequest({
    asset_key: 'missing-cloud-file',
    fileID: 'cloud://env/missing.jpg',
    purpose: 'workload_evidence',
    business_type: 'workload_report',
    business_id: 'missing',
    mime_type: 'image/jpeg',
    sha256: 'a'.repeat(64)
  }, {
    readCloudFile() {
      throw Object.assign(new Error('云文件失效'), { statusCode: 404, code: 'cloud_file_missing', retryable: true });
    }
  });

  assert.equal(new Set(tasks.map(item => item.asset_key)).size, 1);
  assert.equal(store.all().length, 2);
  assert.notEqual(changed.source_fingerprint, tasks[0].source_fingerprint);
  assert.equal(invalid.statusCode, 404);
  assert.equal(invalid.body.code, 'cloud_file_missing');
  assert.equal(invalid.body.retryable, true);
});

test('历史媒体预热 CLI 使用显式清单和数量上限', () => {
  const script = readFileSync(join(projectRoot, 'scripts/miniprogram_media_prewarm.mjs'), 'utf8');
  const results = mediaAdapter.prewarmHistoricalMedia([
    { source_url: 'https://supercalf.com/uploads/knowledge/1.jpg', updated_at: 'v1' },
    { source_url: 'https://supercalf.com/uploads/knowledge/2.jpg', updated_at: 'v1' }
  ], { limit: 1 });

  assert.ok(script.includes('--input'));
  assert.ok(script.includes('prewarmHistoricalMedia'));
  assert.equal(results.length, 1);
  assert.equal(results[0].status, 'pending');
});

test('媒体任务状态恢复覆盖 failed、expired 和 ready', () => {
  const ready = mediaAdapter.mediaTaskStatus({ status: 'ready', retry_count: 2 });
  const failed = mediaAdapter.mediaTaskStatus({ status: 'failed', retry_count: 1, error_code: 'cloud_file_missing' }, { maxAttempts: 3 });
  const exhausted = mediaAdapter.recoverMediaTask({ status: 'failed', retry_count: 3, error_code: 'mime_mismatch' }, { maxAttempts: 3 });
  const expired = mediaAdapter.recoverMediaTask({ status: 'ready', retry_count: 0, expires_at: '2026-08-20T00:00:00Z' }, { now: Date.parse('2026-08-21T00:00:00Z') });

  assert.equal(ready.recovery_required, false);
  assert.equal(failed.retryable, true);
  assert.equal(mediaAdapter.recoverMediaTask({ status: 'failed', retry_count: 1 }, { maxAttempts: 3 }).action, 'retry');
  assert.equal(exhausted.action, 'manual_review');
  assert.equal(expired.status, 'pending');
  assert.equal(expired.action, 'retry');
});

test('小程序媒体描述保留任务状态和恢复字段', () => {
  const mediaUtil = readFileSync(join(projectRoot, 'mini-program/utils/media.js'), 'utf8');
  assert.ok(mediaUtil.includes('recovery_required'));
  assert.ok(mediaUtil.includes('retry_count'));
  assert.ok(mediaUtil.includes('error_code'));
});

test('媒体摘要一致性属性：随机文件与重试序列的 ready 摘要等于内容摘要', async () => {
  for (let seed = 1; seed <= 16; seed += 1) {
    const randomBody = Buffer.alloc(512 + seed, seed);
    const file = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), randomBody]);
    const sha256 = createHash('sha256').update(file).digest('hex');
    let attemptCount = 0;
    const result = await mediaAdapter.processMediaTask({
      asset_key: `property-media-${seed}`,
      purpose: 'knowledge_media',
      business_type: 'knowledge_audio',
      business_id: String(seed),
      mime_type: 'image/png',
      sha256,
      content_base64: file.toString('base64')
    }, {
      forward(body, meta) {
        attemptCount += 1;
        if (seed % 3 === 0 && meta.attempt === 1) throw Object.assign(new Error('临时失败'), { retryable: true });
        return { status: 'ready', asset_key: body.asset_key, attempt: meta.attempt };
      },
      maxAttempts: 3
    });
    assert.equal(result.status, 'ready');
    assert.equal(result.media.sha256, sha256);
    assert.equal(result.upstream.status, 'ready');
    assert.ok(attemptCount >= 1 && attemptCount <= 2);
  }
});

test('Drill v2 新录音先通过统一媒体工具上传云存储并登记 drill_audio', () => {
  const drillClient = readFileSync(join(projectRoot, 'mini-program/utils/drill-v2.js'), 'utf8');
  assert.ok(drillClient.includes("const media = require('./media')"));
  assert.ok(drillClient.includes('media.uploadAndRegister'));
  assert.ok(drillClient.includes("purpose: 'drill_audio'"));
  assert.ok(drillClient.includes("businessType: 'drill_attempt'"));
  assert.ok(drillClient.includes('cloud_media_asset_key'));
  assert.ok(drillClient.includes("'/audio-chunks.php'"));
  assert.ok(drillClient.includes("'/turns/finalize.php'"));
});

test('media-adapter 将 Drill v2 云音频适配为资源、分片和 finalize 协议', async () => {
  const wavHeader = Buffer.from('RIFF0000WAVE', 'ascii');
  const audio = Buffer.concat([wavHeader, Buffer.alloc(5 * 1024 * 1024 + 32, 7)]);
  const sha256 = createHash('sha256').update(audio).digest('hex');
  const forwarded = [];
  const result = await mediaAdapter.processMediaTask({
    asset_key: 'drill-cloud-media-key',
    fileID: 'cloud://env/drill/audio.wav',
    purpose: 'drill_audio',
    business_type: 'drill_attempt',
    business_id: '88',
    attempt_id: 88,
    status_version: 4,
    duration_ms: 1200,
    mime_type: 'audio/wav',
    sha256
  }, {
    readCloudFile(fileID) {
      assert.equal(fileID, 'cloud://env/drill/audio.wav');
      return Readable.from([audio.slice(0, 1024), audio.slice(1024)]);
    },
    forwardDrillOperation(operation) {
      forwarded.push(operation);
      if (operation.endpoint === '/drill/v2/audio-assets.php') return { audio_asset_id: 501 };
      assert.equal(operation.body.audio_asset_id, 501);
      return { ok: true };
    }
  });

  assert.equal(result.status, 'ready');
  assert.equal(result.adapter, 'drillAudio');
  assert.equal(result.upstream.audio_asset_id, 501);
  assert.deepEqual(forwarded.map(item => item.endpoint), [
    '/drill/v2/audio-assets.php',
    '/drill/v2/audio-chunks.php',
    '/drill/v2/audio-chunks.php',
    '/drill/v2/turns/finalize.php'
  ]);
  assert.equal(forwarded[0].body.attempt_id, 88);
  assert.equal(forwarded[0].body.checksum, sha256);
  assert.equal(forwarded[1].body.chunk_no, 1);
  assert.equal(forwarded[2].body.chunk_no, 2);
  assert.equal(forwarded[1].chunk_content.length, 5 * 1024 * 1024);
  assert.ok(!Object.hasOwn(forwarded[1].body, 'content_base64'));
  assert.equal(forwarded[1].body.checksum, createHash('sha256').update(forwarded[1].chunk_content).digest('hex'));
  assert.equal(forwarded[3].body.status_version, 4);
  assert.equal(forwarded[3].body.expected_chunks, 2);
  assert.equal(forwarded[3].idempotency_key, 'drill-audio:drill-cloud-media-key:finalize');
});

test('Drill v2 音频操作生成器校验用途和 attempt 参数', () => {
  const audio = Buffer.concat([Buffer.from('RIFF0000WAVE', 'ascii'), Buffer.alloc(512, 1)]);
  const sha256 = createHash('sha256').update(audio).digest('hex');
  const verified = {
    asset_key: 'drill-audio-key',
    purpose: 'drill_audio',
    business_id: '9',
    mime_type: 'audio/wav',
    byte_size: audio.length,
    sha256,
    content: audio
  };

  const operations = mediaAdapter.buildDrillV2Operations(verified, { status_version: 0 });
  assert.equal(operations.length, 3);
  assert.equal(operations[1].body.byte_size, audio.length);
  assert.throws(() => mediaAdapter.buildDrillV2Operations({ ...verified, purpose: 'knowledge_media' }, {}), /Drill 音频/);
  assert.throws(() => mediaAdapter.buildDrillV2Operations({ ...verified, business_id: '0' }, {}), /Drill 音频参数无效/);
});
