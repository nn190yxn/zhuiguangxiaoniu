import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('..', import.meta.url);

test('DOCX 解析器输出标题、段落、列表、表格和原始位置映射', () => {
  const dir = mkdtempSync(join(tmpdir(), 'lesson-word-'));
  const path = join(dir, 'lesson.docx');
  const phpCreate = String.raw`
    $z = new ZipArchive(); $z->open(${JSON.stringify(path)}, ZipArchive::CREATE);
    $z->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>跳跃训练教案</w:t></w:r></w:p><w:p><w:r><w:t>安全：落地保护</w:t></w:r></w:p><w:p><w:pPr><w:numPr><w:ilvl w:val="0"/></w:numPr></w:pPr><w:r><w:t>热身活动</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>器材</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>软垫</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:sectPr/></w:body></w:document>'); $z->close();
  `;
  const created = spawnSync('php', ['-r', phpCreate], { cwd: root, encoding: 'utf8' });
  assert.equal(created.status, 0, created.stderr);
  const phpParse = String.raw`require 'api/lesson-submissions/LessonWordParser.php'; echo json_encode((new LessonWordParser())->parse(${JSON.stringify(path)}, 'lesson.docx'), JSON_UNESCAPED_UNICODE);`;
  const result = spawnSync('php', ['-r', phpParse], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const parsed = JSON.parse(result.stdout);
  assert.equal(parsed.blocks[0].type, 'heading');
  assert.equal(parsed.blocks[2].type, 'list_item');
  assert.equal(parsed.blocks[3].type, 'table');
  assert.equal(parsed.content.metadata.title, '跳跃训练教案');
  assert.equal(parsed.content.safety.physical, '落地保护');
  assert.equal(parsed.content.equipment[0].value, '软垫');
  assert.equal(parsed.content.mapping.physical_safety.source.paragraph, 2);
  rmSync(dir, { recursive: true, force: true });
});
