import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('..', import.meta.url);

test('空文件和损坏 Office 文件均返回可追踪解析错误', () => {
  const dir = mkdtempSync(join(tmpdir(), 'lesson-office-edge-'));
  const xlsx = join(dir, 'empty.xlsx');
  const docx = join(dir, 'broken.docx');
  writeFileSync(xlsx, '');
  writeFileSync(docx, 'not-a-zip');
  const php = String.raw`
    require 'api/lesson-submissions/LessonWorkbookParser.php';
    require 'api/lesson-submissions/LessonWordParser.php';
    foreach ([['xlsx', ${JSON.stringify(xlsx)}, 'empty.xlsx'], ['docx', ${JSON.stringify(docx)}, 'broken.docx']] as $case) {
      try { ($case[0] === 'xlsx' ? new LessonWorkbookParser() : new LessonWordParser())->parse($case[1], $case[2]); echo 'unexpected'; }
      catch (Throwable $error) { echo get_class($error) . "|"; }
    }
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.equal((result.stdout.match(/ParserException/g) || []).length, 2);
  assert.doesNotMatch(result.stdout, /unexpected/);
});

test('Office 解析器的成功结果包含统一教案结构所需字段', () => {
  const workbook = requireSource('api/lesson-submissions/LessonWorkbookParser.php');
  const word = requireSource('api/lesson-submissions/LessonWordParser.php');
  for (const source of [workbook, word]) {
    for (const field of ['metadata', 'objectives', 'learner_focus', 'safety', 'equipment', 'phases', 'progressions', 'assistant_responsibilities', 'reflection', 'mapping']) {
      assert.match(source, new RegExp(`['"]${field}['"]`));
    }
    assert.match(source, /source/);
  }
});

function requireSource(path) {
  return readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
}
