import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import test from 'node:test';

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const projectRoot = resolve(import.meta.dirname, '..');

test(`${validatesCriteria(['7.1', '7.2', 'Property 8'])} 终审任务、批准主记录与正式库始终读取同一版本`, () => {
  const result = spawnSync(
    'php',
    ['scripts/lesson_approved_version_consistency.property.php', '256'],
    { cwd: projectRoot, encoding: 'utf8', timeout: 30_000 },
  );

  assert.equal(result.status, 0, result.stderr || result.stdout);
  const evidence = JSON.parse(result.stdout);
  assert.deepEqual(evidence, {
    case_count: 256,
    detail_checked: 256,
    list_checked: 256,
    current_version_perturbations: 85,
    mismatch_count: 0,
  });
});
