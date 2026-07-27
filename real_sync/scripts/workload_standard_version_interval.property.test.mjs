import assert from 'node:assert/strict';
import test from 'node:test';

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function publish(versions, next) {
  const sameRole = versions.filter((version) => version.role === next.role && ['active', 'scheduled'].includes(version.status));
  if (sameRole.some((version) => version.from >= next.from && (!next.to || next.to >= version.from))) return null;
  const previousDay = new Date(`${next.from}T00:00:00Z`);
  previousDay.setUTCDate(previousDay.getUTCDate() - 1);
  const cutoff = previousDay.toISOString().slice(0, 10);
  return [
    ...versions.map((version) => {
      if (version.role !== next.role || !['active', 'scheduled'].includes(version.status)) return version;
      if (!version.to || version.to >= next.from) return { ...version, to: cutoff };
      return version;
    }),
    next,
  ];
}

function activeAt(versions, role, date) {
  return versions.filter((version) =>
    version.role === role
    && ['active', 'scheduled'].includes(version.status)
    && version.from <= date
    && (!version.to || version.to >= date));
}

function disable(versions, id, cutoff) {
  const target = versions.find((version) => version.id === id);
  if (!target || !['active', 'scheduled'].includes(target.status)) return null;
  if (cutoff < target.from || (target.to && cutoff > target.to)) return null;
  const overlaps = versions.some((version) =>
    version.id !== id
    && version.role === target.role
    && ['active', 'scheduled'].includes(version.status)
    && version.from <= cutoff
    && (!version.to || version.to >= target.from));
  if (overlaps) return null;
  return versions.map((version) => version.id === id ? { ...version, to: cutoff } : version);
}

test(`${validatesCriteria(['24.8'])} each role and business date resolves at most one published version`, () => {
  let seed = 20260724;
  const random = () => {
    seed = (seed * 1664525 + 1013904223) >>> 0;
    return seed / 2 ** 32;
  };
  const roles = ['sales', 'coach', 'trainer', 'reception'];
  let versions = [];
  let id = 1;
  for (let day = 0; day < 1000; day++) {
    if (random() < 0.12) {
      const date = new Date('2026-01-01T00:00:00Z');
      date.setUTCDate(date.getUTCDate() + day);
      const from = date.toISOString().slice(0, 10);
      const role = roles[Math.floor(random() * roles.length)];
      const next = { id: id++, role, from, to: null, status: from > '2026-07-27' ? 'scheduled' : 'active' };
      versions = publish(versions, next) ?? versions;
    }
    const date = new Date('2026-01-01T00:00:00Z');
    date.setUTCDate(date.getUTCDate() + day);
    const businessDate = date.toISOString().slice(0, 10);
    for (const role of roles) {
      assert.ok(activeAt(versions, role, businessDate).length <= 1, `${role} ${businessDate}`);
    }
  }
});

test(`${validatesCriteria(['24.6', '24.7'])} ending a published interval preserves historical bindings`, () => {
  const report = { id: 88, ruleVersionId: 1 };
  const versions = publish(
    [{ id: 1, role: 'sales', from: '2026-01-01', to: null, status: 'active' }],
    { id: 2, role: 'sales', from: '2026-08-01', to: null, status: 'scheduled' },
  );
  assert.equal(versions.find((version) => version.id === report.ruleVersionId).to, '2026-07-31');
  assert.equal(report.ruleVersionId, 1);
});

test(`${validatesCriteria(['24.6', '24.8'])} disabling a version can only shorten its non-overlapping interval`, () => {
  const versions = [
    { id: 1, role: 'sales', from: '2026-01-01', to: '2026-07-31', status: 'active' },
    { id: 2, role: 'sales', from: '2026-08-01', to: null, status: 'scheduled' },
  ];
  assert.equal(disable(versions, 1, '2026-09-01'), null);
  const shortened = disable(versions, 1, '2026-06-30');
  assert.equal(shortened.find((version) => version.id === 1).to, '2026-06-30');
  for (const date of ['2026-06-30', '2026-07-01', '2026-08-01']) {
    assert.ok(activeAt(shortened, 'sales', date).length <= 1);
  }
});

test(`${validatesCriteria(['24.8'])} a bounded version may be published before an existing later version`, () => {
  const versions = publish(
    [{ id: 2, role: 'sales', from: '2026-08-01', to: null, status: 'scheduled' }],
    { id: 1, role: 'sales', from: '2026-07-01', to: '2026-07-31', status: 'active' },
  );
  assert.ok(versions);
  for (const date of ['2026-07-01', '2026-07-31', '2026-08-01']) {
    assert.ok(activeAt(versions, 'sales', date).length <= 1);
  }
});
