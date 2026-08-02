import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('PWA refresh credential uses restricted secure HttpOnly cookie attributes', () => {
  const http = read('api/auth/PwaSessionHttp.php');

  assert.match(http, /PLATFORM_PWA_REFRESH_PATH = '\/api\/auth\/refresh\.php'/);
  assert.match(http, /'secure' => true/);
  assert.match(http, /'httponly' => true/);
  assert.match(http, /'samesite' => 'Lax'/);
  assert.match(http, /hash_hmac\('sha256', \$payload, JWT_SECRET\)/);
});

test('PWA refresh endpoint validates origin, double-submit CSRF, rotation, and session version', () => {
  const endpoint = read('api/auth/refresh.php');

  assert.match(endpoint, /platformPwaRequireTrustedOrigin\(\)/);
  assert.match(endpoint, /HTTP_X_CSRF_TOKEN/);
  assert.match(endpoint, /hash_equals\(\$csrfCookie, \$csrfHeader\)/);
  assert.match(endpoint, /platformPwaValidateCsrf\(\$csrfHeader, \(string\)\$session\['id'\]\)/);
  assert.match(endpoint, /\$service->refresh\(\$refreshToken, \$currentVersion\)/);
  assert.match(endpoint, /\$service->revokeFamily\(\(string\)\$session\['family_id'\], 'logout'\)/);
  assert.match(endpoint, /unset\(\$tokens\['refresh_token'\]\)/);
  assert.match(endpoint, /Cache-Control: no-store/);
});

test('PWA login opts into versioned sessions without exposing refresh tokens in JSON', () => {
  const loginApi = read('api/auth-jwt.php');
  const loginPage = read('mobile/login.html');

  assert.match(loginApi, /\(\$input\['client_type'\] \?\? ''\) === 'pwa'/);
  assert.match(loginApi, /platformPwaSetSessionCookies/);
  assert.doesNotMatch(loginApi, /'refresh_token'\s*=>\s*\$versionedSession/);
  assert.match(loginPage, /formData\.append\('client_type', 'pwa'\)/);
  assert.doesNotMatch(loginPage, /writeStoredValue\('jwt_token', token/);
});

test('PWA request layer keeps access tokens in memory and broadcasts coordination metadata only', () => {
  const auth = read('js/app-auth.js');
  const mine = read('mobile/mine.html');

  assert.match(auth, /var accessToken/);
  assert.match(auth, /navigator\.locks\.request\('platform-session-refresh'/);
  assert.match(auth, /new BroadcastChannel\('platform-session'/);
  assert.match(auth, /type:'session-updated',session_version:/);
  assert.doesNotMatch(auth, /postMessage\([^)]*token/i);
  assert.doesNotMatch(auth, /localStorage\.setItem\([^,]*token/i);
  assert.match(auth, /X-CSRF-Token/);
  assert.match(auth, /if\(readCookie\('platform_csrf'\)\)\{\s*removeStoredValue/);
  assert.match(auth, /else\{\s*accessToken=readStoredValue/);
  assert.match(auth, /if\(accessToken&&!readCookie\('platform_csrf'\)\)/);
  assert.match(mine, /AppAuth\.authFetch\('\/api\/auth\/me\.php'/);
  assert.match(mine, /AppAuth\.authFetch\('\/api\/auth-change-password\.php'/);
  assert.match(mine, /AppAuth\.authFetch\(url, requestOptions\)/);
});
