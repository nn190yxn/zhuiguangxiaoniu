import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const root = new URL('../', import.meta.url);
const fixturePath = new URL('scripts/fixtures/recruitment_resume_regression.json', root);
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
const versions = Object.fromEntries(process.argv.slice(2).map((argument) => {
  const [key, ...value] = argument.replace(/^--/, '').split('=');
  return [key, value.join('=') || 'unspecified'];
}));

const php = `
require 'api/admin/recruitment/services/ResumeFieldNormalizer.php';
require 'api/admin/recruitment/services/ResumeGradeService.php';
$fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$normalizer = new ResumeFieldNormalizer();
$grader = (new ReflectionClass(ResumeGradeService::class))->newInstanceWithoutConstructor();
$results = [];
foreach ($fixture['cases'] as $case) {
    $profile = $normalizer->deterministicProfile($case['pages']);
    $grade = $grader->calculate($case['evidence'], $fixture['rule']);
    $results[] = [
        'id' => $case['id'],
        'fields' => [
            'name' => $profile['name']['value'],
            'phone' => $profile['phone']['value'],
            'email' => $profile['email']['value'],
        ],
        'grade' => $grade['system_grade'],
        'total_score' => $grade['total_score'],
    ];
}
echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
`;
const execution = spawnSync('php', ['-r', php, fixturePath.pathname], {
  cwd: new URL('.', root),
  encoding: 'utf8',
});
if (execution.status !== 0) {
  process.stderr.write(execution.stderr || execution.stdout || 'Regression execution failed\n');
  process.exit(execution.status || 1);
}

const actual = JSON.parse(execution.stdout);
let fieldChecks = 0;
let fieldMatches = 0;
let evidenceChecks = 0;
let evidenceMatches = 0;
let gradeMatches = 0;
let expectedAb = 0;
let recalledAb = 0;
const gradeDistribution = { A: 0, B: 0, C: 0 };
const failures = [];

fixture.cases.forEach((testCase, index) => {
  const result = actual[index];
  for (const [field, expected] of Object.entries(testCase.expected_fields)) {
    fieldChecks += 1;
    if (result?.fields?.[field] === expected) fieldMatches += 1;
    else failures.push(`${testCase.id}: ${field}`);
  }
  for (const evidence of testCase.evidence) {
    evidenceChecks += 1;
    const page = testCase.pages.find((item) => item.page_no === evidence.page_no);
    if (page && page.text.includes(evidence.source_text)) evidenceMatches += 1;
    else failures.push(`${testCase.id}: evidence page ${evidence.page_no}`);
  }
  if (result?.grade === testCase.expected_grade) gradeMatches += 1;
  else failures.push(`${testCase.id}: grade ${result?.grade || 'missing'}`);
  if (['A', 'B'].includes(testCase.expected_grade)) {
    expectedAb += 1;
    if (['A', 'B'].includes(result?.grade)) recalledAb += 1;
  }
  if (result?.grade in gradeDistribution) gradeDistribution[result.grade] += 1;
});

const ratio = (matched, total) => total ? Number((matched / total).toFixed(4)) : 1;
const report = {
  fixture_version: fixture.version,
  versions: {
    parser: versions.parser || 'deterministic-v1',
    prompt: versions.prompt || 'fixture-no-model',
    model: versions.model || 'fixture-no-model',
    scoring: versions.scoring || 'resume-grade-v1',
  },
  case_count: fixture.cases.length,
  metrics: {
    field_accuracy: ratio(fieldMatches, fieldChecks),
    evidence_validity: ratio(evidenceMatches, evidenceChecks),
    grade_accuracy: ratio(gradeMatches, fixture.cases.length),
    ab_recall: ratio(recalledAb, expectedAb),
    failure_rate: ratio(failures.length, fieldChecks + evidenceChecks + fixture.cases.length),
    grade_distribution: gradeDistribution,
  },
  failures,
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;
