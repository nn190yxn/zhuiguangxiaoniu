import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const common = read('api/admin/common.php');
const recruitmentCommon = read('api/admin/recruitment/_common.php');
const requirementService = read('api/admin/recruitment/services/RecruitmentRequirementService.php');
const ruleService = read('api/admin/recruitment/services/RecruitmentRuleService.php');
const requirementPage = read('admin/recruitment-requirements.html');
const rulePage = read('admin/recruitment-rules.html');
const migration = read('database/migrations/202607310001_recruitment_resume_screening.sql');

test('recruitment permissions are registered for scoped roles', () => {
  for (const permission of [
    'recruitment.requirement_manage',
    'recruitment.requirement_approve',
    'recruitment.rule_manage',
    'recruitment.rule_publish',
    'recruitment.resume_upload',
    'recruitment.resume_view',
    'recruitment.resume_contact',
    'recruitment.resume_export',
    'recruitment.resume_original_view',
    'recruitment.resume_phone_view',
    'recruitment.audit_view',
    'recruitment.retention_manage',
  ]) {
    assert.match(common, new RegExp(`'${permission.replaceAll('.', '\\.')}'`));
  }
});

test('placeholder endpoints report an unavailable business capability', () => {
  assert.match(recruitmentCommon, /jsonResponse\(501, '招聘接口业务实现尚未启用'/);
  assert.doesNotMatch(recruitmentCommon, /jsonResponse\(0, '招聘接口已接入/);
});

test('requirement and rule state transitions preserve approval gates', () => {
  assert.match(requirementService, /\['draft', 'returned'\]/);
  assert.match(requirementService, /\$before\['status'\] !== 'approval_pending'/);
  assert.match(requirementService, /退回需求必须填写原因/);
  assert.match(ruleService, /\$before\['status'\] !== 'draft'/);
  assert.match(ruleService, /\$before\['status'\] !== 'in_review'/);
  assert.match(ruleService, /发布硬性条件必须填写合法依据和岗位必要性/);
});

test('management pages preserve transition and hard-condition metadata', () => {
  assert.match(requirementPage, /prompt\('请输入退回原因'\)/);
  assert.match(rulePage, /currentHardConditions/);
  assert.match(rulePage, /saved\.legal_basis\|\|''/);
  assert.doesNotMatch(rulePage, /legal_basis:'待补充'/);
  assert.match(rulePage, /if\(rule\.status==='draft'\)html\+=.*提交<\/button>';if\(rule\.status==='in_review'\)html\+=.*发布<\/button>'/);
});

test('recruitment migration declares complete core domains', () => {
  for (const table of [
    'recruitment_requirements',
    'recruitment_rule_versions',
    'recruitment_resume_batches',
    'recruitment_resume_files',
    'recruitment_resume_documents',
    'recruitment_resume_jobs',
    'recruitment_candidates',
    'recruitment_applications',
    'recruitment_match_evidence',
    'recruitment_grade_results',
    'recruitment_export_jobs',
    'recruitment_retention_policies',
  ]) {
    assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`));
  }
});
