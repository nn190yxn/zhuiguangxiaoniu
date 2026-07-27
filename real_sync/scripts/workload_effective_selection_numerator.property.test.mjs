import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const effectiveValueService = read('../api/workload/services/WorkloadEffectiveValueService.php');
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const auditModes = ['none', 'full'];
const auditStatuses = [null, 'pending', 'approved', 'rejected', 'needs_resubmit', 'superseded'];

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

function phpRoundToTwo(value) {
  return Math.sign(value) * Math.round(Math.abs(value) * 100 + Number.EPSILON) / 100;
}

function calculateValues({ rawValue, auditMode, auditStatus, taskExists }) {
  const roundedRawValue = phpRoundToTwo(rawValue);
  const isFullAudit = auditMode === 'full';
  const isPending = isFullAudit && taskExists && auditStatus === 'pending';
  const isApproved = isFullAudit && taskExists && auditStatus === 'approved';
  return {
    rawValue: roundedRawValue,
    pendingValue: isPending ? roundedRawValue : 0,
    effectiveValue: isFullAudit ? (isApproved ? roundedRawValue : 0) : roundedRawValue,
  };
}

function assertEffectiveSelectionBound(reports, context) {
  const rawNumerator = reports.filter((report) => report.rawValue > 0).length;
  const effectiveNumerator = reports.filter((report) => report.effectiveValue > 0).length;
  assert.ok(
    effectiveNumerator <= rawNumerator,
    `${context}: effective ${effectiveNumerator}, raw ${rawNumerator}`,
  );
}

test(`${validatesCriteria(['3.1', '7.2-7.5', 'Property 7'])} arbitrary submitted-report sequences keep the effective numerator within the raw numerator`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const reports = [];

    for (let step = 0; step < 256; step += 1) {
      const rawValue = (random() * 2_000_000 - 1_000_000) / 1000;
      const auditMode = auditModes[Math.floor(random() * auditModes.length)];
      const auditStatus = auditStatuses[Math.floor(random() * auditStatuses.length)];
      const taskExists = random() >= 0.25;
      reports.push(calculateValues({ rawValue, auditMode, auditStatus, taskExists }));
      assertEffectiveSelectionBound(reports, `seed ${seed}, step ${step}`);
    }
  }
});

test(`${validatesCriteria(['3.1', '7.2-7.5', 'Property 7'])} audit states and rounding boundaries preserve the positive-report subset`, () => {
  const rawValues = [-1000, -0.005, -0.004, 0, 0.004, 0.005, 0.01, 1000];
  const reports = [];

  for (const rawValue of rawValues) {
    for (const auditMode of auditModes) {
      for (const auditStatus of auditStatuses) {
        for (const taskExists of [false, true]) {
          const values = calculateValues({ rawValue, auditMode, auditStatus, taskExists });
          reports.push(values);
          assert.ok(
            values.effectiveValue === 0 || values.effectiveValue === values.rawValue,
            `${rawValue}, ${auditMode}, ${auditStatus}, task ${taskExists}`,
          );
        }
      }
    }
  }

  assertEffectiveSelectionBound(reports, 'complete audit and boundary matrix');
});

test(`${validatesCriteria(['3.1', '7.2-7.5', 'Property 7'])} production formulas restrict effective values to the rounded raw value or zero`, () => {
  assert.match(effectiveValueService, /\$rawValue = round\(\$rawValue, 2\)/);
  assert.match(effectiveValueService, /\$isApproved = \$isFullAudit && \$taskExists && \$auditStatus === 'approved'/);
  assert.match(
    effectiveValueService,
    /effective_value' => \$isFullAudit \? \(\$isApproved \? \$rawValue : 0\.0\) : \$rawValue/,
  );
  assert.match(
    effectiveValueService,
    /'effective_value' => "CASE WHEN \$fullAudit THEN CASE WHEN \$taskExists AND \$auditStatusExpression = 'approved' THEN \$rawExpression ELSE 0 END ELSE \$rawExpression END"/,
  );
});
