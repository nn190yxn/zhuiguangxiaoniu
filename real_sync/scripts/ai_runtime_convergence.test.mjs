import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const apiRuntimePath = fileURLToPath(new URL('../api/ai-runtime.php', import.meta.url));
const apiRuntime = read('../api/ai-runtime.php');
const compatibilityRuntime = read('../ai-runtime.php');
const aiServices = read('../api/ai-services.php');
const drillAdapter = read('../api/drill/v2/services/DrillAiAdapter.php');

test('root AI runtime is a thin compatibility entry for the authoritative API runtime', () => {
  assert.match(compatibilityRuntime, /require_once __DIR__ \. '\/api\/ai-runtime\.php';/);
  assert.doesNotMatch(compatibilityRuntime, /function ai_deepseek_chat\(/);
  assert.doesNotMatch(compatibilityRuntime, /function ai_baidu_ocr_text\(/);
});

test('authoritative runtime assembles the platform gateway for DeepSeek and Baidu OCR', () => {
  assert.match(apiRuntime, /platform\/AiCapabilityGateway\.php/);
  assert.match(apiRuntime, /function ai_runtime_gateway\(\): PlatformAiCapabilityGateway/);
  assert.match(apiRuntime, /PlatformAiCapabilityGateway::TEXT_GENERATE/);
  assert.match(apiRuntime, /PlatformAiCapabilityGateway::OCR_EXTRACT/);
  assert.match(apiRuntime, /'deepseek'/);
  assert.match(apiRuntime, /'baidu_ocr'/);
});

test('fitness OCR uses Baidu extraction and deterministic parsing with optional rating completion', () => {
  const functionStart = apiRuntime.indexOf('function ai_ocr_fitness_image(');
  const functionEnd = apiRuntime.indexOf('function ai_zhipu_vision(', functionStart);
  const ocrFunction = apiRuntime.slice(functionStart, functionEnd);

  assert.ok(functionStart >= 0 && functionEnd > functionStart);
  assert.match(ocrFunction, /ai_gateway_ocr_extract\(/);
  assert.match(ocrFunction, /ai_parse_fitness_ocr_text\(/);
  assert.doesNotMatch(ocrFunction, /ai_parse_ocr_text_with_deepseek\(/);
  assert.match(ocrFunction, /ai_fitness_ocr_missing_rating_fields\(\$result\) !== array\(\)/);
  assert.match(ocrFunction, /ai_has_service\('doubao'\)/);
  assert.match(ocrFunction, /ai_doubao_vision\(\$visionInput, ai_get_fitness_ocr_vision_prompt\(\$prompt\)\)/);
  assert.match(ocrFunction, /ai_merge_fitness_vision_result\(\$result, \$visionResponse\['result'\]\)/);
  assert.doesNotMatch(ocrFunction, /ai_has_service\('deepseek'\)/);
  assert.match(apiRuntime, /function ai_ocr_ready\(\): bool\s*\{\s*return ai_has_service\('baidu_ocr'\);/s);
  assert.doesNotMatch(apiRuntime, /'fitness_ocr_structure(?:_retry)?'/);
});

test('fitness interpretation and Drill v2 use gateway text generation while preserving consumers', () => {
  assert.match(aiServices, /ai_gateway_text_generate\(/);
  assert.match(aiServices, /'fitness_plan'/);
  assert.match(drillAdapter, /ai_gateway_text_generate\(/);
  assert.match(drillAdapter, /'sales_drill_text_generate'/);
  assert.match(drillAdapter, /'business_authorized' => true/);
  assert.match(drillAdapter, /raw_response_ref/);
});

test('deterministic fitness OCR parser extracts values and source ratings', () => {
  const sample = [
    '姓名：小牛',
    '性别：男',
    '年龄：5岁6个月',
    '测试日期：2026-07-31',
    '身高 118.5 cm 标准',
    '体重 24.2 kg 偏胖',
    '立定跳远 96 cm 良好',
    '双脚连续跳 6.8 秒 合格',
    '10米折返跑 8.4 秒 优秀',
  ].join('\n');
  const php = [
    `require_once ${JSON.stringify(apiRuntimePath)};`,
    `$result = ai_parse_fitness_ocr_text(${JSON.stringify(sample)});`,
    'echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);',
  ].join('\n');
  const result = spawnSync('php', ['-r', php], { encoding: 'utf8', timeout: 10_000 });

  assert.equal(result.status, 0, result.stderr);
  const parsed = JSON.parse(result.stdout);
  assert.equal(parsed.name, '小牛');
  assert.equal(parsed.gender, '男');
  assert.equal(parsed.ageYears, 5);
  assert.equal(parsed.ageMonths, 6);
  assert.equal(parsed.testDate, '2026-07-31');
  assert.equal(parsed.height, 118.5);
  assert.equal(parsed.height_rating, '标准');
  assert.equal(parsed.weight, 24.2);
  assert.equal(parsed.weight_rating, '偏胖');
  assert.equal(parsed.standing_jump, 96);
  assert.equal(parsed.standing_jump_rating, '良好');
  assert.equal(parsed.continuous_jump, 6.8);
  assert.equal(parsed.continuous_jump_rating, '合格');
  assert.equal(parsed.shuttle_run, 8.4);
  assert.equal(parsed.shuttle_run_rating, '优秀');
});
