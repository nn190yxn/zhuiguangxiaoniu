import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

test('XLSX export declares the fixed twenty-two columns in order', () => {
  const service = read('api/admin/recruitment/services/RecruitmentExportService.php');
  const columns = ['批次编号', '招聘需求编号', '门店', '应聘岗位', '姓名', '手机号', '来源文件', '当前或最近岗位', '工作年限', '行业经历', '经验摘要', '教育与专业', '技能与证书', '简历亮点', '命中关键词', '硬性条件状态', '人工核验项', '匹配分', '等级', '建议理由', '联系状态', '联系备注'];
  let cursor = -1;
  for (const column of columns) {
    const next = service.indexOf(`'${column}'`, cursor + 1);
    assert.ok(next > cursor, `${column} should appear in fixed order`);
    cursor = next;
  }
  assert.match(service, /A1:V/);
});

test('export defaults to authorized appointment A and B candidates', () => {
  const service = read('api/admin/recruitment/services/RecruitmentExportService.php');
  assert.match(service, /application\.queue_status = 'appointment'/);
  assert.match(service, /application\.effective_grade IN \('A', 'B'\)/);
  assert.match(service, /requirementWhereClause\(\$scope, 'requirement'\)/);
  assert.match(service, /authorized_requirement_ids/);
  assert.match(service, /assertJobScope/);
});

test('workbook includes overview and stable requirement sheets', () => {
  const service = read('api/admin/recruitment/services/RecruitmentExportService.php');
  assert.match(service, /\$groups = \['总览' => \$rows\]/);
  assert.match(service, /requirement_name/);
  assert.match(service, /ORDER BY requirement\.requirement_no ASC/);
  assert.match(service, /sheetNames/);
});

test('export protects formulas, storage paths and short-lived downloads', () => {
  const service = read('api/admin/recruitment/services/RecruitmentExportService.php');
  const endpoint = read('api/admin/recruitment/export.php');
  assert.ok(service.includes("preg_match('/^[=+\\-@]/u'"));
  assert.match(service, /INTERVAL 30 MINUTE/);
  assert.match(service, /str_starts_with\(\$path, \$root/);
  assert.match(endpoint, /recruitment\.resume_contact/);
  assert.match(endpoint, /resume\.export\.download/);
  assert.match(endpoint, /Cache-Control: private, no-store/);
});

test('generated workbook is a readable multi-sheet XLSX archive', () => {
  const source = `
    require 'api/admin/recruitment/services/RecruitmentExportService.php';
    $service = (new ReflectionClass(RecruitmentExportService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(RecruitmentExportService::class, 'writeWorkbook');
    $path = tempnam(sys_get_temp_dir(), 'resume-export-');
    $rows = [
      ['requirement_id' => 1, 'requirement_name' => 'REQ-1-教练', 'values' => array_pad(['B001', 'REQ-1', '=危险公式'], 22, '')],
      ['requirement_id' => 2, 'requirement_name' => 'REQ-2-课程顾问', 'values' => array_pad(['B002', 'REQ-2', '门店二'], 22, '')],
    ];
    $method->invoke($service, $path, $rows);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { exit(2); }
    $workbook = $zip->getFromName('xl/workbook.xml');
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    echo json_encode(['entries' => $zip->numFiles, 'sheets' => substr_count($workbook, '<sheet '), 'formula_safe' => str_contains($sheet, '&apos;=危险公式'), 'has_last_column' => str_contains($sheet, 'r="V1"')], JSON_UNESCAPED_UNICODE);
    $zip->close();
  `;
  const result = spawnSync('php', ['-r', source], { cwd: new URL('..', import.meta.url), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  const parsed = JSON.parse(result.stdout);
  assert.ok(parsed.entries >= 8);
  assert.equal(parsed.sheets, 3);
  assert.equal(parsed.formula_safe, true);
  assert.equal(parsed.has_last_column, true);
});
