import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const runPhp = (source) => {
  const result = spawnSync('php', ['-r', source], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
};

test('同步等级、ETag、墓碑和签名游标保持稳定契约', () => {
  const output = runPhp(String.raw`
    require 'api/kernel/bootstrap.php';
    $first = PlatformSyncProtocol::etag('drill_attempt', '42', 3, ['b' => 2, 'a' => ['y' => 2, 'x' => 1]]);
    $reordered = PlatformSyncProtocol::etag('drill_attempt', '42', 3, ['a' => ['x' => 1, 'y' => 2], 'b' => 2]);
    $changed = PlatformSyncProtocol::etag('drill_attempt', '42', 4, ['a' => ['x' => 1, 'y' => 2], 'b' => 2]);
    $scope = PlatformSyncProtocol::scopeHash([
      'staff_id' => 12,
      'session_version' => 4,
      'scope_type' => 'stores',
      'store_ids' => [3, 1],
    ]);
    $reorderedScope = PlatformSyncProtocol::scopeHash([
      'staff_id' => 12,
      'session_version' => 4,
      'scope_type' => 'stores',
      'store_ids' => [1, 3],
    ]);
    $cursor = PlatformSyncProtocol::encodeCursor(['occurred_at' => '2026-07-31 12:00:00', 'id' => 99], $scope, 'test-secret');
    $scopeError = null;
    try {
      PlatformSyncProtocol::decodeCursor($cursor, str_repeat('a', 64), 'test-secret');
    } catch (PlatformApiException $error) {
      $scopeError = [$error->httpStatus(), $error->errorCode()];
    }
    echo json_encode([
      'levels' => PlatformSyncProtocol::levels(),
      'first' => $first,
      'reordered' => $reordered,
      'changed' => $changed,
      'weak_match' => PlatformSyncProtocol::matchesEtag('W/' . $first, $first),
      'cursor' => PlatformSyncProtocol::decodeCursor($cursor, $scope, 'test-secret'),
      'scope_stable' => $scope === $reorderedScope,
      'scope_error' => $scopeError,
      'tombstone' => PlatformSyncProtocol::tombstone('drill_attempt', '42', 5, 'permission_revoked', '2026-07-31 12:01:00', 'scope_changed'),
    ], JSON_UNESCAPED_UNICODE);
  `);

  assert.equal(output.levels.A.max_stale_seconds, 30);
  assert.equal(output.levels.B.max_stale_seconds, 300);
  assert.equal(output.levels.C.max_stale_seconds, 1800);
  assert.equal(output.first, output.reordered);
  assert.notEqual(output.first, output.changed);
  assert.equal(output.weak_match, true);
  assert.deepEqual(output.cursor, { occurred_at: '2026-07-31 12:00:00', id: 99 });
  assert.equal(output.scope_stable, true);
  assert.deepEqual(output.scope_error, [400, 'sync_cursor_scope_changed']);
  assert.equal(output.tombstone.status, 'permission_revoked');
});

test('过期状态版本返回权威状态和可执行恢复数据', () => {
  const output = runPhp(String.raw`
    require 'api/kernel/bootstrap.php';
    try {
      PlatformStateVersion::assertExpected(8, 6, [
        'object_type' => 'approval',
        'object_id' => 'approval-17',
        'authoritative_state' => ['status' => 'approved'],
        'recovery_action' => 'refresh_then_retry',
      ]);
    } catch (PlatformApiException $error) {
      echo json_encode([
        'status' => $error->httpStatus(),
        'code' => $error->errorCode(),
        'data' => $error->errorData(),
      ], JSON_UNESCAPED_UNICODE);
    }
  `);

  assert.equal(output.status, 409);
  assert.equal(output.code, 'version_conflict');
  assert.equal(output.data.base_version, 6);
  assert.equal(output.data.current_version, 8);
  assert.equal(output.data.object_type, 'approval');
  assert.equal(output.data.object_id, 'approval-17');
  assert.deepEqual(output.data.authoritative_state, { status: 'approved' });
  assert.equal(output.data.recovery_action, 'refresh_then_retry');
  assert.equal(output.data.retryable, true);
});

test('服务端草稿通过乐观锁收敛并将删除状态写入增量结果', () => {
  const output = runPhp(String.raw`
    require 'api/platform/SyncService.php';

    final class MemorySyncStore implements PlatformSyncStore {
      public array $drafts = [];
      public array $changes = [];
      private array $snapshot = [];
      public function begin(): void { $this->snapshot = [$this->drafts, $this->changes]; }
      public function commit(): void { $this->snapshot = []; }
      public function rollback(): void { if ($this->snapshot !== []) [$this->drafts, $this->changes] = $this->snapshot; $this->snapshot = []; }
      private function key(int $staffId, string $domain, string $objectType, string $objectId): string { return implode(':', [$staffId, $domain, $objectType, $objectId]); }
      public function findDraft(int $staffId, string $domain, string $objectType, string $objectId, bool $forUpdate): ?array {
        return $this->drafts[$this->key($staffId, $domain, $objectType, $objectId)] ?? null;
      }
      public function saveDraft(array $draft): void {
        $this->drafts[$this->key($draft['owner_staff_id'], $draft['domain'], $draft['object_type'], $draft['object_id'])] = $draft;
      }
      public function markDraftDeleted(int $staffId, string $domain, string $objectType, string $objectId, int $draftVersion, string $updatedAt): void {
        $key = $this->key($staffId, $domain, $objectType, $objectId);
        $this->drafts[$key]['status'] = 'deleted';
        $this->drafts[$key]['draft_version'] = $draftVersion;
        $this->drafts[$key]['payload_json'] = '{}';
        $this->drafts[$key]['updated_at'] = $updatedAt;
      }
      public function appendChange(array $change): int {
        $change['id'] = count($this->changes) + 1;
        $this->changes[] = $change;
        return $change['id'];
      }
      public function listChanges(string $scopeHash, array $position, int $limit, array $filters = []): array {
        return array_slice(array_values(array_filter($this->changes, static function (array $change) use ($scopeHash, $position, $filters): bool {
          $after = $change['occurred_at'] > $position['occurred_at']
            || ($change['occurred_at'] === $position['occurred_at'] && $change['id'] > $position['id']);
          return $after
            && $change['scope_hash'] === $scopeHash
            && (!isset($filters['domain']) || $filters['domain'] === $change['domain'])
            && (!isset($filters['object_type']) || $filters['object_type'] === $change['object_type']);
        })), 0, $limit);
      }
    }

    $store = new MemorySyncStore();
    $currentTime = '2026-07-31 12:00:00';
    $clock = static function () use (&$currentTime): DateTimeImmutable {
      return new DateTimeImmutable($currentTime);
    };
    $service = new PlatformSyncService($store, 'cursor-secret', $clock);
    $scope = str_repeat('b', 64);
    $first = $service->saveDraft(7, 'drill', 'attempt', 'attempt-9', 0, 5, ['text' => 'first'], 'pwa', 'desktop-1');
    $draftConflict = null;
    try {
      $service->saveDraft(7, 'drill', 'attempt', 'attempt-9', 0, 5, ['text' => 'stale'], 'mini_program', 'phone-1');
    } catch (PlatformApiException $error) {
      $draftConflict = [$error->httpStatus(), $error->errorCode(), $error->errorData()];
    }
    $baseConflict = null;
    try {
      $service->saveDraft(7, 'drill', 'attempt', 'attempt-9', 1, 4, ['text' => 'old-base'], 'mini_program', 'phone-1');
    } catch (PlatformApiException $error) {
      $baseConflict = [$error->httpStatus(), $error->errorCode(), $error->errorData()];
    }
    $second = $service->saveDraft(7, 'drill', 'attempt', 'attempt-9', 1, 6, ['text' => 'second'], 'mini_program', 'phone-1');
    $deleted = $service->deleteDraft(7, 'drill', 'attempt', 'attempt-9', 2, $scope);
    $incremental = $service->incremental($scope, null, 20);
    $currentTime = '2026-07-31 12:01:00';
    $repeatIncremental = $service->incremental($scope, null, 20);
    echo json_encode(compact('first', 'second', 'draftConflict', 'baseConflict', 'deleted', 'incremental', 'repeatIncremental'), JSON_UNESCAPED_UNICODE);
  `);

  assert.equal(output.first.draft_version, 1);
  assert.equal(output.second.draft_version, 2);
  assert.equal(output.second.base_state_version, 6);
  assert.equal(output.second.source_client, 'mini_program');
  assert.deepEqual(output.draftConflict.slice(0, 2), [409, 'draft_version_conflict']);
  assert.equal(output.draftConflict[2].authoritative_state.payload.text, 'first');
  assert.deepEqual(output.baseConflict.slice(0, 2), [409, 'base_version_conflict']);
  assert.equal(output.baseConflict[2].current_version, 5);
  assert.equal(output.deleted.state_version, 3);
  assert.equal(output.incremental.items.length, 0);
  assert.equal(output.incremental.tombstones.length, 1);
  assert.equal(output.incremental.tombstones[0].status, 'deleted');
  assert.equal(output.incremental.etag, output.repeatIncremental.etag);
  assert.match(output.incremental.next_cursor, /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/);
});

test('同步端点、能力元数据和迁移声明完整协议边界', () => {
  const endpoint = read('api/platform/sync.php');
  const capabilities = read('api/platform/capabilities.php');
  const migration = read('database/migrations/202607310004_platform_sync.sql');
  const snapshot = read('scripts/platform_contract_snapshot.mjs');

  assert.match(endpoint, /platformApiAuthContext/);
  assert.match(endpoint, /If-None-Match/);
  assert.match(endpoint, /http_response_code\(304\)/);
  assert.match(endpoint, /action === 'changes'/);
  assert.match(endpoint, /action === 'draft'/);
  assert.match(capabilities, /'sync_levels'/);
  assert.match(capabilities, /'incremental_cursor'/);
  assert.match(capabilities, /'server_drafts'/);
  assert.match(capabilities, /'1\.3\.0'/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS platform_sync_drafts/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS platform_sync_changes/);
  assert.match(migration, /uq_platform_sync_draft_owner_object/);
  assert.match(migration, /idx_platform_sync_changes_cursor/);
  assert.match(migration, /permission_revoked/);
  assert.match(snapshot, /incremental_cursor/);
  assert.match(snapshot, /tombstone/);
});
