import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const storagePath = fileURLToPath(new URL('../api/platform/PrivateFileStorage.php', import.meta.url));

function runPhp(body) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', body], {
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
}

function runStorage(expression, setup = '') {
  const root = mkdtempSync(join(tmpdir(), 'platform-private-files-'));
  const php = [
    `require_once ${JSON.stringify(storagePath)};`,
    `$root = ${JSON.stringify(root)};`,
    '$storage = new PlatformPrivateFileStorage($root);',
    setup,
    'try {',
    `  $value = ${expression};`,
    '  echo json_encode(["ok" => true, "value" => $value, "root" => $root], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);',
    '} catch (Throwable $error) {',
    '  echo json_encode(["ok" => false, "error" => get_class($error), "message" => $error->getMessage()], JSON_THROW_ON_ERROR);',
    '}',
  ].join('\n');
  return runPhp(php);
}

test('private upload rejects declared MIME spoofing and verifies the actual digest', () => {
  const sourceDir = mkdtempSync(join(tmpdir(), 'platform-upload-source-'));
  const source = join(sourceDir, 'fake.png');
  writeFileSync(source, '%PDF-1.4\n1 0 obj\n<<>>\nendobj\n');
  const result = runStorage(`$storage->storeFile([
    "source_path" => ${JSON.stringify(source)},
    "original_name" => "fake.png",
    "declared_mime_type" => "image/png",
    "allowed_mime_types" => ["application/pdf", "image/png"],
    "max_bytes" => 1024,
    "namespace" => "evidence",
  ])`);
  assert.equal(result.ok, false);
  assert.equal(result.message, 'file_declared_mime_mismatch');

  const digestMismatch = runStorage(`$storage->storeFile([
    "source_path" => ${JSON.stringify(source)},
    "original_name" => "document.pdf",
    "declared_mime_type" => "application/pdf",
    "allowed_mime_types" => ["application/pdf"],
    "expected_sha256" => "${'0'.repeat(64)}",
    "max_bytes" => 1024,
    "namespace" => "evidence",
  ])`);
  assert.equal(digestMismatch.ok, false);
  assert.equal(digestMismatch.message, 'file_sha256_mismatch');
});

test('private upload generates opaque keys under the private root', () => {
  const sourceDir = mkdtempSync(join(tmpdir(), 'platform-upload-source-'));
  const source = join(sourceDir, 'document.pdf');
  const content = '%PDF-1.4\nprivate-document\n';
  writeFileSync(source, content);
  const result = runStorage(`(function () use ($storage, $root) {
    $input = [
      "source_path" => ${JSON.stringify(source)},
      "original_name" => "document.pdf",
      "declared_mime_type" => "application/pdf",
      "allowed_mime_types" => ["application/pdf"],
      "max_bytes" => 1024,
      "namespace" => "controlled/evidence",
    ];
    $first = $storage->storeFile($input, new DateTimeImmutable("2026-08-01 10:00:00"));
    $second = $storage->storeFile($input, new DateTimeImmutable("2026-08-01 10:00:00"));
    return [
      "first" => $first,
      "second" => $second,
      "first_path" => $storage->resolveForRead($first["storage_key"]),
      "permissions" => substr(sprintf("%o", fileperms($storage->resolveForRead($first["storage_key"]))), -4),
      "root_permissions" => substr(sprintf("%o", fileperms($root)), -4),
      "directory_permissions" => substr(sprintf("%o", fileperms(dirname($storage->resolveForRead($first["storage_key"])))), -4),
    ];
  })()`);
  assert.equal(result.ok, true, result.message);
  assert.equal(result.value.first.mime_type, 'application/pdf');
  assert.equal(result.value.first.byte_size, Buffer.byteLength(content));
  assert.match(result.value.first.sha256, /^[a-f0-9]{64}$/);
  assert.match(result.value.first.storage_key, /^controlled\/evidence\/2026\/08\/[a-f0-9]{48}\.pdf$/);
  assert.notEqual(result.value.first.storage_key, result.value.second.storage_key);
  assert.equal(result.value.first_path.startsWith(`${result.root}/`), true);
  assert.equal(result.value.permissions, '0600');
  assert.equal(result.value.root_permissions, '0700');
  assert.equal(result.value.directory_permissions, '0700');
});

test('[validates 11.5] private reads reject every path escape before physical access', () => {
  const boundaries = runStorage(`(function () use ($storage, $root) {
    $outside = tempnam(sys_get_temp_dir(), "platform-outside-");
    file_put_contents($outside, "outside");
    symlink($outside, $root . "/escape.part");
    $cases = [
      "traversal" => "../outside.txt",
      "absolute" => "/tmp/outside.txt",
      "backslash" => "safe\\\\outside.txt",
      "double_slash" => "safe//outside.txt",
      "dot_segment" => "safe/./outside.txt",
      "control" => "safe/" . chr(0) . "outside.txt",
      "missing" => "safe/missing.part",
      "symlink" => "escape.part",
    ];
    $errors = [];
    foreach ($cases as $name => $key) {
      try { $storage->resolveForRead($key); }
      catch (Throwable $error) { $errors[$name] = $error->getMessage(); }
    }
    return $errors;
  })()`);
  assert.equal(boundaries.ok, true, boundaries.message);
  assert.deepEqual(boundaries.value, {
    traversal: 'file_storage_key_invalid',
    absolute: 'file_storage_key_invalid',
    backslash: 'file_storage_key_invalid',
    double_slash: 'file_storage_key_invalid',
    dot_segment: 'file_storage_key_invalid',
    control: 'file_storage_key_invalid',
    missing: 'file_not_found',
    symlink: 'file_not_found',
  });
});

test('private download resolves every path before streaming ordered parts', () => {
  const streamed = runStorage(`(function () use ($storage) {
    $first = $storage->storeBytes("hello-", "drill/audio/chunks", "part", new DateTimeImmutable("2026-08-01"));
    $second = $storage->storeBytes("audio", "drill/audio/chunks", "part", new DateTimeImmutable("2026-08-01"));
    $download = $storage->prepareDownload([$first["storage_key"], $second["storage_key"]], "audio/webm", "recording.webm");
    ob_start();
    $storage->stream($download);
    $body = ob_get_clean();
    return ["body" => $body, "byte_size" => $download["byte_size"], "path_count" => count($download["paths"])];
  })()`);
  assert.equal(streamed.ok, true, streamed.message);
  assert.equal(streamed.value.body, 'hello-audio');
  assert.equal(streamed.value.byte_size, 11);
  assert.equal(streamed.value.path_count, 2);

  const invalidPart = runStorage(`(function () use ($storage) {
    $first = $storage->storeBytes("must-not-stream", "drill/audio/chunks", "part", new DateTimeImmutable("2026-08-01"));
    ob_start();
    try { $storage->prepareDownload([$first["storage_key"], "../outside.part"], "audio/webm", "recording.webm"); }
    finally { $body = ob_get_clean(); }
    return $body;
  })()`);
  assert.equal(invalidPart.ok, false);
  assert.equal(invalidPart.message, 'file_storage_key_invalid');
});

test('[validates 11.5] retention cleanup honors exact expiry and remains idempotent', () => {
  const result = runStorage(`(function () use ($storage) {
    $due = $storage->storeBytes("due", "retention", "bin", new DateTimeImmutable("2026-07-01"));
    $future = $storage->storeBytes("future", "retention", "bin", new DateTimeImmutable("2026-07-01"));
    $inactive = $storage->storeBytes("inactive", "retention", "bin", new DateTimeImmutable("2026-07-01"));
    $missingKey = "retention/2026/07/" . str_repeat("f", 48) . ".bin";
    $assets = [
      ["id" => 1, "storage_key" => $due["storage_key"], "retention_until" => "2026-08-01 10:00:00", "status" => "active"],
      ["id" => 2, "storage_key" => $future["storage_key"], "retention_until" => "2026-08-01 10:00:01", "status" => "active"],
      ["id" => 3, "storage_key" => $inactive["storage_key"], "retention_until" => "2026-08-01 09:59:59", "status" => "expired"],
      ["id" => 4, "storage_key" => $missingKey, "retention_until" => "2026-08-01 09:59:59", "status" => "active"],
    ];
    $summary = $storage->cleanupExpired($assets, new DateTimeImmutable("2026-08-01 10:00:00"));
    $replay = $storage->cleanupExpired($assets, new DateTimeImmutable("2026-08-01 10:00:00"));
    return [
      "summary" => $summary,
      "replay" => $replay,
      "due_exists" => $storage->exists($due["storage_key"]),
      "future_exists" => $storage->exists($future["storage_key"]),
      "inactive_exists" => $storage->exists($inactive["storage_key"]),
    ];
  })()`);
  assert.equal(result.ok, true, result.message);
  assert.equal(result.value.summary.eligible_count, 2);
  assert.equal(result.value.summary.deleted_count, 1);
  assert.equal(result.value.summary.missing_count, 1);
  assert.deepEqual(result.value.summary.results, [{ id: 1, status: 'deleted' }, { id: 4, status: 'missing' }]);
  assert.equal(result.value.replay.eligible_count, 2);
  assert.equal(result.value.replay.deleted_count, 0);
  assert.equal(result.value.replay.missing_count, 2);
  assert.equal(result.value.due_exists, false);
  assert.equal(result.value.future_exists, true);
  assert.equal(result.value.inactive_exists, true);
});
