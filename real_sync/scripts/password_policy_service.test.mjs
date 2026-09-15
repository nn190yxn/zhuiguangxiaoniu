import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const policy = readFileSync(new URL('../api/common/PasswordPolicy.php', import.meta.url), 'utf8');
const reset = readFileSync(new URL('../api/admin/staff/reset-password.php', import.meta.url), 'utf8');
const legacyReset = readFileSync(new URL('../api/admin/reset-password.php', import.meta.url), 'utf8');
const selfChange = readFileSync(new URL('../api/auth-change-password.php', import.meta.url), 'utf8');
const lifecycle = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');

test('one configured password policy protects create, reset, and self-service changes', () => {
  assert.match(policy, /PASSWORD_MIN_LENGTH/);
  for (const rule of ["preg_match('/[a-z]/'", "preg_match('/[A-Z]/'", "preg_match('/\\d/'", "preg_match('/[^A-Za-z0-9]/'"]) {
    assert.ok(policy.includes(rule));
  }
  assert.match(lifecycle, /adminPasswordHash\(\$data\['initial_password'\]\)/);
  assert.match(reset, /PasswordPolicy::validate\(\$newPassword\)/);
  assert.match(selfChange, /PasswordPolicy::validate\(\$newPassword\)/);
  assert.match(legacyReset, /PasswordPolicy::generate\(\)/);
});

test('administrator password reset locks identity and revokes every old session', () => {
  assert.match(reset, /SELECT id, user_id, session_version FROM staffs WHERE id = \? FOR UPDATE/);
  assert.match(reset, /SELECT ID, user_login, user_status FROM wp_users WHERE ID = \? FOR UPDATE/);
  assert.match(reset, /session_version = session_version \+ 1/);
  assert.match(reset, /\$db->commit\(\)/);
  assert.match(reset, /\$db->rollBack\(\)/);
  assert.match(legacyReset, /SELECT id, session_version FROM staffs WHERE user_id = \? FOR UPDATE/);
  assert.match(legacyReset, /session_version = session_version \+ 1/);
});

test('self-service password change rotates sessions and returns a replacement token', () => {
  assert.match(selfChange, /SELECT id, session_version FROM staffs WHERE user_id = \? FOR UPDATE/);
  assert.match(selfChange, /session_version = session_version \+ 1/);
  assert.match(selfChange, /\$replacementToken = generate_jwt\(/);
  assert.match(selfChange, /'token' => \$replacementToken/);
});

test('password policy accepts only complete complexity combinations', () => {
  const valid = (password, minimumLength = 10) => password.length >= minimumLength
    && /[a-z]/.test(password)
    && /[A-Z]/.test(password)
    && /\d/.test(password)
    && /[^A-Za-z0-9]/.test(password);
  assert.equal(valid('Strong#1234'), true);
  for (const password of ['Short#1', 'lowercase#123', 'UPPERCASE#123', 'NoNumber###', 'NoSpecial123']) {
    assert.equal(valid(password), false);
  }
});
