import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
const serviceSource = readFileSync(new URL('../api/platform/FileAssetService.php', import.meta.url), 'utf8');
const migrationSource = readFileSync(new URL('../database/migrations/202607310013_platform_file_assets.sql', import.meta.url), 'utf8');

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('[validates 11.1] four file classes produce complete metadata and enforce storage and retention policy', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/FileAssetService.php';

    final class FakeFileAssetStore implements PlatformFileAssetStore {
      public array $assets = [];
      public array $grants = [];
      public array $events = [];
      public function createAsset(array $asset): array { $asset['id'] = count($this->assets) + 1; return $this->assets[] = $asset; }
      public function createGrant(array $grant): array { $grant['id'] = count($this->grants) + 1; return $this->grants[] = $grant; }
      public function recordAccess(array $event): void { $this->events[] = $event; }
    }

    $store = new FakeFileAssetStore();
    $service = new PlatformFileAssetService($store);
    $now = new DateTimeImmutable('2026-08-01T00:00:00Z');
    $base = [
      'purpose_code' => 'recruitment.resume',
      'owner_type' => 'staff',
      'owner_id' => '42',
      'business_object_type' => 'candidate',
      'business_object_id' => 'candidate-9',
      'original_name' => 'resume.pdf',
      'mime_type' => 'application/pdf',
      'byte_size' => 128,
      'sha256' => str_repeat('a', 64),
      'retention_policy_code' => 'recruitment.resume.v1',
      'created_by_type' => 'staff',
      'created_by_id' => '42',
    ];
    $public = $service->register($base + [
      'asset_key' => str_repeat('1', 32),
      'asset_class' => PlatformFileAssetPolicy::PUBLIC_STATIC,
      'storage_driver' => 'web_static',
      'storage_key' => 'assets/help/resume.pdf',
    ], $now);
    $controlled = $service->register($base + [
      'asset_key' => str_repeat('2', 32),
      'asset_class' => PlatformFileAssetPolicy::CONTROLLED,
      'storage_driver' => 'local_private',
      'storage_key' => 'recruitment/2026/resume.pdf',
      'retention_until' => '2027-08-01T00:00:00Z',
    ], $now);
    $temporary = $service->register($base + [
      'asset_key' => str_repeat('3', 32),
      'asset_class' => PlatformFileAssetPolicy::TEMPORARY_EXPORT,
      'storage_driver' => 'local_private',
      'storage_key' => 'exports/2026/export.xlsx',
      'retention_until' => '2026-08-02T00:00:00Z',
      'download_expires_at' => '2026-08-01T00:30:00Z',
    ], $now);
    $sensitive = $service->register($base + [
      'asset_key' => str_repeat('4', 32),
      'asset_class' => PlatformFileAssetPolicy::SENSITIVE_SOURCE,
      'storage_driver' => 'object_private',
      'storage_key' => 'recordings/2026/source.wav',
      'retention_until' => '2027-02-01T00:00:00Z',
    ], $now);

    $errors = [];
    foreach ([
      ['name' => 'digest', 'input' => array_replace($base, ['asset_class' => 'controlled', 'storage_driver' => 'local_private', 'storage_key' => 'safe/a', 'retention_until' => '2027-01-01', 'sha256' => 'bad'])],
      ['name' => 'traversal', 'input' => $base + ['asset_class' => 'controlled', 'storage_driver' => 'local_private', 'storage_key' => '../secret', 'retention_until' => '2027-01-01']],
      ['name' => 'storage', 'input' => $base + ['asset_class' => 'sensitive_source', 'storage_driver' => 'web_static', 'storage_key' => 'public/source.wav', 'retention_until' => '2027-01-01']],
      ['name' => 'expiry', 'input' => $base + ['asset_class' => 'temporary_export', 'storage_driver' => 'local_private', 'storage_key' => 'exports/a', 'retention_until' => '2026-08-02']],
    ] as $case) {
      try { $service->register($case['input'], $now); } catch (Throwable $error) { $errors[$case['name']] = $error->getMessage(); }
    }

    echo json_encode([
      'modes' => array_column([$public, $controlled, $temporary, $sensitive], 'access_mode'),
      'complete' => array_diff(['purpose_code', 'owner_type', 'owner_id', 'mime_type', 'byte_size', 'sha256', 'storage_key', 'retention_policy_code', 'created_at'], array_keys($controlled)) === [],
      'temporary_expiry' => $temporary['download_expires_at'],
      'errors' => $errors,
    ]);
  `);

  assert.deepEqual(output.modes, ['public', 'authorized', 'owner_scoped', 'authorized_audited']);
  assert.equal(output.complete, true);
  assert.match(output.temporary_expiry, /^2026-08-01 00:30:00/);
  assert.deepEqual(output.errors, {
    digest: 'file_sha256_invalid',
    traversal: 'file_storage_key_invalid',
    storage: 'file_storage_driver_for_class_invalid',
    expiry: 'file_download_expiry_required',
  });
});

test('[validates 11.1] ACL decisions enforce owner, scope, expiry, revocation, and download validity with audit', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/FileAssetService.php';

    final class FakeFileAccessStore implements PlatformFileAssetStore {
      public array $events = [];
      public function createAsset(array $asset): array { return $asset; }
      public function createGrant(array $grant): array { return $grant; }
      public function recordAccess(array $event): void { $this->events[] = $event; }
    }

    $store = new FakeFileAccessStore();
    $service = new PlatformFileAssetService($store);
    $now = new DateTimeImmutable('2026-08-01T00:00:00Z');
    $asset = [
      'id' => 7,
      'asset_class' => 'controlled',
      'owner_type' => 'staff',
      'owner_id' => '42',
      'status' => 'active',
      'retention_until' => '2027-01-01T00:00:00Z',
      'download_expires_at' => null,
      'storage_key' => 'private/secret.pdf',
    ];
    $owner = $service->authorize($asset, ['type' => 'staff', 'id' => '42'], 'manage', [], ['request_id' => 'req-owner'], $now);
    $grant = [
      'asset_id' => 7,
      'principal_type' => 'staff', 'principal_id' => '9', 'permission_code' => 'download',
      'scope_type' => 'store', 'scope_id' => '3', 'expires_at' => '2026-08-02T00:00:00Z', 'revoked_at' => null,
    ];
    $scoped = $service->authorize($asset, ['type' => 'staff', 'id' => '9', 'scopes' => ['store:3']], 'download', [$grant], ['request_id' => 'req-scope', 'access_reason' => 'review evidence'], $now);
    $wrongScope = $service->authorize($asset, ['type' => 'staff', 'id' => '9', 'scopes' => ['store:4']], 'download', [$grant], ['request_id' => 'req-wrong'], $now);
    $expiredGrant = array_replace($grant, ['expires_at' => '2026-07-31T00:00:00Z']);
    $expired = $service->authorize($asset, ['type' => 'staff', 'id' => '9', 'scopes' => ['store:3']], 'download', [$expiredGrant], ['request_id' => 'req-expired'], $now);
    $revokedGrant = array_replace($grant, ['revoked_at' => '2026-07-31T00:00:00Z']);
    $revoked = $service->authorize($asset, ['type' => 'staff', 'id' => '9', 'scopes' => ['store:3']], 'download', [$revokedGrant], ['request_id' => 'req-revoked'], $now);
    $foreignGrant = array_replace($grant, ['asset_id' => 8]);
    $foreign = $service->authorize($asset, ['type' => 'staff', 'id' => '9', 'scopes' => ['store:3']], 'download', [$foreignGrant], ['request_id' => 'req-foreign'], $now);
    $downloadExpiredAsset = array_replace($asset, ['download_expires_at' => '2026-07-31T00:00:00Z']);
    $downloadExpired = $service->authorize($downloadExpiredAsset, ['type' => 'staff', 'id' => '42'], 'download', [], ['request_id' => 'req-download-expired'], $now);

    $auditHasStorageKey = false;
    foreach ($store->events as $event) {
      $auditHasStorageKey = $auditHasStorageKey || array_key_exists('storage_key', $event);
    }
    echo json_encode([
      'decisions' => [$owner, $scoped, $wrongScope, $expired, $revoked, $foreign, $downloadExpired],
      'events' => $store->events,
      'audit_has_storage_key' => $auditHasStorageKey,
    ]);
  `);

  assert.deepEqual(output.decisions.map((decision) => decision.allowed), [true, true, false, false, false, false, false]);
  assert.deepEqual(output.decisions.map((decision) => decision.reason_code), ['owner', 'acl_grant', 'permission_denied', 'permission_denied', 'permission_denied', 'permission_denied', 'download_expired']);
  assert.equal(output.events.length, 7);
  assert.deepEqual(output.events.map((event) => event.decision), ['allowed', 'allowed', 'denied', 'denied', 'denied', 'denied', 'denied']);
  assert.equal(output.events[1].scope_type, 'store');
  assert.equal(output.events[1].access_reason, 'review evidence');
  assert.equal(output.audit_has_storage_key, false);
});

test('[validates 11.1] migration exposes metadata, ACL, lifecycle indexes, and immutable access evidence', () => {
  for (const table of ['platform_file_assets', 'platform_file_access_grants', 'platform_file_access_events']) {
    assert.match(migrationSource, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
  }
  for (const field of ['asset_class', 'purpose_code', 'owner_type', 'owner_id', 'mime_type', 'byte_size', 'sha256', 'storage_driver', 'storage_key', 'retention_until', 'download_expires_at']) {
    assert.match(migrationSource, new RegExp(`\\b${field}\\b`));
  }
  assert.match(migrationSource, /UNIQUE KEY uq_platform_file_storage_location \(storage_driver, storage_key\)/);
  assert.match(migrationSource, /KEY idx_platform_file_retention \(status, retention_until\)/);
  assert.match(migrationSource, /KEY idx_platform_file_event_actor \(actor_type, actor_id, occurred_at\)/);
  assert.match(serviceSource, /public const PUBLIC_STATIC = 'public_static'/);
  assert.match(serviceSource, /public const SENSITIVE_SOURCE = 'sensitive_source'/);
  assert.doesNotMatch(migrationSource, /UPDATE\s+|DELETE\s+FROM|DROP\s+/i);
});
