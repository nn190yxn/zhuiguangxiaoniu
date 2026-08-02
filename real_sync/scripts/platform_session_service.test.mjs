import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('versioned session migration preserves families, token rotation, and security events', () => {
  const migration = read('database/migrations/202607310002_platform_sessions.sql');

  assert.match(migration, /CREATE TABLE IF NOT EXISTS platform_sessions/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS platform_refresh_tokens/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS platform_security_events/);
  assert.match(migration, /UNIQUE KEY uq_platform_refresh_tokens_hash \(token_hash\)/);
  assert.match(migration, /KEY idx_platform_sessions_family \(family_id, status\)/);
});

test('session service rotates once and revokes the family when a rotated token is reused', () => {
  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    require 'api/auth/SessionStore.php';
    require 'api/auth/SessionService.php';

    final class MemorySessionStore implements PlatformSessionStore {
      public array $sessions = [];
      public array $tokens = [];

      public function createSession(array $session, array $refreshToken): void {
        $this->sessions[$session['id']] = $session;
        $this->tokens[$refreshToken['token_hash']] = $refreshToken;
      }

      public function findRefreshSession(string $tokenHash): ?array {
        if (!isset($this->tokens[$tokenHash])) return null;
        return $this->sessions[$this->tokens[$tokenHash]['session_id']] ?? null;
      }

      public function rotateRefreshToken(string $tokenHash, int $sessionVersion, array $replacement, int $now): array {
        if (!isset($this->tokens[$tokenHash])) return ['status' => 'invalid'];
        $token =& $this->tokens[$tokenHash];
        $session =& $this->sessions[$token['session_id']];
        if ($token['status'] === 'rotated') {
          $session['status'] = 'revoked';
          foreach ($this->tokens as &$familyToken) {
            if ($familyToken['session_id'] === $session['id'] && $familyToken['status'] === 'active') {
              $familyToken['status'] = 'revoked';
            }
          }
          return ['status' => 'reuse_detected', 'session' => $session];
        }
        if ($session['status'] !== 'active' || $token['status'] !== 'active') return ['status' => 'revoked'];
        if ($session['session_version'] !== $sessionVersion) {
          $session['status'] = 'revoked';
          return ['status' => 'version_mismatch'];
        }
        if ($token['expires_at'] <= $now || $session['expires_at'] <= $now) return ['status' => 'expired'];
        $token['status'] = 'rotated';
        $token['replaced_by_token_id'] = $replacement['id'];
        $this->tokens[$replacement['token_hash']] = $replacement;
        return ['status' => 'rotated', 'session' => $session];
      }

      public function revokeSessionFamily(string $familyId, string $reason, int $now): void {
        foreach ($this->sessions as &$session) {
          if ($session['family_id'] === $familyId) $session['status'] = 'revoked';
        }
      }
    }

    $store = new MemorySessionStore();
    $issuedClaims = [];
    $issuer = static function (array $claims, int $ttl) use (&$issuedClaims): string {
      $issuedClaims[] = [$claims, $ttl];
      return 'access-' . count($issuedClaims);
    };
    $service = new PlatformSessionService($store, $issuer, 900, 2592000, static fn(): int => 1700000000);
    $identity = ['user_id' => 7, 'staff_id' => 11, 'username' => 'member', 'role' => 'staff', 'session_version' => 4];
    $first = $service->issue($identity, 'pwa', 'browser-1');
    $second = $service->refresh($first['refresh_token'], 4);
    try {
      $service->refresh($first['refresh_token'], 4);
      $reuse = null;
    } catch (PlatformApiException $error) {
      $reuse = [$error->httpStatus(), $error->errorCode()];
    }
    try {
      $service->refresh($second['refresh_token'], 4);
      $family = null;
    } catch (PlatformApiException $error) {
      $family = [$error->httpStatus(), $error->errorCode()];
    }
    echo json_encode([
      'first' => $first,
      'second' => $second,
      'reuse' => $reuse,
      'family' => $family,
      'claims' => $issuedClaims,
    ]);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });

  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.first.access_token, 'access-1');
  assert.equal(output.first.access_expires_in, 900);
  assert.equal(output.first.refresh_expires_in, 2592000);
  assert.match(output.first.refresh_token, /^psr_[a-f0-9]{64}$/);
  assert.notEqual(output.first.refresh_token, output.second.refresh_token);
  assert.deepEqual(output.reuse, [401, 'refresh_token_reused']);
  assert.deepEqual(output.family, [401, 'session_revoked']);
  assert.equal(output.claims[0][0].session_version, 4);
  assert.equal(output.claims[0][0].client, 'pwa');
  assert.equal(output.claims[0][1], 900);
});

test('session version changes invalidate refresh before a new access token is issued', () => {
  const service = read('api/auth/SessionService.php');
  const store = read('api/auth/SessionStore.php');
  const factory = read('api/auth/SessionFactory.php');
  const config = read('api/config.php');

  assert.match(service, /version_mismatch[\s\S]*session_version_changed/);
  assert.match(store, /SELECT[\s\S]*FOR UPDATE/);
  assert.match(store, /reuse_detected/);
  assert.match(factory, /new PlatformPdoSessionStore\(\$db\)/);
  assert.match(config, /JWT_ACCESS_EXPIRE/);
  assert.match(config, /function isPlatformSessionAllowed/);
  assert.match(config, /status'\] === 'active'/);
  assert.match(config, /function generate_jwt\([^)]*array \$claims = \[\][^)]*\)/);
});
