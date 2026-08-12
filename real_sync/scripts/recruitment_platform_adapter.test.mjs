import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('招聘域登记 BIZ-010 至 BIZ-013、代表入口和现有消费者', { skip: !hasPhp }, () => {
  const registry = runPhp(String.raw`
    require 'api/platform/BusinessDomainRegistry.php';
    echo json_encode(PlatformBusinessDomainRegistry::get('recruitment'));
  `);
  assert.deepEqual(registry.function_ids, ['BIZ-010', 'BIZ-011', 'BIZ-012', 'BIZ-013']);
  assert.equal(registry.endpoint, 'api/admin/recruitment/candidates.php');
  assert.ok(registry.capabilities.includes('platform_job_queue'));
  assert.ok(registry.capabilities.includes('hire_to_employee'));
  for (const consumer of registry.legacy_consumers) {
    assert.equal(existsSync(new URL(`../${consumer}`, import.meta.url)), true, consumer);
  }
});

test('招聘核心读写入口接入 Kernel、具名权限、兼容元数据、审计和状态版本', () => {
  for (const endpoint of ['api/admin/recruitment/candidates.php', 'api/admin/recruitment/candidate-contact.php']) {
    const source = read(endpoint);
    assert.match(source, /kernel\/bootstrap\.php/, endpoint);
    assert.match(source, /platformApiContext\(\['domain' => 'recruitment'/, endpoint);
    assert.match(source, /platformApiAuthContext\(/, endpoint);
    assert.match(source, /requirePermission\('recruitment\./, endpoint);
    assert.match(source, /PlatformApiCompatibility::withMetadata\(/, endpoint);
    assert.match(source, /PlatformApiLogger/, endpoint);
  }
  assert.match(read('api/admin/recruitment/candidates.php'), /state_version/);
  assert.match(read('api/admin/recruitment/candidate-contact.php') + read('api/admin/recruitment/services/ResumeReviewService.php'), /PlatformStateVersion/);
});

test('招聘 AI 与 OCR Adapter 仅复用平台 Runtime 且固定供应商职责', () => {
  const ai = read('api/admin/recruitment/platform/RecruitmentPlatformAiAdapter.php');
  assert.match(ai, /ai_gateway_text_generate\(/);
  assert.match(ai, /preferred_provider[^\n]*stepfun_recruitment|StepFun/);
  assert.doesNotMatch(ai, /ai_deepseek_chat\(/);
  const ocr = read('api/admin/recruitment/platform/RecruitmentPlatformOcrAdapter.php');
  assert.match(ocr, /ai_gateway_ocr_extract\(/);
  assert.match(ocr, /baidu_ocr/);
  assert.match(ocr, /finfo_file\(\$finfo, \$path\)/);
  assert.match(ocr, /\['image\/jpeg', 'image\/png', 'image\/webp'\]/);
  assert.match(ocr, /'data:' \. \$mimeType \. ';base64,'/);
  assert.doesNotMatch(ocr, /data:application\/octet-stream/);
  assert.doesNotMatch(ocr, /tesseract|proc_open|vision\.extract/i);
  assert.match(ai + ocr, /business_authorized/);
  assert.match(ai + ocr, /approval_id/);
});

test('招聘新文件进入平台私有存储且预览执行二次鉴权和历史受控兼容', () => {
  const adapter = read('api/admin/recruitment/platform/RecruitmentPlatformFileAdapter.php');
  const upload = read('api/admin/recruitment/services/ResumeUploadService.php');
  const preview = read('api/admin/recruitment/resume-preview.php');
  assert.match(adapter, /PlatformPrivateFileStorage/);
  assert.match(adapter, /PlatformFileAssetService/);
  assert.match(adapter, /SENSITIVE_SOURCE/);
  assert.match(upload, /RecruitmentPlatformFileAdapter/);
  assert.doesNotMatch(upload, /move_uploaded_file\(/);
  assert.match(preview, /RecruitmentPlatformFileAdapter/);
  assert.match(preview, /recruitment\.resume_original_view/);
  assert.match(preview, /prepareDownload\(/);
  assert.match(adapter, /legacy/i);
});

test('招聘长任务 Handler 通过 platform registry 复用权威处理服务', () => {
  const registry = read('api/platform/jobs/registry.php');
  const handler = read('api/platform/jobs/RecruitmentResumeJobHandler.php');
  assert.match(registry, /recruitment\.resume\.process/);
  assert.match(handler, /ResumeProcessingService/);
  assert.match(handler, /admin\/recruitment\/_common\.php/);
  assert.match(handler, /processJob\(/);
  assert.match(handler, /assertCurrent\(/);
  assert.match(handler, /heartbeatIfDue\(/);
});

test('招聘处理任务复用 64 位任务哈希作为平台幂等键', () => {
  const adapter = read('api/admin/recruitment/platform/RecruitmentPlatformJobAdapter.php');
  assert.match(adapter, /\(string\) \$job\['idempotency_hash'\],/);
  assert.doesNotMatch(adapter, /recruitment\.resume\.process:' \./);
});

test('招聘联系变化通过事务 outbox 投影提醒且保持现有状态机', () => {
  const projection = read('api/admin/recruitment/platform/RecruitmentReminderProjection.php');
  const review = read('api/admin/recruitment/services/ResumeReviewService.php');
  assert.match(projection, /PlatformOutboxService/);
  assert.match(projection, /recruitment\.(?:contact|hire)/);
  assert.match(review, /RecruitmentReminderProjection/);
  assert.match(review, /contact_status/);
  assert.match(projection, /idempotency/i);
});

test('BIZ-013 转员工端点要求审批、幂等、事务、权限并复用员工生命周期服务', () => {
  const endpoint = read('api/admin/recruitment/hire-to-employee.php');
  const approvalEndpoint = read('api/admin/recruitment/hire-approval.php');
  const service = read('api/admin/recruitment/services/HireToEmployeeService.php');
  assert.match(endpoint, /recruitment\.hire_convert/);
  assert.match(approvalEndpoint, /recruitment\.hire_approve/);
  assert.match(endpoint, /Idempotency-Key|idempotency_key/);
  assert.match(endpoint, /PlatformApiCompatibility::withMetadata/);
  assert.match(service, /StaffLifecycleService/);
  assert.match(service, /FOR UPDATE/);
  assert.match(service, /beginTransaction\(/);
  assert.match(service, /recruitment_hire_approvals/);
  assert.match(service, /recruitment_hire_conversions/);
  assert.match(service, /RecruitmentReminderProjection/);
  assert.match(service, /response_json/);
  assert.match(service, /approvalResponse\(\$existing, \(int\) \$existing\['state_version'\]\)/);
  assert.ok(
    service.indexOf('$application = $this->lockApplication($applicationId, $scope);', service.indexOf('public function convert'))
      < service.indexOf('$conversion = $this->existingConversion($applicationId, $idempotencyKey);'),
    '转换重放必须先校验投递数据范围'
  );
  assert.match(service, /schema_not_ready|结构/);
});

test('招聘平台增量迁移登记状态版本、录用审批和幂等转换结构', () => {
  const migration = read('database/migrations/202608020002_recruitment_platform_adapter.sql');
  const catalog = read('database/migration_catalog.php');
  assert.match(migration, /recruitment_hire_approvals/);
  assert.match(migration, /recruitment_hire_conversions/);
  assert.match(migration, /state_version/);
  assert.match(migration, /platform_asset_id/);
  assert.match(catalog, /202608020002/);
  assert.doesNotMatch(read('database/migration_manifest.php'), /202608020002/);
});

test('招聘请求、Adapter 和 Handler 均不执行运行时 DDL', () => {
  const paths = [
    'api/admin/recruitment/candidates.php',
    'api/admin/recruitment/candidate-contact.php',
    'api/admin/recruitment/hire-approval.php',
    'api/admin/recruitment/hire-to-employee.php',
    'api/admin/recruitment/platform/RecruitmentPlatformAiAdapter.php',
    'api/admin/recruitment/platform/RecruitmentPlatformOcrAdapter.php',
    'api/admin/recruitment/platform/RecruitmentPlatformFileAdapter.php',
    'api/admin/recruitment/platform/RecruitmentReminderProjection.php',
    'api/admin/recruitment/services/HireToEmployeeService.php',
    'api/platform/jobs/RecruitmentResumeJobHandler.php',
  ];
  const source = paths.map(read).join('\n');
  assert.doesNotMatch(source, /\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+(?:TABLE|INDEX)\b/i);
});
