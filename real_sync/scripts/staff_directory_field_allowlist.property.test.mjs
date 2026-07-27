import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const directory = readFileSync(new URL('../api/admin/services/StaffDirectoryService.php', import.meta.url), 'utf8');
const listEndpoint = readFileSync(new URL('../api/admin/staff/list.php', import.meta.url), 'utf8');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

const LIST_FIELDS = Object.freeze([
  'id',
  'employee_no',
  'name',
  'phone',
  'role',
  'role_name',
  'job_title',
  'stage',
  'status',
  'status_text',
  'lifecycle_status',
  'store_id',
  'store_name',
  'primary_position_id',
  'primary_position_name',
  'entry_date',
  'offboarded_at',
  'offboard_reason',
  'user_id',
  'account_linked',
  'account_enabled',
  'username',
  'email',
  'total_courses',
  'total_drills',
  'avg_pass_rate',
  'created_at',
]);

const SENSITIVE_FIELDS = Object.freeze(['phone', 'username', 'email']);
const RAW_SENSITIVE_ROLES = new Set(['operation', 'admin', 'ceo']);
const ROLE_POOL = Object.freeze(['operation', 'admin', 'ceo', 'finance', 'manager', 'sales', 'coach']);
const FORBIDDEN_SOURCE_FIELDS = Object.freeze([
  'user_pass',
  'password_hash',
  'session_token',
  'refresh_token',
  'openid',
  'device_fingerprint',
  'ip_address',
]);

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

function randomText(random, prefix) {
  return `${prefix}-${Math.floor(random() * 0x100000000).toString(36)}`;
}

function maskSensitive(value) {
  const characters = Array.from(String(value));
  if (characters.length <= 2) {
    return '*'.repeat(characters.length);
  }
  return `${characters[0]}${'*'.repeat(characters.length - 2)}${characters.at(-1)}`;
}

function canViewSensitive(roleTokens) {
  return roleTokens.some((role) => RAW_SENSITIVE_ROLES.has(role));
}

function projectListRow(row, roleTokens) {
  const projected = Object.fromEntries(LIST_FIELDS.map((field) => [field, row[field]]));
  if (!canViewSensitive(roleTokens)) {
    for (const field of SENSITIVE_FIELDS) {
      projected[field] = maskSensitive(row[field]);
    }
  }
  return projected;
}

function generatedRow(random) {
  const row = Object.fromEntries(LIST_FIELDS.map((field) => [field, randomText(random, field)]));
  for (const field of FORBIDDEN_SOURCE_FIELDS) {
    row[field] = randomText(random, field);
  }
  const extraCount = 1 + Math.floor(random() * 8);
  for (let index = 0; index < extraCount; index++) {
    row[randomText(random, 'unknown_field')] = randomText(random, 'secret');
  }
  return row;
}

function generatedRoles(random) {
  const count = 1 + Math.floor(random() * 3);
  const roles = new Set();
  while (roles.size < count) {
    roles.add(ROLE_POOL[Math.floor(random() * ROLE_POOL.length)]);
  }
  return [...roles];
}

test(`${validatesCriteria(["20.7", "21.4", "Property 29"])} arbitrary role responses stay inside the list field allowlist`, () => {
  const allowedFields = new Set(LIST_FIELDS);
  for (let seed = 1; seed <= 128; seed++) {
    const random = seededRandom(seed);
    for (let sample = 0; sample < 256; sample++) {
      const row = generatedRow(random);
      const roles = generatedRoles(random);
      const response = projectListRow(row, roles);

      assert.ok(Object.keys(response).every((field) => allowedFields.has(field)), `seed ${seed}, sample ${sample}`);
      for (const forbiddenField of FORBIDDEN_SOURCE_FIELDS) {
        assert.equal(Object.hasOwn(response, forbiddenField), false, `seed ${seed}, sample ${sample}, field ${forbiddenField}`);
      }
    }
  }
});

test(`${validatesCriteria(["20.7", "21.4", "Property 29"])} sensitive values follow the current role policy`, () => {
  for (let seed = 129; seed <= 256; seed++) {
    const random = seededRandom(seed);
    for (let sample = 0; sample < 128; sample++) {
      const row = generatedRow(random);
      const roles = generatedRoles(random);
      const response = projectListRow(row, roles);

      for (const field of SENSITIVE_FIELDS) {
        const expected = canViewSensitive(roles) ? row[field] : maskSensitive(row[field]);
        assert.equal(response[field], expected, `seed ${seed}, sample ${sample}, roles ${roles.join('|')}, field ${field}`);
      }
    }
  }
});

test(`${validatesCriteria(["20.7", "21.4", "Property 29"])} production list projection is an explicit role-aware allowlist`, () => {
  const listMethodStart = directory.indexOf('public function list');
  const listMethodEnd = directory.indexOf('public function detail', listMethodStart);
  const listMethod = directory.slice(listMethodStart, listMethodEnd);
  const formatStart = directory.indexOf('private function formatStaff');
  const formatEnd = directory.indexOf('private function assignments', formatStart);
  const formatMethod = directory.slice(formatStart, formatEnd);
  const projectedFields = [...formatMethod.matchAll(/^\s*'([a-z_]+)'\s*=>/gm)].map((match) => match[1]);

  assert.ok(listMethodStart >= 0 && listMethodEnd > listMethodStart);
  assert.ok(formatStart >= 0 && formatEnd > formatStart);
  assert.deepEqual(projectedFields.toSorted(), [...LIST_FIELDS].toSorted());
  assert.doesNotMatch(listMethod, /SELECT\s+(?:s\.)?\*/i);
  for (const field of SENSITIVE_FIELDS) {
    assert.match(formatMethod, new RegExp(`['"]${field}['"]\\s*=>\\s*\\$this->sensitive`));
  }
  assert.match(listEndpoint, /array_intersect\(\['operation', 'admin'\], \$roles\)/);
  assert.match(listEndpoint, /adminRequirePermission\('staff\.view_all'\)/);
});
