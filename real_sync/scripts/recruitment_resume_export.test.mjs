import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const hasZipArchive = spawnSync('php', ['-r', 'exit(class_exists("ZipArchive") ? 0 : 1);']).status === 0;

test('XLSX export declares the fixed twenty-seven columns in order', () => {
  const service = read('api/admin/recruitment/services/RecruitmentExportService.php');
  const columns = ['批次编号', '录入时间', '招聘需求编号', '门店', '应聘岗位', '姓名', '手机号', '来源文件', '当前或最近岗位', '工作年限', '行业经历', '经验摘要', '教育与专业', '技能与证书', '简历亮点', '命中关键词', '硬性条件状态', '人工核验项', '匹配分', '等级', '匹配分析说明', '简历收到日期', '下次联系日期', '跟进人', '联系状态', '联系备注', '重复标记'];
  let cursor = -1;
  for (const column of columns) {
    const next = service.indexOf(`'${column}'`, cursor + 1);
    assert.ok(next > cursor, `${column} should appear in fixed order`);
    cursor = next;
  }
  assert.match(service, /columnName\(\$colCount\)/);
  assert.match(service, /UNCLASSIFIED_COLUMNS/);
});

test('export includes authorized A, B and C candidates across queues', () => {
  const service = read('api/admin/recruitment/services/RecruitmentExportService.php');
  const page = read('admin/recruitment-resumes.html');
  assert.match(service, /application\.effective_grade IN \('A', 'B', 'C'\)/);
  assert.doesNotMatch(service, /application\.queue_status = 'appointment'/);
  assert.match(service, /\['A', 'B', 'C'\]/);
  assert.match(page, /按收到日期分表导出全部简历/);
  assert.match(page, /行 A\/B\/C 候选人/);
  assert.match(service, /requirementWhereClause\(\$scope, 'requirement'\)/);
  assert.match(service, /authorized_requirement_ids/);
  assert.match(service, /assertJobScope/);
  assert.match(service, /scope_mode/);
  assert.match(service, /录入结束日期需晚于或等于开始日期/);
  assert.match(service, /queryUnclassifiedRows\(\$query, \$scope\)/);
  assert.match(service, /d\.batch_id = \?/);
  assert.match(page, /id="exportScope"/);
  assert.match(page, /scope_mode:document\.getElementById\('exportScope'\)\.value/);
  assert.match(page, /pageSize:100/);
});

test('export decrypts the full phone number before its masked display value', () => {
  const service = read('api/admin/recruitment/services/RecruitmentExportService.php');
  const full = service.indexOf("decrypt($row['phone_ciphertext'] ?? null)");
  const masked = service.indexOf("decrypt($row['phone_display_ciphertext'] ?? null)");
  assert.ok(full >= 0 && masked > full, 'full contact phone should take precedence in exports');
});

test('workbook includes overview and stable requirement sheets', () => {
  const service = read('api/admin/recruitment/services/RecruitmentExportService.php');
  assert.match(service, /\$groups = \['总览' => \$rows\]/);
  assert.match(service, /'requirement_name' => trim\(\(string\) \$row\['position_name_snapshot'\]\) \?: '未命名岗位'/);
  assert.match(service, /ORDER BY document\.created_at DESC, application\.id DESC/);
  assert.match(service, /document_created_at:desc,application_id:desc/);
  assert.match(service, /sheetNames/);
});

test('export protects formulas, storage paths and short-lived downloads', () => {
  const service = read('api/admin/recruitment/services/RecruitmentExportService.php');
  const endpoint = read('api/admin/recruitment/export.php');
  assert.ok(service.includes("preg_match('/^[=+\\-@]/u'"));
  assert.match(service, /dirname\(__DIR__, 4\) \. '\/.private\/recruitment-exports'/);
  assert.match(service, /INTERVAL 30 MINUTE/);
  assert.match(service, /str_starts_with\(\$path, \$root/);
  assert.match(endpoint, /recruitment\.resume_contact/);
  assert.match(endpoint, /resume\.export\.download/);
  assert.match(endpoint, /Cache-Control: private, no-store/);
});

test('generated workbook is a readable multi-sheet XLSX archive', { skip: !hasZipArchive }, () => {
  const source = `
    require 'api/admin/recruitment/services/RecruitmentExportService.php';
    $service = (new ReflectionClass(RecruitmentExportService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(RecruitmentExportService::class, 'writeWorkbook');
    $path = tempnam(sys_get_temp_dir(), 'resume-export-');
    $rows = [
      ['requirement_id' => 1, 'requirement_name' => 'REQ-1-教练', 'values' => array_pad(['B001', 'REQ-1', '=危险公式'], 27, '')],
      ['requirement_id' => 2, 'requirement_name' => 'REQ-2-课程顾问', 'values' => array_pad(['B002', 'REQ-2', '门店二'], 27, '')],
    ];
    $method->invoke($service, $path, $rows, []);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { exit(2); }
    $workbook = $zip->getFromName('xl/workbook.xml');
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    echo json_encode(['entries' => $zip->numFiles, 'sheets' => substr_count($workbook, '<sheet '), 'formula_safe' => str_contains($sheet, '&apos;=危险公式'), 'has_last_column' => str_contains($sheet, 'r="AA1"')], JSON_UNESCAPED_UNICODE);
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

test('generated workbook creates date sheets for all source document states', { skip: !hasZipArchive }, () => {
  const source = `
    require 'api/admin/recruitment/services/RecruitmentExportService.php';
    $service = (new ReflectionClass(RecruitmentExportService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(RecruitmentExportService::class, 'writeWorkbook');
    $path = tempnam(sys_get_temp_dir(), 'resume-date-export-');
    $rows = [
      ['document_id' => 11, 'received_date' => '2026-08-30', 'received_at' => '2026-08-30 09:00', 'position_sort_order' => 20, 'classification_status' => 'classified', 'requirement_id' => 2, 'requirement_name' => '课程顾问', 'values' => array_pad(['B001', '2026-08-30 09:00', 'REQ-2'], 27, '')],
      ['document_id' => 12, 'received_date' => '2026-08-31', 'received_at' => '2026-08-31 09:00', 'position_sort_order' => 10, 'classification_status' => 'failed', 'requirement_id' => 1, 'requirement_name' => '教练', 'values' => array_pad(['B002', '2026-08-31 09:00', 'REQ-1'], 27, '')],
    ];
    $unclassified = [['document_id' => 13, 'received_date' => '2026-08-31', 'received_at' => '2026-08-31', 'position_sort_order' => null, 'classification_status' => 'needs_confirmation', 'requirement_id' => 0, 'requirement_name' => '未归类确认', 'values' => ['姓名', '手机号', 'resume.pdf', '教练', '亮点', '2026-08-31']]];
    $failed = [];
    $method->invoke($service, $path, $rows, $unclassified, $failed);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { exit(2); }
    $workbook = $zip->getFromName('xl/workbook.xml');
    preg_match_all('/<sheet name="([^"]+)"/', $workbook, $matches);
    $dateSheet = $zip->getFromName('xl/worksheets/sheet3.xml');
    echo json_encode(['names' => $matches[1], 'date_has_status' => str_contains($dateSheet, '处理状态'), 'date_has_failed' => str_contains($dateSheet, '处理失败'), 'date_has_unclassified' => str_contains($dateSheet, '待确认岗位')], JSON_UNESCAPED_UNICODE);
    $zip->close();
  `;
  const result = spawnSync('php', ['-r', source], { cwd: new URL('..', import.meta.url), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  const parsed = JSON.parse(result.stdout);
  assert.deepEqual(parsed.names.slice(0, 3), ['总览', '2026-08-30', '2026-08-31']);
  assert.equal(parsed.date_has_status, true);
  assert.equal(parsed.date_has_failed, true);
  assert.equal(parsed.date_has_unclassified, true);
});
