import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const servicePath = fileURLToPath(new URL('../api/drill/v2/services/DrillMediaService.php', import.meta.url));
const audioAssetsEndpoint = readFileSync(new URL('../api/drill/v2/audio-assets.php', import.meta.url), 'utf8');
const audioChunksEndpoint = readFileSync(new URL('../api/drill/v2/audio-chunks.php', import.meta.url), 'utf8');
const audioTranscriptsEndpoint = readFileSync(new URL('../api/drill/v2/audio-transcripts.php', import.meta.url), 'utf8');
const audioAccessEndpoint = readFileSync(new URL('../api/drill/v2/audio-access.php', import.meta.url), 'utf8');
const audioRecoveryEndpoint = readFileSync(new URL('../api/drill/v2/audio-recovery.php', import.meta.url), 'utf8');
const service = readFileSync(servicePath, 'utf8');
const migration = readFileSync(new URL('../database/migrations/202607270003_drill_execution_domain.sql', import.meta.url), 'utf8');

function runPhp(body) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', body], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
}

function runService(expression, extra = '') {
  const storageRoot = mkdtempSync(join(tmpdir(), 'drill-media-'));
  const php = [
    `require_once ${JSON.stringify(servicePath)};`,
    'final class DrillMediaStatement extends PDOStatement {',
    '  private array $params = [];',
    '  public function __construct(private DrillMediaFakePdo $pdo, private string $sql) {}',
    '  public function execute(?array $params = null): bool { $this->params = $params ?? []; $this->pdo->executeSql($this->sql, $this->params); return true; }',
    '  public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->pdo->fetchSql($this->sql, $this->params); }',
    '  public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->pdo->fetchAllSql($this->sql, $this->params); }',
    '}',
    'final class DrillMediaFakePdo extends PDO {',
    '  public array $attempts = []; public array $assets = []; public array $chunks = []; public array $transcripts = []; public array $reviewTasks = []; public array $scores = []; public array $reviews = []; public array $certifications = []; public array $log = []; private ?string $lastSql = null; private int $nextAssetId = 1; private int $nextChunkId = 1; private int $nextTranscriptId = 1; private bool $transaction = false;',
    '  public function __construct() {}',
    '  public function beginTransaction(): bool { if ($this->transaction) { throw new RuntimeException("nested transaction"); } $this->transaction = true; $this->log[] = "begin"; return true; }',
    '  public function commit(): bool { $this->transaction = false; $this->log[] = "commit"; return true; }',
    '  public function rollBack(): bool { $this->transaction = false; $this->log[] = "rollback"; return true; }',
    '  public function inTransaction(): bool { return $this->transaction; }',
    '  public function prepare(string $query, array $options = []): PDOStatement|false { $this->lastSql = $query; return new DrillMediaStatement($this, $query); }',
    '  public function lastInsertId(?string $name = null): string|false { return (string) ($this->nextAssetId - 1); }',
    '  public function executeSql(string $sql, array $params): void {',
    '    if (str_contains($sql, "INSERT INTO drill_audio_assets")) { $id = $this->nextAssetId++; $this->assets[$id] = ["id" => $id, "attempt_id" => $params[0], "staff_id" => $params[1], "asset_type" => $params[2], "storage_path" => $params[3], "public_locator" => null, "mime_type" => $params[4], "byte_size" => $params[5], "duration_ms" => $params[6], "checksum" => $params[7], "consent_status" => $params[8], "consent_basis" => $params[9], "purpose_code" => $params[10], "access_scope_json" => $params[11], "consent_valid_until" => $params[12], "retention_until" => $params[13], "status" => $params[14], "expired_at" => null]; return; }',
    '    if (str_contains($sql, "INSERT INTO drill_audio_chunks")) { $id = $this->nextChunkId++; $this->chunks[$id] = ["id" => $id, "audio_asset_id" => $params[0], "chunk_no" => $params[1], "checksum" => $params[2], "byte_size" => $params[3], "storage_path" => $params[4], "status" => $params[5], "received_at" => $params[6]]; return; }',
    '    if (str_contains($sql, "INSERT INTO drill_transcripts")) { $id = $this->nextTranscriptId++; $this->transcripts[$id] = ["id" => $id, "attempt_id" => $params[0], "audio_asset_id" => $params[1], "turn_id" => null, "transcript_type" => $params[2], "provider" => $params[3], "model" => $params[4], "content" => $params[5], "confidence" => $params[6], "status" => $params[7], "raw_response_ref" => $params[8], "completed_at" => $params[9]]; return; }',
    '    if (str_contains($sql, "UPDATE drill_transcripts")) { foreach ($this->transcripts as &$transcript) { if ((int) $transcript["id"] === (int) $params[7]) { $transcript["provider"] = $params[0]; $transcript["model"] = $params[1]; $transcript["content"] = $params[2]; $transcript["confidence"] = $params[3]; $transcript["status"] = $params[4]; $transcript["raw_response_ref"] = $params[5]; $transcript["completed_at"] = $params[6]; return; } } return; }',
    '    if (str_contains($sql, "UPDATE drill_audio_assets SET status = ?, expired_at = ?")) { if (isset($this->assets[(int) $params[2]])) { $this->assets[(int) $params[2]]["status"] = $params[0]; $this->assets[(int) $params[2]]["expired_at"] = $params[1]; } return; }',
    '    if (str_contains($sql, "UPDATE drill_audio_assets")) { if (isset($this->assets[(int) $params[1]])) { $this->assets[(int) $params[1]]["status"] = $params[0]; } return; }',
    '  }',
    '  public function fetchSql(string $sql, array $params): mixed {',
    '    if (str_contains($sql, "FROM drill_attempts")) { foreach ($this->attempts as $attempt) { if ((int) $attempt["id"] === (int) $params[0] && (int) $attempt["staff_id"] === (int) $params[1]) { return $attempt; } } return false; }',
    '    if (str_contains($sql, "WHERE attempt_id = ? AND checksum = ?")) { foreach ($this->assets as $asset) { if ((int) $asset["attempt_id"] === (int) $params[0] && $asset["checksum"] === $params[1]) { return $asset; } } return false; }',
    '    if (str_contains($sql, "audio INNER JOIN drill_attempts")) { foreach ($this->assets as $asset) { $attempt = $this->attempts[(int) $asset["attempt_id"]] ?? []; if ((int) $asset["id"] !== (int) $params[0]) { continue; } if (count($params) > 1 && ((int) $asset["staff_id"] !== (int) $params[1] || (int) ($attempt["staff_id"] ?? 0) !== (int) $params[2])) { continue; } return array_merge($asset, ["evaluation_context" => $attempt["evaluation_context"] ?? "ai_roleplay"]); } return false; }',
    '    if (str_contains($sql, "FROM drill_audio_chunks")) { foreach ($this->chunks as $chunk) { if ((int) $chunk["audio_asset_id"] === (int) $params[0] && (int) $chunk["chunk_no"] === (int) $params[1]) { return $chunk; } } return false; }',
    '    if (str_contains($sql, "FROM drill_transcripts")) { foreach ($this->transcripts as $transcript) { if ((int) $transcript["audio_asset_id"] === (int) $params[0] && $transcript["transcript_type"] === $params[1]) { return $transcript; } } return false; }',
    '    if (str_contains($sql, "FROM drill_review_tasks")) { foreach ($this->reviewTasks as $task) { if ((int) $task["attempt_id"] === (int) $params[0] && (int) $task["reviewer_staff_id"] === (int) $params[1]) { return $task; } } return false; }',
    '    return false;',
    '  }',
    '  public function fetchAllSql(string $sql, array $params): array {',
    '    if (str_contains($sql, "FROM drill_audio_assets WHERE status IN")) { $assets = array_values(array_filter($this->assets, fn($asset) => in_array($asset["status"], ["uploading", "uploaded"], true) && strcmp((string) $asset["retention_until"], (string) $params[0]) <= 0)); usort($assets, fn($a, $b) => strcmp((string) $a["retention_until"], (string) $b["retention_until"])); return $assets; }',
    '    if (str_contains($sql, "FROM drill_audio_chunks")) { $chunks = array_values(array_filter($this->chunks, fn($chunk) => (int) $chunk["audio_asset_id"] === (int) $params[0])); usort($chunks, fn($a, $b) => (int) $a["chunk_no"] <=> (int) $b["chunk_no"]); return $chunks; }',
    '    return [];',
    '  }',
    '}',
    `$storageRoot = ${JSON.stringify(storageRoot)};`,
    '$pdo = new DrillMediaFakePdo();',
    '$pdo->attempts[9] = ["id" => 9, "staff_id" => 7, "evaluation_context" => "ai_roleplay"];',
    '$service = new DrillMediaService($pdo, $storageRoot);',
    extra,
    'try {',
    `  $value = ${expression};`,
    '  echo json_encode(["ok" => true, "value" => $value, "assets" => $pdo->assets, "chunks" => $pdo->chunks, "transcripts" => $pdo->transcripts, "log" => $pdo->log, "storage_root" => $storageRoot], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);',
    '} catch (Throwable $error) {',
    '  echo json_encode(["ok" => false, "error" => get_class($error), "message" => $error->getMessage(), "log" => $pdo->log], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);',
    '}',
  ].join('\n');
  return runPhp(php);
}

test('task 9.1 schema exposes shared audio assets and chunks with ownership and uniqueness constraints', () => {
  assert.match(migration, /CREATE TABLE IF NOT EXISTS `drill_audio_assets`/);
  assert.match(migration, /`attempt_id` BIGINT UNSIGNED NOT NULL/);
  assert.match(migration, /`staff_id` BIGINT UNSIGNED NOT NULL/);
  assert.match(migration, /UNIQUE KEY `uk_drill_audio_assets_checksum` \(`attempt_id`, `checksum`\)/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS `drill_audio_chunks`/);
  assert.match(migration, /UNIQUE KEY `uk_drill_audio_chunks_sequence` \(`audio_asset_id`, `chunk_no`\)/);
});

test('audio endpoints use shared v2 bootstrap, idempotency, and media service', () => {
  for (const endpoint of [audioAssetsEndpoint, audioChunksEndpoint, audioTranscriptsEndpoint, audioRecoveryEndpoint]) {
    assert.match(endpoint, /drillV2Bootstrap\(\['POST'\]\)/);
    assert.match(endpoint, /drillV2RunIdempotent/);
    assert.match(endpoint, /new DrillMediaService\(\$pdo\)/);
    assert.match(endpoint, /catch \(DrillIdempotencyException \$exception\)/);
    assert.match(endpoint, /\$exception->statusCode\(\)/);
  }
  assert.match(audioAssetsEndpoint, /createAudioAsset/);
  assert.match(audioChunksEndpoint, /uploadChunk/);
  assert.match(audioTranscriptsEndpoint, /finalizeTranscription/);
  assert.match(audioRecoveryEndpoint, /recoverTranscription/);
  assert.match(audioAccessEndpoint, /drillV2Bootstrap\(\['GET', 'POST'\]\)/);
  assert.match(audioAccessEndpoint, /accessAudioAsset/);
  assert.match(audioAccessEndpoint, /authorization_status/);
});

test('media service validates ownership, mime, size, checksum, storage path, and nested transactions', () => {
  for (const token of [
    'ownedAttempt($attemptId, $staffId)',
    'lockAudioAsset($audioAssetId, $staffId, true)',
    'MAX_ASSET_BYTES = 52428800',
    'MAX_CHUNK_BYTES = 5242880',
    'hash(\'sha256\', $binary) !== $checksum',
    "dirname(__DIR__, 5) . '/wp-content/uploads'",
    '$this->pdo->inTransaction()',
  ]) {
    assert.match(service, new RegExp(token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
});

test('audio asset creation accepts valid metadata and replays duplicate checksum', () => {
  const checksum = 'a'.repeat(64);
  const input = `[
    "mime_type" => "audio/webm",
    "byte_size" => 1024,
    "checksum" => "${checksum}",
    "duration_ms" => 3000,
    "consent_status" => "not_required",
  ]`;
  const first = runService(`$service->createAudioAsset(7, 9, ${input}, new DateTimeImmutable("2026-07-28 10:00:00"))`);
  assert.equal(first.ok, true);
  assert.equal(first.value.audio_asset_id, 1);
  assert.equal(first.value.status, 'uploading');
  assert.equal(first.value.retention_until, '2027-01-24 10:00:00');
  assert.equal(first.value.idempotent_replay, false);
  assert.deepEqual(first.log, ['begin', 'commit']);

  const replay = runService(`(function () use ($service) { $input = ${input}; $service->createAudioAsset(7, 9, $input, new DateTimeImmutable("2026-07-28 10:00:00")); return $service->createAudioAsset(7, 9, $input, new DateTimeImmutable("2026-07-28 10:00:00")); })()`);
  assert.equal(replay.ok, true);
  assert.equal(replay.value.idempotent_replay, true);
});

test('real call review assets require granted consent before recording review media', () => {
  const checksum = '7'.repeat(64);
  const missingConsent = runService(`(function () use ($pdo, $service) {
    $pdo->attempts[9]["evaluation_context"] = "real_call_review";
    return $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${checksum}", "consent_status" => "not_required"], new DateTimeImmutable("2026-07-28 10:00:00"));
  })()`);
  assert.equal(missingConsent.ok, false);
  assert.match(missingConsent.message, /告知授权/);

  const granted = runService(`(function () use ($pdo, $service) {
    $pdo->attempts[9]["evaluation_context"] = "real_call_review";
    return $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${checksum}", "consent_status" => "granted", "consent_basis" => "verbal_notice", "purpose_code" => "real_call_review", "access_scope" => ["owner" => true, "reviewer" => true]], new DateTimeImmutable("2026-07-28 10:00:00"));
  })()`);
  assert.equal(granted.ok, true, granted.message);
  const asset = Object.values(granted.assets)[0];
  assert.equal(asset.consent_valid_until, '2027-01-24 10:00:00');
  assert.equal(asset.retention_until, '2027-01-24 10:00:00');
});

test('audio asset creation rejects unsupported mime, oversized files, and missing consent basis', () => {
  const checksum = 'b'.repeat(64);
  const badMime = runService(`$service->createAudioAsset(7, 9, ["mime_type" => "video/mp4", "byte_size" => 1, "checksum" => "${checksum}"], new DateTimeImmutable())`);
  assert.equal(badMime.ok, false);
  assert.match(badMime.message, /格式/);

  const oversized = runService(`$service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 52428801, "checksum" => "${checksum}"], new DateTimeImmutable())`);
  assert.equal(oversized.ok, false);
  assert.match(oversized.message, /50MB/);

  const consent = runService(`$service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1, "checksum" => "${checksum}", "consent_status" => "granted"], new DateTimeImmutable())`);
  assert.equal(consent.ok, false);
  assert.match(consent.message, /授权依据/);
});

test('chunk upload validates base64 payload, declared size, digest, and duplicate chunk replay', () => {
  const assetChecksum = 'c'.repeat(64);
  const payload = Buffer.from('hello-audio');
  const chunkChecksum = createHash('sha256').update(payload).digest('hex');
  const base64 = payload.toString('base64');
  const result = runService(`(function () use ($service) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${assetChecksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    return $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${chunkChecksum}", "byte_size" => ${payload.length}, "content_base64" => "${base64}"], new DateTimeImmutable("2026-07-28 10:01:00"));
  })()`);
  assert.equal(result.ok, true, result.message);
  assert.equal(result.value.chunk_no, 1);
  assert.equal(result.value.idempotent_replay, false);
  assert.equal(Object.values(result.chunks)[0].storage_path.includes('chunk-000001'), true);

  const replay = runService(`(function () use ($service) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${assetChecksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $input = ["chunk_no" => 1, "checksum" => "${chunkChecksum}", "byte_size" => ${payload.length}, "content_base64" => "${base64}"];
    $service->uploadChunk(7, 1, $input, new DateTimeImmutable("2026-07-28 10:01:00"));
    return $service->uploadChunk(7, 1, $input, new DateTimeImmutable("2026-07-28 10:01:00"));
  })()`);
  assert.equal(replay.ok, true);
  assert.equal(replay.value.idempotent_replay, true);
});

test('chunk upload stores partial transcripts and replaces duplicate partial text by chunk number', () => {
  const assetChecksum = '1'.repeat(64);
  const first = Buffer.from('first');
  const second = Buffer.from('second');
  const firstChecksum = createHash('sha256').update(first).digest('hex');
  const secondChecksum = createHash('sha256').update(second).digest('hex');
  const result = runService(`(function () use ($service) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 2048, "checksum" => "${assetChecksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $service->uploadChunk(7, 1, ["chunk_no" => 2, "checksum" => "${secondChecksum}", "byte_size" => ${second.length}, "content_base64" => "${second.toString('base64')}", "transcript_text" => "第二段"], new DateTimeImmutable("2026-07-28 10:01:00"));
    return $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${firstChecksum}", "byte_size" => ${first.length}, "content_base64" => "${first.toString('base64')}", "transcript_text" => "第一段"], new DateTimeImmutable("2026-07-28 10:02:00"));
  })()`);
  assert.equal(result.ok, true, result.message);
  assert.equal(result.value.partial_transcript.chunk_count, 2);
  assert.equal(result.value.partial_transcript.merged_text, '第一段\n第二段');
  const transcript = JSON.parse(Object.values(result.transcripts)[0].content);
  assert.deepEqual(transcript.chunks.map((chunk) => chunk.chunk_no), [1, 2]);
});

test('final transcription reorders chunks, detects missing chunks, and stores final transcript', () => {
  const assetChecksum = '2'.repeat(64);
  const first = Buffer.from('first-final');
  const second = Buffer.from('second-final');
  const firstChecksum = createHash('sha256').update(first).digest('hex');
  const secondChecksum = createHash('sha256').update(second).digest('hex');
  const result = runService(`(function () use ($service) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 2048, "checksum" => "${assetChecksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $service->uploadChunk(7, 1, ["chunk_no" => 2, "checksum" => "${secondChecksum}", "byte_size" => ${second.length}, "content_base64" => "${second.toString('base64')}", "transcript_text" => "后半句"], new DateTimeImmutable("2026-07-28 10:01:00"));
    $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${firstChecksum}", "byte_size" => ${first.length}, "content_base64" => "${first.toString('base64')}", "transcript_text" => "前半句"], new DateTimeImmutable("2026-07-28 10:02:00"));
    return $service->finalizeTranscription(7, 1, ["expected_chunks" => 2, "provider" => "local"], new DateTimeImmutable("2026-07-28 10:03:00"));
  })()`);
  assert.equal(result.ok, true);
  assert.equal(result.value.content, '前半句\n后半句');
  assert.deepEqual(result.value.chunk_numbers, [1, 2]);
  assert.equal(Object.values(result.assets)[0].status, 'uploaded');

  const missing = runService(`(function () use ($service) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 2048, "checksum" => "${'3'.repeat(64)}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $service->uploadChunk(7, 1, ["chunk_no" => 2, "checksum" => "${secondChecksum}", "byte_size" => ${second.length}, "content_base64" => "${second.toString('base64')}", "transcript_text" => "后半句"], new DateTimeImmutable("2026-07-28 10:01:00"));
    return $service->finalizeTranscription(7, 1, ["expected_chunks" => 2, "provider" => "local"], new DateTimeImmutable("2026-07-28 10:03:00"));
  })()`);
  assert.equal(missing.ok, false);
  assert.match(missing.message, /缺失分片：1/);
});

test('final transcription can use provider final text and rejects empty transcript content', () => {
  const assetChecksum = '4'.repeat(64);
  const payload = Buffer.from('provider-final');
  const checksum = createHash('sha256').update(payload).digest('hex');
  const result = runService(`(function () use ($service) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 2048, "checksum" => "${assetChecksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${checksum}", "byte_size" => ${payload.length}, "content_base64" => "${payload.toString('base64')}"], new DateTimeImmutable("2026-07-28 10:01:00"));
    return $service->finalizeTranscription(7, 1, ["expected_chunks" => 1, "provider" => "local", "final_transcript_text" => "供应商最终文本", "confidence" => 0.92], new DateTimeImmutable("2026-07-28 10:03:00"));
  })()`);
  assert.equal(result.ok, true);
  assert.equal(result.value.content, '供应商最终文本');
  assert.equal(Object.values(result.transcripts).find((item) => item.transcript_type === 'final').confidence, 0.92);

  const empty = runService(`(function () use ($service) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 2048, "checksum" => "${'5'.repeat(64)}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${checksum}", "byte_size" => ${payload.length}, "content_base64" => "${payload.toString('base64')}"], new DateTimeImmutable("2026-07-28 10:01:00"));
    return $service->finalizeTranscription(7, 1, ["expected_chunks" => 1, "provider" => "local"], new DateTimeImmutable("2026-07-28 10:03:00"));
  })()`);
  assert.equal(empty.ok, false);
  assert.match(empty.message, /最终转写内容/);
});

test('transcription recovery preserves received chunks and supports failure, retry, and text fallback', () => {
  const assetChecksum = '7'.repeat(64);
  const payload = Buffer.from('recoverable-audio');
  const checksum = createHash('sha256').update(payload).digest('hex');
  const result = runService(`(function () use ($service) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 2048, "checksum" => "${assetChecksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${checksum}", "byte_size" => ${payload.length}, "content_base64" => "${payload.toString('base64')}", "transcript_text" => "保留的临时文本"], new DateTimeImmutable("2026-07-28 10:01:00"));
    $failed = $service->recoverTranscription(7, 1, ["action" => "mark_failed", "reason" => "provider_unavailable"], new DateTimeImmutable("2026-07-28 10:02:00"));
    $retry = $service->recoverTranscription(7, 1, ["action" => "retry"], new DateTimeImmutable("2026-07-28 10:03:00"));
    $fallback = $service->recoverTranscription(7, 1, ["action" => "text_fallback", "text_content" => "员工文本补充"], new DateTimeImmutable("2026-07-28 10:04:00"));
    return ["failed" => $failed, "retry" => $retry, "fallback" => $fallback];
  })()`);
  assert.equal(result.ok, true, result.message);
  assert.equal(result.value.failed.audio_status, 'transcription_failed');
  assert.equal(result.value.failed.transcript_status, 'failed');
  assert.equal(result.value.failed.preserved_chunk_count, 1);
  assert.equal(result.value.retry.audio_status, 'uploading');
  assert.equal(result.value.retry.transcript_status, 'pending');
  assert.equal(result.value.fallback.audio_status, 'uploaded');
  assert.equal(result.value.fallback.transcription_status, 'text_fallback');
  assert.equal(result.value.fallback.content, '员工文本补充');
  assert.equal(Object.values(result.chunks).length, 1);
});

test('transcription recovery validates actions and requires text for fallback', () => {
  const setup = `$service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${'8'.repeat(64)}"], new DateTimeImmutable("2026-07-28 10:00:00"));`;
  const unknown = runService(`(function () use ($service) { ${setup} return $service->recoverTranscription(7, 1, ["action" => "erase"], new DateTimeImmutable()); })()`);
  assert.equal(unknown.ok, false);
  assert.match(unknown.message, /恢复操作/);

  const emptyFallback = runService(`(function () use ($service) { ${setup} return $service->recoverTranscription(7, 1, ["action" => "text_fallback"], new DateTimeImmutable()); })()`);
  assert.equal(emptyFallback.ok, false);
  assert.match(emptyFallback.message, /文本降级内容/);
});

test('randomized chunk order preserves the canonical transcript and repeated retry keeps received chunks', () => {
  let seed = 20260728;
  const random = () => {
    seed = (seed * 1664525 + 1013904223) >>> 0;
    return seed / 0x100000000;
  };
  const chunks = [
    { text: '第一段', payload: Buffer.from('property-one') },
    { text: '第二段', payload: Buffer.from('property-two') },
    { text: '第三段', payload: Buffer.from('property-three') },
    { text: '第四段', payload: Buffer.from('property-four') },
  ];

  for (let iteration = 0; iteration < 12; iteration += 1) {
    const order = [0, 1, 2, 3].sort(() => random() - 0.5);
    const assetChecksum = createHash('sha256').update(`property-asset-${iteration}`).digest('hex');
    const uploadCalls = order.map((index) => {
      const chunk = chunks[index];
      const checksum = createHash('sha256').update(chunk.payload).digest('hex');
      return `$service->uploadChunk(7, 1, ["chunk_no" => ${index + 1}, "checksum" => "${checksum}", "byte_size" => ${chunk.payload.length}, "content_base64" => "${chunk.payload.toString('base64')}", "transcript_text" => "${chunk.text}"], new DateTimeImmutable("2026-07-28 10:01:00"));`;
    }).join('\n');
    const result = runService(`(function () use ($service) {
      $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 4096, "checksum" => "${assetChecksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));
      ${uploadCalls}
      $service->recoverTranscription(7, 1, ["action" => "mark_timeout", "reason" => "timeout"], new DateTimeImmutable("2026-07-28 10:02:00"));
      $service->recoverTranscription(7, 1, ["action" => "retry"], new DateTimeImmutable("2026-07-28 10:03:00"));
      return $service->finalizeTranscription(7, 1, ["expected_chunks" => 4, "provider" => "local"], new DateTimeImmutable("2026-07-28 10:04:00"));
    })()`);
    assert.equal(result.ok, true, `iteration ${iteration}: ${result.message}`);
    assert.equal(result.value.content, '第一段\n第二段\n第三段\n第四段');
    assert.deepEqual(result.value.chunk_numbers, [1, 2, 3, 4]);
    assert.equal(Object.values(result.chunks).length, 4);
  }
});

test('real call review transcription is blocked when consent expires or retention is due', () => {
  const payload = Buffer.from('real-call');
  const chunkChecksum = createHash('sha256').update(payload).digest('hex');
  const expiredConsent = runService(`(function () use ($pdo, $service) {
    $pdo->attempts[9]["evaluation_context"] = "real_call_review";
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${'8'.repeat(64)}", "consent_status" => "granted", "consent_basis" => "verbal_notice", "purpose_code" => "real_call_review"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $pdo->assets[1]["consent_valid_until"] = "2026-07-28 09:59:59";
    return $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${chunkChecksum}", "byte_size" => ${payload.length}, "content_base64" => "${payload.toString('base64')}", "transcript_text" => "转写文本"], new DateTimeImmutable("2026-07-28 10:01:00"));
  })()`);
  assert.equal(expiredConsent.ok, false);
  assert.match(expiredConsent.message, /授权已失效/);

  const dueRetention = runService(`(function () use ($pdo, $service) {
    $pdo->attempts[9]["evaluation_context"] = "real_call_review";
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${'9'.repeat(64)}", "consent_status" => "granted", "consent_basis" => "verbal_notice", "purpose_code" => "real_call_review"], new DateTimeImmutable("2026-01-01 10:00:00"));
    return $service->finalizeTranscription(7, 1, ["expected_chunks" => 1, "provider" => "local", "final_transcript_text" => "文本"], new DateTimeImmutable("2026-07-28 10:01:00"));
  })()`);
  assert.equal(dueRetention.ok, false);
  assert.match(dueRetention.message, /已到期/);
});

test('audio access honors owner, reviewer, coach, and admin scopes', () => {
  const checksum = 'a1'.padEnd(64, 'a');
  const setup = `$pdo->attempts[9]["evaluation_context"] = "real_call_review"; $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${checksum}", "consent_status" => "granted", "consent_basis" => "verbal_notice", "purpose_code" => "real_call_review", "access_scope" => ["owner" => true, "reviewer" => true, "coach" => true, "admin" => true]], new DateTimeImmutable("2026-07-28 10:00:00"));`;

  const owner = runService(`(function () use ($pdo, $service) { ${setup} return $service->accessAudioAsset(7, 1, ["role" => "sales"], new DateTimeImmutable("2026-07-28 10:01:00")); })()`);
  assert.equal(owner.ok, true, owner.message);
  assert.equal(owner.value.audio_asset_id, 1);

  const reviewer = runService(`(function () use ($pdo, $service) { ${setup} $pdo->reviewTasks[] = ["id" => 3, "attempt_id" => 9, "reviewer_staff_id" => 88]; return $service->accessAudioAsset(88, 1, ["role" => "operation"], new DateTimeImmutable("2026-07-28 10:01:00")); })()`);
  assert.equal(reviewer.ok, true, reviewer.message);

  const coach = runService(`(function () use ($pdo, $service) { ${setup} return $service->accessAudioAsset(66, 1, ["role" => "coach"], new DateTimeImmutable("2026-07-28 10:01:00")); })()`);
  assert.equal(coach.ok, true, coach.message);

  const admin = runService(`(function () use ($pdo, $service) { ${setup} return $service->accessAudioAsset(99, 1, ["role" => "admin", "is_admin" => true], new DateTimeImmutable("2026-07-28 10:01:00")); })()`);
  assert.equal(admin.ok, true, admin.message);

  const denied = runService(`(function () use ($pdo, $service) { ${setup} return $service->accessAudioAsset(55, 1, ["role" => "sales"], new DateTimeImmutable("2026-07-28 10:01:00")); })()`);
  assert.equal(denied.ok, false);
  assert.match(denied.message, /访问权限/);
});

test('expired media blocks access and expiry keeps metadata without changing review outcomes', () => {
  const result = runService(`(function () use ($pdo, $service, $storageRoot) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${'b1'.padEnd(64, 'b')}", "retention_days" => 1], new DateTimeImmutable("2026-07-27 10:00:00"));
    $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${createHash('sha256').update(Buffer.from('expire-me')).digest('hex')}", "byte_size" => 9, "content_base64" => "${Buffer.from('expire-me').toString('base64')}"], new DateTimeImmutable("2026-07-27 10:01:00"));
    $assetPath = $storageRoot . "/" . $pdo->assets[1]["storage_path"];
    $chunkPath = $storageRoot . "/" . array_values($pdo->chunks)[0]["storage_path"];
    $pdo->scores = ["attempt_9" => 86];
    $pdo->reviews = ["attempt_9" => "passed"];
    $pdo->certifications = ["attempt_9" => "certified"];
    $expired = $service->expireDueAudioAssets(new DateTimeImmutable("2026-07-28 10:00:01"));
    $blocked = null;
    try { $service->accessAudioAsset(7, 1, ["role" => "sales"], new DateTimeImmutable("2026-07-28 10:00:02")); } catch (Throwable $error) { $blocked = $error->getMessage(); }
    return ["expired" => $expired, "asset_exists" => file_exists($assetPath), "chunk_exists" => file_exists($chunkPath), "blocked" => $blocked, "scores" => $pdo->scores, "reviews" => $pdo->reviews, "certifications" => $pdo->certifications];
  })()`);
  assert.equal(result.ok, true, result.message);
  assert.deepEqual(result.value.expired.expired_audio_asset_ids, [1]);
  assert.equal(result.assets[1].status, 'expired');
  assert.equal(result.assets[1].expired_at, '2026-07-28 10:00:01');
  assert.equal(result.value.asset_exists, false);
  assert.equal(result.value.chunk_exists, false);
  assert.match(result.value.blocked, /已到期/);
  assert.deepEqual(result.value.scores, { attempt_9: 86 });
  assert.deepEqual(result.value.reviews, { attempt_9: 'passed' });
  assert.deepEqual(result.value.certifications, { attempt_9: 'certified' });
});

test('conflicting retransmission keeps the original chunk and requires that sequence to be resent', () => {
  const assetChecksum = '6'.repeat(64);
  const first = Buffer.from('same-seq-a');
  const second = Buffer.from('same-seq-b');
  const firstChecksum = createHash('sha256').update(first).digest('hex');
  const secondChecksum = createHash('sha256').update(second).digest('hex');
  const result = runService(`(function () use ($service) {
    $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 2048, "checksum" => "${assetChecksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${firstChecksum}", "byte_size" => ${first.length}, "content_base64" => "${first.toString('base64')}", "transcript_text" => "旧内容"], new DateTimeImmutable("2026-07-28 10:01:00"));
    return $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${secondChecksum}", "byte_size" => ${second.length}, "content_base64" => "${second.toString('base64')}", "transcript_text" => "新内容"], new DateTimeImmutable("2026-07-28 10:02:00"));
  })()`);
  assert.equal(result.ok, false);
  assert.match(result.message, /同一分片序号已存在不同内容/);
});

test('chunk upload rejects invalid base64, size mismatch, checksum mismatch, and oversized chunks', () => {
  const assetChecksum = 'd'.repeat(64);
  const setup = `$service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${assetChecksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));`;
  const invalidBase64 = runService(`(function () use ($service) { ${setup} return $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${'e'.repeat(64)}", "byte_size" => 1, "content_base64" => "@@@"], new DateTimeImmutable()); })()`);
  assert.equal(invalidBase64.ok, false);
  assert.match(invalidBase64.message, /base64/);

  const sizeMismatch = runService(`(function () use ($service) { ${setup} return $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${'e'.repeat(64)}", "byte_size" => 2, "content_base64" => "YQ=="], new DateTimeImmutable()); })()`);
  assert.equal(sizeMismatch.ok, false);
  assert.match(sizeMismatch.message, /大小/);

  const checksumMismatch = runService(`(function () use ($service) { ${setup} return $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${'e'.repeat(64)}", "byte_size" => 1, "content_base64" => "YQ=="], new DateTimeImmutable()); })()`);
  assert.equal(checksumMismatch.ok, false);
  assert.match(checksumMismatch.message, /摘要/);

  const oversized = runService(`(function () use ($service) { ${setup} return $service->uploadChunk(7, 1, ["chunk_no" => 1, "checksum" => "${'e'.repeat(64)}", "byte_size" => 5242881, "content_base64" => "YQ=="], new DateTimeImmutable()); })()`);
  assert.equal(oversized.ok, false);
  assert.match(oversized.message, /5MB/);
});

test('media service can run inside the idempotency transaction without starting another transaction', () => {
  const checksum = 'f'.repeat(64);
  const result = runService(`(function () use ($pdo, $service) {
    $pdo->beginTransaction();
    $value = $service->createAudioAsset(7, 9, ["mime_type" => "audio/webm", "byte_size" => 1024, "checksum" => "${checksum}"], new DateTimeImmutable("2026-07-28 10:00:00"));
    $pdo->commit();
    return $value;
  })()`);
  assert.equal(result.ok, true);
  assert.deepEqual(result.log, ['begin', 'commit']);
});
