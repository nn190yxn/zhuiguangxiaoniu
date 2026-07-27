import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const configSource = readFileSync(new URL('../api/config.php', import.meta.url), 'utf8');
const lifecycleSource = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');

function createRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

class SessionLifecycleModel {
  constructor() {
    this.status = 'active';
    this.accountActive = true;
    this.sessionVersion = 1;
    this.nextTokenId = 1;
    this.tokens = [];
    this.permanentlyRevoked = new Set();
  }

  issue() {
    if (this.status !== 'active' || !this.accountActive) return null;
    const token = { id: this.nextTokenId++, sessionVersion: this.sessionVersion };
    this.tokens.push(token);
    return token;
  }

  revokeExistingSessions() {
    this.tokens.forEach((token) => this.permanentlyRevoked.add(token.id));
    this.sessionVersion += 1;
  }

  deactivate() {
    if (this.status !== 'active') return;
    this.revokeExistingSessions();
    this.status = 'inactive';
    this.accountActive = false;
  }

  activate() {
    if (this.status !== 'inactive') return;
    this.sessionVersion += 1;
    this.status = 'active';
    this.accountActive = true;
  }

  offboard() {
    if (this.status === 'offboarded') return;
    this.revokeExistingSessions();
    this.status = 'offboarded';
    this.accountActive = false;
  }

  restore() {
    if (this.status !== 'offboarded') return;
    this.sessionVersion += 1;
    this.status = 'active';
    this.accountActive = true;
  }

  canAccess(token) {
    return this.status === 'active'
      && this.accountActive
      && token.sessionVersion === this.sessionVersion;
  }

  assertRevokedSessionsStayInvalid() {
    for (const token of this.tokens) {
      if (this.permanentlyRevoked.has(token.id)) {
        assert.equal(this.canAccess(token), false, `revoked token ${token.id} regained access`);
      }
    }
  }
}

test('production authentication binds staff JWTs to the current session version', () => {
  assert.match(configSource, /\$payload\['session_version'\] = \(int\)\(\$staff\['session_version'\] \?\? 0\)/);
  assert.match(configSource, /array_key_exists\('session_version', \$payload\)/);
  assert.match(configSource, /\(int\)\$payload\['session_version'\] === \(int\)\(\$staff\['session_version'\] \?\? 0\)/);
  assert.match(configSource, /\(string\)\(\$staff\['lifecycle_status'\] \?\? 'active'\) === 'active'/);
});

test('deactivation and offboarding increment the session version in their transactions', () => {
  assert.match(
    lifecycleSource,
    /array_key_exists\('status', \$basicChanges\)[\s\S]*?session_version = session_version \+ 1/,
  );
  assert.match(
    lifecycleSource,
    /lifecycle_status = 'offboarded'[\s\S]*?session_version = session_version \+ 1/,
  );
});

test('property 22: every session existing at deactivation or offboarding stays invalid', () => {
  const operations = ['issue', 'deactivate', 'activate', 'offboard', 'restore', 'access'];

  for (let run = 1; run <= 128; run += 1) {
    const random = createRandom(0x22000000 + run);
    const model = new SessionLifecycleModel();
    model.issue();

    for (let step = 0; step < 256; step += 1) {
      const operation = operations[Math.floor(random() * operations.length)];
      if (operation === 'issue') model.issue();
      if (operation === 'deactivate') model.deactivate();
      if (operation === 'activate') model.activate();
      if (operation === 'offboard') model.offboard();
      if (operation === 'restore') model.restore();
      if (operation === 'access' && model.tokens.length > 0) {
        const token = model.tokens[Math.floor(random() * model.tokens.length)];
        if (model.permanentlyRevoked.has(token.id)) assert.equal(model.canAccess(token), false);
      }
      model.assertRevokedSessionsStayInvalid();
    }

    assert.ok(model.permanentlyRevoked.size > 0, `run ${run} did not exercise revocation`);
  }
});
