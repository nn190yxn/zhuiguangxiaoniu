import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('../', import.meta.url);

test('de-identified resume regression fixture covers required scenarios', () => {
  const fixture = JSON.parse(readFileSync(new URL('scripts/fixtures/recruitment_resume_regression.json', root), 'utf8'));
  assert.ok(fixture.cases.length >= 9);
  const scenarios = fixture.cases.map((item) => item.scenario).join(' ');
  ['教练', '课程顾问', '跨行业', '关键词不足', '关键信息缺失', '复杂排版', '重复简历'].forEach((scenario) => {
    assert.ok(scenarios.includes(scenario), `${scenario} should be covered`);
  });
  assert.ok(fixture.cases.every((item) => item.pages.every((page) => !page.text.includes('@gmail.com'))));
});

test('regression runner reports stable extraction evidence and grades', () => {
  const result = spawnSync('node', [
    'scripts/recruitment_resume_regression.mjs',
    '--parser=test-parser',
    '--prompt=test-prompt',
    '--model=test-model',
    '--scoring=test-scoring',
  ], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  const report = JSON.parse(result.stdout);
  assert.equal(report.case_count, 9);
  assert.equal(report.metrics.field_accuracy, 1);
  assert.equal(report.metrics.evidence_validity, 1);
  assert.equal(report.metrics.grade_accuracy, 1);
  assert.equal(report.metrics.ab_recall, 1);
  assert.equal(report.metrics.failure_rate, 0);
  assert.deepEqual(report.metrics.grade_distribution, { A: 4, B: 3, C: 2 });
  assert.equal(report.versions.model, 'test-model');
});

test('grade calculation remains independently testable without database writes', () => {
  const service = readFileSync(new URL('api/admin/recruitment/services/ResumeGradeService.php', root), 'utf8');
  assert.match(service, /public function calculate\(array \$evidence, array \$rule\): array/);
  assert.match(service, /\$result = \$this->calculate\(\$evidence, \$rule\)/);
});
