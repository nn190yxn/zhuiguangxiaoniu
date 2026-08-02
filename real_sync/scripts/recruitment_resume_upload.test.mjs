import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const uploadService = read('api/admin/recruitment/services/ResumeUploadService.php');
const fileAdapter = read('api/admin/recruitment/platform/RecruitmentPlatformFileAdapter.php');
const privateStorage = read('api/platform/PrivateFileStorage.php');
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
});

test('controlled storage uses random keys outside the web application root', () => {
  assert.match(uploadService, /RecruitmentPlatformFileAdapter/);
  assert.match(fileAdapter, /PlatformPrivateFileStorage/);
  assert.match(privateStorage, /PLATFORM_PRIVATE_FILE_ROOT/);
  assert.match(privateStorage, /\.private\/platform-files/);
  assert.match(privateStorage, /bin2hex\(random_bytes\(24\)\)/);
  assert.match(privateStorage, /chmod\(\$target, 0600\)/);
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
