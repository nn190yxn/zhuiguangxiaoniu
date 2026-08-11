import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const uploadService = read('api/admin/recruitment/services/ResumeUploadService.php');
const fileAdapter = read('api/admin/recruitment/platform/RecruitmentPlatformFileAdapter.php');
const privateStorage = read('api/platform/PrivateFileStorage.php');
const textExtractor = read('api/admin/recruitment/services/ResumeTextExtractor.php');
const documentService = read('api/admin/recruitment/services/ResumeDocumentService.php');
const batches = read('api/admin/recruitment/batches.php');
const upload = read('api/admin/recruitment/upload.php');
const duplicate = read('api/admin/recruitment/resume-duplicate.php');

test('batch creation requires approved demand and published matching rule', () => {
  assert.match(uploadService, /status = 'approved'/);
  assert.match(uploadService, /status = 'published'/);
  assert.match(uploadService, /assertRuleMatchesRequirement/);
  assert.match(uploadService, /Idempotency-Key/);
});

test('mixed batch creation snapshots every visible requirement and allows unpublished rules', () => {
  assert.match(uploadService, /function createMixedBatch/);
  assert.match(uploadService, /batch\.create_mixed/);
  assert.match(uploadService, /mixedCandidateRequirements/);
  assert.match(uploadService, /requirement\.status <> 'closed'/);
  assert.match(uploadService, /latestPublishedRuleOrNull/);
  assert.match(uploadService, /'awaiting_publish'/);
  assert.match(uploadService, /recruitment_resume_batch_requirements/);
  assert.match(uploadService, /candidate_scope_hash/);
  assert.match(batches, /\['create', 'create_mixed'\]/);
  assert.match(batches, /createMixedBatch/);
});

test('mixed batch upload preserves scoped access and filename candidates', () => {
  assert.match(uploadService, /intake_mode.*mixed_requirements/);
  assert.match(uploadService, /batchRequirementIds/);
  assert.match(uploadService, /classification_ready = 0/);
  assert.match(uploadService, /'awaiting_rules'/);
  assert.match(uploadService, /filenameMatch\(\$name, \$scope, \$lockedBatch\)/);
});

test('upload contract enforces file count, byte and content limits', () => {
  assert.match(uploadService, /MAX_FILE_BYTES = 20 \* 1024 \* 1024/);
  assert.match(uploadService, /MAX_BATCH_BYTES = 2 \* 1024 \* 1024 \* 1024/);
  assert.match(uploadService, /MAX_BATCH_FILES = 500/);
  for (const extension of ['pdf', 'jpg', 'jpeg', 'png', 'webp']) {
    assert.match(uploadService, new RegExp(`'${extension}' =>`));
  }
  assert.match(uploadService, /finfo_open\(FILEINFO_MIME_TYPE\)/);
  assert.match(uploadService, /is_uploaded_file/);
  assert.match(upload, /\$_FILES\['files'\]/);
  assert.match(upload, /recruitmentAdminIdempotent/);
});

test('recruitment mutations use the shared idempotency result store', () => {
  for (const path of [
    'api/admin/recruitment/candidate-grade.php',
    'api/admin/recruitment/candidate-queue.php',
    'api/admin/recruitment/candidate-duplicate.php',
    'api/admin/recruitment/resume-duplicate.php',
    'api/admin/recruitment/candidate-contact.php',
    'api/admin/recruitment/batches.php',
    'api/admin/recruitment/export.php',
  ]) {
    assert.match(read(path), /recruitmentAdminIdempotent/);
  }
  assert.match(read('api/admin/recruitment/_common.php'), /同一写请求正在处理中/);
});

test('controlled storage uses random keys outside the web application root', () => {
  assert.match(uploadService, /RecruitmentPlatformFileAdapter/);
  assert.match(fileAdapter, /PlatformPrivateFileStorage/);
  assert.match(privateStorage, /PLATFORM_PRIVATE_FILE_ROOT/);
  assert.match(privateStorage, /\.private\/platform-files/);
  assert.match(privateStorage, /bin2hex\(random_bytes\(24\)\)/);
  assert.match(privateStorage, /chmod\(\$target, 0600\)/);
  assert.match(textExtractor, /PLATFORM_PRIVATE_FILE_ROOT/);
  assert.match(textExtractor, /\.private\/platform-files/);
});

test('exact duplicate workflow pauses and supports all resolutions', () => {
  assert.match(privateStorage, /hash_file\('sha256'/);
  assert.match(uploadService, /duplicate_type.*exact_document/);
  assert.match(uploadService, /'skip' => 'skipped'/);
  assert.match(uploadService, /'reuse' => 'reused'/);
  assert.match(uploadService, /'continue' => 'continued'/);
  assert.match(duplicate, /resolveDuplicate/);
});

test('image documents support ordered grouping, revision and splitting', () => {
  assert.match(documentService, /groupImages/);
  assert.match(documentService, /splitImages/);
  assert.match(documentService, /page_order/);
  assert.match(documentService, /revision_no/);
  assert.match(documentService, /status = 'superseded'/);
  assert.match(documentService, /status = 'cancelled'/);
  assert.match(batches, /group_images/);
  assert.match(batches, /split_images/);
});
