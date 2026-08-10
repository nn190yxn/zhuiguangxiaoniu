import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const migration = read('database/migrations/202608100001_mixed_resume_classification.sql');
const manifest = read('database/migration_manifest.php');
const catalog = read('database/migration_catalog.php');

test('mixed resume migration keeps single-position batches compatible', () => {
  assert.match(migration, /MODIFY requirement_id BIGINT UNSIGNED NULL/);
  assert.match(migration, /MODIFY rule_version_id BIGINT UNSIGNED NULL/);
  assert.match(migration, /intake_mode ENUM\(''single_requirement'', ''mixed_requirements''\)/);
  assert.match(migration, /candidate_scope_hash CHAR\(64\)/);
  assert.match(migration, /classification_status ENUM\(''awaiting_upload'', ''awaiting_rules'', ''queued'', ''processing'', ''completed'', ''partial_failed''\)/);
});

test('mixed resume migration records candidate scope and classification history', () => {
  for (const table of [
    'recruitment_resume_batch_requirements',
    'recruitment_resume_classification_versions',
    'recruitment_resume_classification_candidates',
    'recruitment_resume_classification_reviews',
  ]) {
    assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`));
    assert.match(manifest, new RegExp(`'${table}'`));
  }
  assert.match(migration, /classification_ready TINYINT\(1\) NOT NULL DEFAULT 0/);
  assert.match(migration, /classification_version_id BIGINT UNSIGNED NULL/);
  assert.match(migration, /review_reason VARCHAR\(1000\) NOT NULL/);
});

test('mixed resume migration is registered with a stable checksum and structural checks', () => {
  assert.match(catalog, /'202608100001' => '35738b82a892ed728ad6a99a3516c4bf46155ea64b3264f3dd9fcdd705e8c7ac'/);
  assert.match(catalog, /recruitment_mixed_classification_contract_valid/);
  assert.match(manifest, /'202608100001' => \[/);
});

test('classification service uses deterministic evidence and preserves ranked versions', () => {
  const service = read('api/admin/recruitment/services/ResumeClassificationService.php');
  assert.match(service, /AUTO_ASSIGN_THRESHOLD = 75\.0/);
  assert.match(service, /MINIMUM_MARGIN = 15\.0/);
  assert.match(service, /filename/);
  assert.match(service, /hard_condition/);
  assert.match(service, /recruitment_resume_classification_versions/);
  assert.match(service, /recruitment_resume_classification_candidates/);
  assert.match(service, /FOR UPDATE/);
});

test('mixed processing classifies before entering the single-requirement pipeline', () => {
  const processing = read('api/admin/recruitment/services/ResumeProcessingService.php');
  assert.match(processing, /ResumeClassificationService/);
  assert.match(processing, /\$this->classification->classify/);
  assert.match(processing, /\$classification\['status'\].*!== 'classified'/);
  assert.match(processing, /document\.assigned_requirement_id/);
});

test('published rules activate waiting mixed-batch classification jobs', () => {
  const rules = read('api/admin/recruitment/services/RecruitmentRuleService.php');
  assert.match(rules, /activateMixedClassificationForRule/);
  assert.match(rules, /classification_ready = 1/);
  assert.match(rules, /mixed-classify-rule/);
  assert.match(rules, /RecruitmentPlatformJobAdapter/);
});

test('classification review API protects confirmation with a version and idempotency key', () => {
  const service = read('api/admin/recruitment/services/ResumeClassificationReviewService.php');
  const endpoint = read('api/admin/recruitment/classifications.php');
  assert.match(service, /classification_version_id/);
  assert.match(service, /FOR UPDATE/);
  assert.match(service, /recruitment_resume_classification_reviews/);
  assert.match(service, /assertAccessibleDocument/);
  assert.match(service, /evidenceSummary/);
  assert.match(service, /candidates\(\(int\) \$item\['classification_version_id'\], \$scope\)/);
  assert.match(service, /requirementWhereClause\(\$scope, 'requirement'\)/);
  assert.match(endpoint, /recruitmentAdminRequireIdempotency/);
  assert.match(endpoint, /\['confirm', 'reclassify'\]/);
});

test('resume workbench supports mixed uploads and classification review actions', () => {
  const page = read('admin/recruitment-resumes.html');
  assert.match(page, /id="intakeMode"/);
  assert.match(page, /value="mixed_requirements"/);
  assert.match(page, /value="mixed_requirements" selected/);
  assert.match(page, /id="requirementField" hidden/);
  assert.match(page, /intakeMode'\)\.value='mixed_requirements'/);
  assert.match(page, /action:'create_mixed'/);
  assert.match(page, /id="classificationList"/);
  assert.match(page, /loadClassifications/);
  assert.match(page, /data-classification-id/);
  assert.match(page, /data-reclassify-id/);
  assert.match(page, /evidence_summary/);
  assert.match(page, /action:'confirm'/);
  assert.match(page, /action:'reclassify'/);
  assert.match(page, /intakeMode'\)\.value==='single_requirement'&&requirementId/);
});
