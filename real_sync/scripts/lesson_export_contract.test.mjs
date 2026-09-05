import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('lesson export service generates version-bound modern Office files', () => {
  const service = read('api/lesson-submissions/LessonExportService.php');
  assert.match(service, /lesson_exports/);
  assert.match(service, /version_id/);
  assert.match(service, /completed_at/);
  assert.match(service, /storeBytes\(\$bytes, 'lesson-exports\/submission-' \./);
  assert.match(service, /基本信息/);
  assert.match(service, /课程流程/);
  assert.match(service, /安全与器材/);
  assert.match(service, /ACE反思/);
  assert.match(service, /application\/vnd\.openxmlformats-officedocument/);
});

test('generated Office packages preserve structured lesson fields', () => {
  const php = String.raw`require_once 'api/platform/PrivateFileStorage.php'; require_once 'api/lesson-submissions/LessonExportService.php'; $class = new ReflectionClass('LessonExportService'); $service = $class->newInstanceWithoutConstructor(); $content = ['metadata' => ['store_name' => '测试门店', 'author_name' => '测试教练', 'course_line' => '体能', 'class_level' => '中班', 'lesson_date' => '2026-09-03', 'title' => '跳跃课'], 'objectives' => ['athletic' => 'A目标', 'cognitive' => 'C目标', 'engagement' => 'E目标'], 'safety' => ['physical' => '保护', 'psychological' => '鼓励'], 'equipment' => ['软垫'], 'progressions' => ['降低高度'], 'phases' => [['name' => '热身', 'duration_minutes' => 10, 'content' => '跑动']], 'assistant_responsibilities' => '观察', 'reflection' => ['athletic' => '完成', 'cognitive' => '理解', 'engagement' => '投入']]; foreach (['xlsx' => ['xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet4.xml'], 'docx' => ['word/document.xml']] as $format => $entries) { $method = $class->getMethod($format); $method->setAccessible(true); $bytes = $method->invoke($service, $content); $path = tempnam(sys_get_temp_dir(), 'lesson-export-test-'); file_put_contents($path, $bytes); $zip = new ZipArchive(); if ($zip->open($path) !== true) exit(10); foreach ($entries as $entry) { if ($zip->locateName($entry) === false) exit(11); } $document = $zip->getFromName($format === 'xlsx' ? 'xl/worksheets/sheet1.xml' : 'word/document.xml'); if (!is_string($document) || !str_contains($document, '测试门店') || !str_contains($document, '跳跃课')) exit(12); $zip->close(); unlink($path); } echo 'ok';`;
  const result = spawnSync('php', ['-r', php], { cwd: new URL('..', import.meta.url), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.equal(result.stdout, 'ok');
});

test('lesson export endpoint protects generation and download', () => {
  const endpoint = read('api/lesson-submissions/export.php');
  assert.match(endpoint, /requirePermission\('lesson_submission\.export'\)/);
  assert.match(endpoint, /method_not_allowed/);
  assert.match(endpoint, /LessonExportService/);
  assert.match(endpoint, /Content-Disposition/);
});

test('lesson export POST uses version-bound idempotency while GET keeps direct download', () => {
  const endpoint = read('api/lesson-submissions/export.php');
  const service = read('api/lesson-submissions/LessonExportService.php');
  assert.match(endpoint, /HTTP_IDEMPOTENCY_KEY/);
  assert.match(endpoint, /new PlatformIdempotencyService\(\$db\)/);
  assert.match(endpoint, /'lesson_submission\.export'/);
  assert.match(endpoint, /'submission:' \. \$submissionId \. ':version:' \. \$versionId \. ':format:' \. \$format/);
  assert.match(endpoint, /createWithinTransaction\(\$submissionId, \$format, \$staffId, \$versionId\)/);
  assert.match(endpoint, /if \(\$_SERVER\['REQUEST_METHOD'\] !== 'GET'\)/);
  assert.match(endpoint, /\$service->download\(/);
  assert.match(service, /public function create\(int \$submissionId, string \$format, int \$actorStaffId, \?int \$versionId = null\): array/);
  assert.match(service, /public function resolveVersionId\(/);
  assert.match(service, /public function createWithinTransaction\(/);
  const neutralBody = service.match(/public function createWithinTransaction[\s\S]*?\n    }\n\n    public function download/)?.[0] ?? '';
  assert.doesNotMatch(neutralBody, /beginTransaction|commit\(|rollBack\(/);
});

test('lesson editor exposes both standard export actions', () => {
  const html = read('lesson-submission.html');
  const js = read('js/lesson-submission.js');
  assert.match(html, /id="exportXlsxButton"/);
  assert.match(html, /id="exportDocxButton"/);
  assert.match(js, /exportLesson\('xlsx'\)/);
  assert.match(js, /exportLesson\('docx'\)/);
});

test('export download route is registered in both transport matrices', () => {
  for (const path of ['mini-program/business-domain-matrix.json', 'cloudfunctions/api-proxy/business-domain-matrix.json']) {
    const matrix = read(path);
    assert.match(matrix, /"method": "POST", "path": "\/lesson-submissions\/export\.php"/);
    assert.match(matrix, /"method": "GET", "path": "\/lesson-submissions\/export\.php"/);
  }
});
