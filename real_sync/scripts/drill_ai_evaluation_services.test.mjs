import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const policyPath = fileURLToPath(new URL('../api/drill/v2/services/DrillEvaluationPolicy.php', import.meta.url));
const adapterPath = fileURLToPath(new URL('../api/drill/v2/services/DrillAiAdapter.php', import.meta.url));
const championPrompt = readFileSync(new URL('../api/drill/v2/services/DrillChampionCoachPrompt.php', import.meta.url), 'utf8');
const contentPackage = readFileSync(new URL('../api/drill/v2/services/DrillNewSignContentPackage.php', import.meta.url), 'utf8');
const service = readFileSync(new URL('../api/drill/v2/services/DrillEvaluationService.php', import.meta.url), 'utf8');
const speaker = readFileSync(new URL('../api/drill/v2/services/DrillSpeakerMappingService.php', import.meta.url), 'utf8');
const report = readFileSync(new URL('../api/drill/v2/services/DrillEvaluationReportService.php', import.meta.url), 'utf8');
const conversation = readFileSync(new URL('../api/drill/v2/services/DrillConversationService.php', import.meta.url), 'utf8');
const migration = readFileSync(new URL('../database/migrations/202607280005_drill_ai_evaluation_services.sql', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');

function evaluate(expression) {
  const php = [`require_once ${JSON.stringify(policyPath)};`, 'try {', `$value = ${expression};`, "echo json_encode(['ok'=>true,'value'=>$value]);", '} catch (Throwable $e) {', "echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);", '}'].join('\n');
  const result = spawnSync('php', ['-r', php], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('AI adapter is injected and persists only controlled metadata references', () => {
  const adapter = readFileSync(adapterPath, 'utf8');
  assert.match(adapter, /callable \$chat/);
  assert.match(adapter, /fromProjectRuntime/);
  assert.match(adapter, /DRILL_AI_PROVIDER/);
  assert.doesNotMatch(adapter, /MCAI_LLM_|OPENAI_API_KEY/);
  assert.match(adapter, /raw_response_ref/);
  assert.match(adapter, /hash\('sha256'/);
  assert.match(adapter, /DrillChampionCoachPrompt::system/);
  assert.match(championPrompt, /销冠教练/);
  for (const dimension of ['需求挖掘', 'FAB 价值转化', '案例植入', '方案匹配与规划', '报价与谈判策略', '异议处理', '试关闭', '紧迫感制造']) {
    assert.match(championPrompt, new RegExp(dimension));
  }
  assert.match(championPrompt, /evidence_status=insufficient_evidence/);
  assert.match(conversation, /submitTextTurnWithGeneratedCustomer/);
  assert.match(conversation, /generateCustomerTurn/);
});

test('new signing contexts route to their locked rubrics', () => {
  assert.equal(evaluate("(function(){ DrillEvaluationPolicy::assertRoute('new_signing','real_call_review','new_sign_real_call_v1'); return true; })()").value, true);
  assert.equal(evaluate("(function(){ DrillEvaluationPolicy::assertRoute('new_signing','training_demo','new_sign_real_call_v1'); return true; })()").ok, false);
});

test('V2 readiness and critical items use the upgraded eight-dimension rubric', () => {
  const policy = readFileSync(new URL('../api/drill/v2/services/DrillEvaluationPolicy.php', import.meta.url), 'utf8');
  assert.match(policy, /'fab_conversion' => 14/);
  assert.doesNotMatch(policy, /'fab_value' => 14/);
  assert.match(contentPackage, /'code' => 'speaker_mapping_complete'/);
  assert.match(contentPackage, /'code' => 'evidence_traceable'/);
  assert.match(contentPackage, /'code' => 'no_fabricated_quote'/);
});

test('hybrid scoring combines dimensions and critical items within bounds', () => {
  const expression = "DrillEvaluationPolicy::score(['mode'=>'hybrid','max_score'=>100,'score_policy'=>['capability_weight'=>.75,'script_match_weight'=>.25],'dimensions'=>[['code'=>'needs','weight'=>60],['code'=>'close','weight'=>40]],'critical_items'=>[['code'=>'needs_gate','dimension_code'=>'needs','minimum_score'=>30]]],['dimension_scores'=>['needs'=>['capability_score'=>48,'script_match_score'=>36],'close'=>['capability_score'=>32,'script_match_score'=>28]],'critical_results'=>[]])";
  const result = evaluate(expression);
  assert.equal(result.ok, true);
  assert.equal(result.value.total_score, 76);
  assert.equal(result.value.critical_results.needs_gate.passed, true);
});

test('training demo excludes coach supplements and evidence must locate scoreable segments', () => {
  const segments = "[['id'=>1,'speaker_key'=>'trainee','is_coach_supplement'=>false],['id'=>2,'speaker_key'=>'trainee','is_coach_supplement'=>true]]";
  assert.deepEqual(evaluate(`DrillEvaluationPolicy::scoreableSegments(${segments}, 'trainee', 'training_demo')`).value.map((item) => item.id), [1]);
  assert.equal(evaluate("DrillEvaluationPolicy::validateAiEvaluation(['dimension_scores'=>[],'critical_results'=>[],'evidence'=>[['segment_id'=>9]]],[['id'=>1]],100)").ok, false);
  assert.equal(evaluate("DrillEvaluationPolicy::score(['mode'=>'capability','max_score'=>10,'dimensions'=>[['code'=>'needs','weight'=>10]],'critical_items'=>[]],['dimension_scores'=>[],'critical_results'=>[]])").value.dimension_scores.needs.evidence_status, 'insufficient_evidence');
});

test('evaluation services create retry states, evidence, reports, and SMART actions', () => {
  for (const method of ['evaluate', 'upsertEvaluation', 'persistEvidence', 'markRetryPending']) assert.match(service, new RegExp(`function ${method}\\(`));
  assert.match(service, /'retry_pending'/);
  assert.match(service, /drill_evaluation_evidence/);
  assert.match(speaker, /speaker_confirmation_required/);
  assert.match(report, /drill_report_action_items/);
  assert.match(report, /learning_resource_version/);
  assert.match(report, /SMART 训练任务字段不完整/);
});

test('evaluation fallback requires insufficient evidence instead of text-length scoring', () => {
  const adapter = readFileSync(adapterPath, 'utf8');
  assert.match(adapter, /return \$this->insufficientEvidenceEvaluation\(\$context, \$error\)/);
  assert.doesNotMatch(adapter, /deterministic_text_coverage/);
  assert.doesNotMatch(adapter, /\$characterCount \/ 120/);
});

test('migration and manifest retain controlled evaluation metadata', () => {
  for (const column of ['provider', 'model', 'prompt_version', 'duration_ms']) assert.match(migration, new RegExp(`ADD COLUMN ${column}`));
  assert.match(manifest, /'202607280005'/);
  assert.match(manifest, /'drill_evaluations' => \['provider', 'model', 'prompt_version', 'duration_ms'\]/);
});
