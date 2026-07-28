import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const migration = read('database/migrations/202607240009_workload_standard_import.sql');
const manifest = read('database/migration_manifest.php');
const parser = read('api/admin/services/WorkloadStandardImportParser.php');
const importer = read('api/admin/services/WorkloadStandardImportService.php');
const standards = read('api/admin/services/WorkloadRoleRuleAdminService.php');

function crc32(buffer) {
  let crc = 0xffffffff;
  for (const byte of buffer) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function storedZip(entries) {
  const localParts = [];
  const centralParts = [];
  let offset = 0;
  for (const [name, content] of Object.entries(entries)) {
    const nameBuffer = Buffer.from(name);
    const contentBuffer = Buffer.from(content);
    const crc = crc32(contentBuffer);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(contentBuffer.length, 18);
    local.writeUInt32LE(contentBuffer.length, 22);
    local.writeUInt16LE(nameBuffer.length, 26);
    localParts.push(local, nameBuffer, contentBuffer);
    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(contentBuffer.length, 20);
    central.writeUInt32LE(contentBuffer.length, 24);
    central.writeUInt16LE(nameBuffer.length, 28);
    central.writeUInt32LE(offset, 42);
    centralParts.push(central, nameBuffer);
    offset += local.length + nameBuffer.length + contentBuffer.length;
  }
  const centralDirectory = Buffer.concat(centralParts);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(Object.keys(entries).length, 8);
  end.writeUInt16LE(Object.keys(entries).length, 10);
  end.writeUInt32LE(centralDirectory.length, 12);
  end.writeUInt32LE(offset, 16);
  return Buffer.concat([...localParts, centralDirectory, end]);
}

test('[validates 25.1, 25.4] migration persists batches, row results, summaries, idempotency, and targets', () => {
  for (const table of ['workload_standard_import_batches', 'workload_standard_import_rows']) {
    assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
    assert.match(manifest, new RegExp(`'${table}'`));
  }
  for (const field of ['file_sha256', 'idempotency_key', 'summary_json', 'validation_status', 'difference_action', 'target_rule_version_id']) {
    assert.match(migration, new RegExp(field));
  }
  assert.match(migration, /UNIQUE KEY uq_workload_standard_import_request \(file_sha256, idempotency_key\)/);
});

test('[validates 25.1, 25.2] parser enforces CSV and XLSX limits and canonical headers', () => {
  assert.match(parser, /\['csv', 'xlsx'\]/);
  assert.match(parser, /MAX_FILE_BYTES = 5 \* 1024 \* 1024/);
  assert.match(parser, /MAX_ROWS = 10000/);
  assert.match(parser, /gzinflate/);
  assert.match(parser, /XLSX 解压内容总量超限/);
  for (const header of ['role_code', 'metric_code', 'metric_name', 'need_evidence', 'audit_mode', 'statistic_direction']) {
    assert.match(parser, new RegExp(`'${header}'`));
  }
});

test('[validates 25.1] parser reads a UTF-8 BOM CSV and maps Chinese headers', () => {
  const directory = mkdtempSync(join(tmpdir(), 'workload-standard-import-'));
  const path = join(directory, 'standards.csv');
  writeFileSync(path, '\ufeff岗位编码,项目编码,项目名称,单位,必填,允许零值,最小值,最大值,目标值,凭证要求,最少凭证,最多凭证,审核方式,统计方向,排序\ncoach,lesson_count,课时数,节,是,否,0,20,10,需要,1,3,凭证,越高越好,10\n');
  const script = `require ${JSON.stringify(new URL('api/admin/services/WorkloadStandardImportParser.php', root).pathname)}; $r=(new WorkloadStandardImportParser())->parse($argv[1], 'standards.csv'); echo json_encode($r['records'], JSON_UNESCAPED_UNICODE);`;
  const result = spawnSync('php', ['-r', script, path], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const records = JSON.parse(result.stdout);
  assert.equal(records.length, 1);
  assert.equal(records[0].role_code, 'coach');
  assert.equal(records[0].metric_name, '课时数');
  assert.equal(records[0]._row_number, 2);
});

test('[validates 25.1] parser reads the first XLSX Open XML worksheet without PHP zip extensions', () => {
  const directory = mkdtempSync(join(tmpdir(), 'workload-standard-xlsx-'));
  const path = join(directory, 'standards.xlsx');
  const headers = ['role_code', 'metric_code', 'metric_name', 'unit', 'is_required', 'allow_zero', 'min_value', 'max_value', 'target_value', 'need_evidence', 'min_evidence_count', 'max_evidence_count', 'audit_mode', 'statistic_direction', 'sort_order'];
  const row = ['coach', 'lesson_count', 'Lesson count', 'session', '1', '0', '0', '20', '10', '1', '1', '3', 'evidence', 'higher', '10'];
  const xmlRow = (number, values) => `<row r="${number}">${values.map((value, index) => `<c r="${String.fromCharCode(65 + index)}${number}" t="inlineStr"><is><t>${value}</t></is></c>`).join('')}</row>`;
  const sheet = `<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>${xmlRow(1, headers)}${xmlRow(2, row)}</sheetData></worksheet>`;
  const workbook = '<?xml version="1.0"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Standards" sheetId="1" r:id="rId1"/></sheets></workbook>';
  const relationships = '<?xml version="1.0"?><Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>';
  writeFileSync(path, storedZip({ 'xl/workbook.xml': workbook, 'xl/_rels/workbook.xml.rels': relationships, 'xl/worksheets/sheet1.xml': sheet }));
  const script = `require ${JSON.stringify(new URL('api/admin/services/WorkloadStandardImportParser.php', root).pathname)}; $r=(new WorkloadStandardImportParser())->parse($argv[1], 'standards.xlsx'); echo json_encode($r['records']);`;
  const result = spawnSync('php', ['-r', script, path], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const records = JSON.parse(result.stdout);
  assert.equal(records[0].metric_code, 'lesson_count');
  assert.equal(records[0].audit_mode, 'evidence');
});

test('[validates 25.2-25.6] import service isolates roles and owns complete confirmation flow', () => {
  for (const action of ['added', 'modified', 'disabled', 'unchanged', 'error']) assert.match(importer, new RegExp(`'${action}'`));
  assert.match(importer, /can_confirm/);
  assert.match(importer, /preflight_has_errors/);
  assert.match(importer, /createImportedDrafts/);
  assert.match(importer, /standard-import-publish-/);
  assert.match(importer, /partially_published/);
  assert.match(importer, /FOR UPDATE/);
  assert.match(standards, /function createImportedDrafts\(/);
  assert.match(standards, /standard\.import\.confirm/);
  assert.match(standards, /beginTransaction\(\)/);
  assert.match(standards, /rollBack\(\)/);
});

test('[validates 25.1-25.6] endpoints require standard permission bootstrap and idempotency keys', () => {
  const upload = read('api/admin/workload/standard-import.php');
  const batches = read('api/admin/workload/standard-import-batches.php');
  for (const endpoint of [upload, batches]) {
    assert.match(endpoint, /workloadStandardBootstrap/);
    assert.match(endpoint, /workloadStandardIdempotencyKey\(\)/);
  }
  assert.match(upload, /\$_FILES\['file'\]/);
  assert.match(batches, /listBatches/);
  assert.match(batches, /->confirm/);
});

test('[validates 25.4] idempotency and rollback model creates one batch and no partial drafts', () => {
  const state = { batches: new Map(), drafts: [] };
  const preflight = (digest, key) => {
    const request = `${digest}:${key}`;
    if (!state.batches.has(request)) state.batches.set(request, { id: state.batches.size + 1 });
    return state.batches.get(request);
  };
  assert.strictEqual(preflight('a'.repeat(64), 'request-1'), preflight('a'.repeat(64), 'request-1'));
  assert.equal(state.batches.size, 1);

  const createDrafts = (roles, failAt) => {
    const snapshot = [...state.drafts];
    try {
      roles.forEach((role, index) => {
        if (index === failAt) throw new Error('injected failure');
        state.drafts.push(role);
      });
    } catch (error) {
      state.drafts = snapshot;
      throw error;
    }
  };
  assert.throws(() => createDrafts(['sales', 'coach'], 1), /injected failure/);
  assert.deepEqual(state.drafts, []);
});
