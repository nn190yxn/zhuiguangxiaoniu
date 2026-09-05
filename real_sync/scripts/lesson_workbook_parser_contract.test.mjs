import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => new URL(`../${path}`, import.meta.url);

test('XLSX 解析器输出多 Sheet、单元格、合并区域和统一字段映射', () => {
  const dir = mkdtempSync(join(tmpdir(), 'lesson-parser-'));
  const path = join(dir, 'lesson.xlsx');
  const php = String.raw`
    $zip = new ZipArchive(); $zip->open(${JSON.stringify(path)}, ZipArchive::CREATE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="基本信息" sheetId="1" r:id="rId1"/><sheet name="流程" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>标题</t></is></c><c r="B1" t="inlineStr"><is><t>基础跳跃</t></is></c></row></sheetData><mergeCells count="1"><mergeCell ref="A1:B1"/></mergeCells></worksheet>');
    $zip->addFromString('xl/worksheets/sheet2.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="2"><c r="A2" t="inlineStr"><is><t>安全</t></is></c><c r="B2" t="inlineStr"><is><t>落地保护</t></is></c></row></sheetData></worksheet>'); $zip->close();
  `;
  const create = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(create.status, 0, create.stderr);
  const parse = String.raw`
    require 'api/lesson-submissions/LessonWorkbookParser.php';
    echo json_encode((new LessonWorkbookParser())->parse(${JSON.stringify(path)}, 'lesson.xlsx'), JSON_UNESCAPED_UNICODE);
  `;
  const result = spawnSync('php', ['-r', parse], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const parsed = JSON.parse(result.stdout);
  assert.equal(parsed.sheets.length, 2);
  assert.deepEqual(parsed.sheets[0].merged_ranges, ['A1:B1']);
  assert.equal(parsed.content.metadata.title, '基础跳跃');
  assert.equal(parsed.content.safety.physical, '落地保护');
  assert.equal(parsed.content.mapping.title.source.cell, 'B1');
  rmSync(dir, { recursive: true, force: true });
});

test('XLS 旧格式返回可交给手工录入回退的明确错误', () => {
  const dir = mkdtempSync(join(tmpdir(), 'lesson-parser-'));
  const path = join(dir, 'lesson.xls');
  writeFileSync(path, 'legacy workbook');
  const php = String.raw`
    require 'api/lesson-submissions/LessonWorkbookParser.php';
    try { (new LessonWorkbookParser())->parse(${JSON.stringify(path)}, 'lesson.xls'); echo 'unexpected'; }
    catch (Throwable $error) { echo $error->getMessage(); }
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /旧版 XLS/);
  assert.doesNotMatch(result.stdout, /unexpected/);
  rmSync(dir, { recursive: true, force: true });
});
