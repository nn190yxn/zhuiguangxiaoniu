import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const page = read('../fitness-assessment-app.html');
const landingPage = read('../fitness-assessment.html');
const endpoint = read('../api/ai-services.php');
const runtime = read('../api/ai-runtime.php');

test('fitness assessment entry links bust cached app HTML', () => {
  assert.match(page, /http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate"/);
  assert.match(page, /http-equiv="Pragma" content="no-cache"/);
  assert.match(page, /http-equiv="Expires" content="0"/);
  assert.match(landingPage, /href="\/fitness-assessment-app\.html\?v=20260728-ocr"/);
  assert.doesNotMatch(landingPage, /href="\/fitness-assessment-app\.html"/);
});

test('fitness assessment sends compressed authenticated OCR requests to the backend', () => {
  assert.match(page, /var compressed = await compressImage\(base64Image, 1600\)/);
  assert.match(page, /fetch\('\/api\/ai-services\.php', \{/);
  assert.match(page, /headers: buildAuthHeaders\(\{/);
  assert.match(page, /action: 'ocr'/);
  assert.match(page, /imageDataUrl: compressed/);
});

test('fitness OCR keeps HTTP status for auth-specific browser errors', () => {
  assert.match(page, /error\.status = response\.status/);
  assert.match(page, /err\.status === 401 \|\| err\.status === 403/);
  assert.match(page, /登录状态已失效，请重新登录后再识别/);
  assert.match(page, /请先登录员工账号，再使用拍照识别/);
  assert.match(page, /err\.status === 400/);
  assert.match(page, /err\.status === 503/);
  assert.match(page, /window\.requirePageAuth\(\{ maxRetries: 1 \}\)/);
});

test('AI service writes sanitized OCR failure logs', () => {
  assert.match(endpoint, /function ai_log_service_error\(string \$action, string \$requestId, Throwable \$exception\): void/);
  assert.match(endpoint, /'action' => \$action/);
  assert.match(endpoint, /'request_id' => \$requestId/);
  assert.match(endpoint, /'error_code' => substr\(hash\('sha256'/);
  assert.doesNotMatch(endpoint, /'message' => \$exception->getMessage\(\)/);
  assert.match(endpoint, /ai-services-errors\.log/);
  assert.match(endpoint, /ai_log_service_error\(\$action, \$requestId, \$exception\)/);
  assert.match(runtime, /function ai_log_ocr_failure\(string \$requestId, string \$stage/);
  assert.match(runtime, /'error_code' => substr\(hash\('sha256'/);
  assert.doesNotMatch(runtime, /'ocr_text' =>/);
});

test('fitness OCR result logs retain operational metadata without report contents', () => {
  const logStart = runtime.indexOf('function ai_log_ocr_result(');
  const logEnd = runtime.indexOf('function ai_ocr_failure_code(', logStart);
  const logFunction = runtime.slice(logStart, logEnd);

  assert.ok(logStart >= 0 && logEnd > logStart);
  for (const field of ['request_id', 'provider', 'duration_ms', 'input_bytes', 'measurement_field_count']) {
    assert.match(logFunction, new RegExp(`'${field}'`));
  }
  assert.doesNotMatch(logFunction, /'name'|'height'|'weight'|ocr_text/);
});

test('fitness OCR passes an optional rating-completion image to the runtime', () => {
  const ocrActionStart = endpoint.indexOf("if ($action === 'ocr')");
  const nextActionStart = endpoint.indexOf("if ($action === 'summer_camp_report')", ocrActionStart);
  const ocrAction = endpoint.slice(ocrActionStart, nextActionStart);

  assert.ok(ocrActionStart >= 0 && nextActionStart > ocrActionStart);
  assert.match(ocrAction, /if \(ai_has_service\('doubao'\)\)/);
  assert.match(ocrAction, /\$imageUrl = ai_store_ocr_image\(\$imageDataUrl\)/);
  assert.match(ocrAction, /\$result = ai_ocr_fitness_image\(\$imageDataUrl, \$imageUrl, \$prompt, array\(/);
  assert.match(ocrAction, /'request_id' => \$requestId/);
});

test('fitness OCR uses the platform Baidu gateway and local parsing without DeepSeek structuring', () => {
  const functionStart = runtime.indexOf('function ai_ocr_fitness_image(');
  const functionEnd = runtime.indexOf('function ai_zhipu_vision(', functionStart);
  const ocrFunction = runtime.slice(functionStart, functionEnd);
  const baiduCall = ocrFunction.indexOf("ai_gateway_ocr_extract($imageDataUrl, 'fitness_ocr', $options)");
  const localParserCall = ocrFunction.indexOf('ai_parse_fitness_ocr_text($ocrText)');

  assert.ok(functionStart >= 0 && functionEnd > functionStart);
  assert.ok(baiduCall >= 0);
  assert.ok(localParserCall > baiduCall);
  assert.match(ocrFunction, /catch \(Throwable \$exception\)/);
  assert.match(ocrFunction, /ai_has_ocr_measurement\(\$result\)/);
  assert.match(ocrFunction, /return \$result;/);
  assert.doesNotMatch(ocrFunction, /ai_parse_ocr_text_with_deepseek/);
  assert.doesNotMatch(ocrFunction, /ai_has_service\('deepseek'\)/);
  assert.doesNotMatch(ocrFunction, /zhipu|智谱/i);
});

test('fitness OCR keeps platform approval, audit, and provider routing', () => {
  assert.match(runtime, /PlatformAiCapabilityGateway::OCR_EXTRACT/);
  assert.match(runtime, /'preferred_provider' => 'baidu_ocr'/);
  assert.match(runtime, /'business_authorized' => \(\$options\['business_authorized'\] \?\? false\) === true/);
  assert.match(runtime, /'approval_id' => \(string\) \(\$options\['approval_id'\]/);
  assert.match(runtime, /'max_attempts' => \(int\) \(\$options\['max_attempts'\]/);
});

test('fitness OCR readiness requires only Baidu OCR while reports still use DeepSeek', () => {
  assert.match(runtime, /function ai_ocr_ready\(\): bool\s*\{\s*return ai_has_service\('baidu_ocr'\);\s*\}/);
  assert.match(endpoint, /'reportAiReady' => ai_has_service\('deepseek'\)/);
});

test('fitness OCR reads backend AI settings before direct database fallbacks', () => {
  const loaderStart = runtime.indexOf('function ai_runtime_load_settings(): array');
  const loaderEnd = runtime.indexOf('function ai_has_service(string $name): bool', loaderStart);
  const loader = runtime.slice(loaderStart, loaderEnd);

  assert.ok(loaderStart >= 0 && loaderEnd > loaderStart);
  assert.match(loader, /function_exists\('ai_load_settings'\)/);
  assert.match(loader, /\$adminSettings = ai_load_settings\(\)/);
  assert.match(loader, /array_key_exists\(\(string\) \$key, \$settings\)/);
  assert.ok(loader.indexOf('$adminSettings = ai_load_settings()') < loader.indexOf('$configSource = __DIR__'));
});

test('fitness assessment falls back to manual entry when OCR returns no result', () => {
  assert.match(page, /if \(ocrResult\)/);
  assert.match(page, /识别失败，已切换为手动录入/);
  assert.match(page, /focusFirstTestInput\(\)/);
});

test('fitness assessment maps OCR field aliases and retains source ratings', () => {
  assert.match(page, /function normalizeOCRFieldKey\(key\)/);
  assert.match(page, /'jump_rope': 'rope_skip'/);
  assert.match(page, /'situp': 'sit_ups'/);
  assert.match(page, /state\.imageRatings\[key\.slice\(0, -7\)\] = normalizedData\[key\]/);
});

test('fitness assessment fills normalized OCR values into matching inputs', () => {
  assert.match(page, /function fillFormFromOCR\(data\)/);
  assert.match(page, /var normalizedData = normalizeOCRData\(data\)/);
  assert.match(page, /document\.getElementById\('input_' \+ key\)/);
  assert.match(page, /input\.value = normalizedData\[key\]/);
  assert.match(page, /autoRate\(key\)/);
});

test('fitness OCR rejects responses without a numeric measurement', () => {
  const functionStart = runtime.indexOf('function ai_ocr_fitness_image(');
  const functionEnd = runtime.indexOf('function ai_get_summer_camp_system_prompt(', functionStart);
  const ocrFunction = runtime.slice(functionStart, functionEnd);

  assert.match(ocrFunction, /if \(!ai_has_ocr_measurement\(\$result\)\)/);
  assert.match(ocrFunction, /OCR 未提取到有效体测数据/);
});

test('DeepSeek uses the model supported by the production gateway', () => {
  assert.match(runtime, /'deepseek_model' => trim\(\(string\) \(getenv\('DEEPSEEK_MODEL'\) \?: 'deepseek-v4-flash'\)\)/);
  assert.match(runtime, /\$model = trim\(\(string\) \(\$settings\['deepseek_model'\] \?\? 'deepseek-v4-flash'\)\)/);
  assert.match(runtime, /'model' => \$model/);
  assert.doesNotMatch(runtime, /'model' => 'deepseek-chat'/);
});

test('fitness OCR validates numeric measurements and keeps vital capacity unmapped', () => {
  const script = [
    "require 'api/ai-runtime.php';",
    "if (ai_numeric_measurement('无法识别') !== null) exit(1);",
    "if (ai_numeric_measurement('168 cm') !== 168.0) exit(2);",
    "if (ai_normalize_result_field_key('vital_capacity') !== null) exit(3);",
    "if (ai_normalize_vision_input('AAAA') !== 'AAAA') exit(4);",
  ].join(' ');
  execFileSync('php', ['-r', script], { cwd: new URL('../', import.meta.url), stdio: 'pipe' });
});

test('fitness OCR normalizes common Chinese structured fields', () => {
  const script = [
    "require 'api/ai-runtime.php';",
    "$result = ai_normalize_ocr_result(array('身高' => '100CM 欠佳', '体能测评项目' => array(array('项目名称' => '立定跳远', '测试结果' => '74CM 合格', '评级' => '合格'), array('项目名称' => '双脚连续跳', '成绩' => '13.28秒 较差', '等级' => '较差'))));",
    "if (($result['height'] ?? '') !== '100CM 欠佳') exit(1);",
    "if (($result['standing_jump'] ?? '') !== '74CM 合格') exit(2);",
    "if (($result['standing_jump_rating'] ?? '') !== '合格') exit(3);",
    "if (($result['continuous_jump'] ?? '') !== '13.28秒 较差') exit(4);",
    "if (!ai_has_ocr_measurement($result)) exit(5);",
    "if (ai_numeric_measurement($result['standing_jump']) !== 74.0) exit(6);",
  ].join(' ');
  execFileSync('php', ['-r', script], { cwd: new URL('../', import.meta.url), stdio: 'pipe' });
});

test('fitness OCR locally extracts report fields from Baidu text', () => {
  assert.match(runtime, /function ai_parse_fitness_ocr_text\(string \$ocrText\): array/);
  assert.match(runtime, /\$provider = 'baidu_ocr_deterministic_parser';/);
  const script = [
    "require 'api/ai-runtime.php';",
    '$text = "基础体能标准测评报告\\n身高100cm 欠佳\\n体重17kg 标准\\n立定跳远（爆发力）74CM 合格\\n双脚连续跳（协调能力）13.28秒 较差\\n网球掷远（上肢力量）6M 良好\\n坐位体前屈（柔韧性）7.8CM 欠佳\\n走平衡木（平衡力）8.38秒 合格\\n十米折返跑（灵敏力）8.65秒 欠佳";',
    "$result = ai_parse_fitness_ocr_text($text);",
    "if (($result['height'] ?? null) !== 100) exit(1);",
    "if (($result['height_rating'] ?? '') !== '欠佳') exit(2);",
    "if (($result['standing_jump'] ?? null) !== 74) exit(3);",
    "if (($result['continuous_jump'] ?? null) !== 13.28) exit(4);",
    "if (($result['tennis_throw'] ?? null) !== 6) exit(5);",
    "if (($result['shuttle_run'] ?? null) !== 8.65) exit(6);",
    "if (!ai_has_ocr_measurement($result)) exit(7);",
  ].join(' ');
  execFileSync('php', ['-r', script], { cwd: new URL('../', import.meta.url), stdio: 'pipe' });
});

test('fitness OCR local parser does not cross lines when a label has no value', () => {
  const script = [
    "require 'api/ai-runtime.php';",
    '$text = "BMI\\n身高\\n欠佳\\n124.8cm优秀\\n十米折返跑（灵敏力）6.09秒\\n立定跳远（爆发力）130CM";',
    "$result = ai_parse_fitness_ocr_text($text);",
    "if (isset($result['bmi'])) exit(1);",
    "if (($result['shuttle_run'] ?? null) !== 6.09) exit(2);",
    "if (($result['standing_jump'] ?? null) !== 130) exit(3);",
  ].join(' ');
  execFileSync('php', ['-r', script], { cwd: new URL('../', import.meta.url), stdio: 'pipe' });
});

test('fitness OCR validates image input before calling the provider', () => {
  assert.match(runtime, /array\('jpeg', 'jpg', 'png', 'webp'\)/);
  assert.match(runtime, /base64_decode\(\$imageInput, true\)/);
  assert.match(runtime, /4 \* 1024 \* 1024/);
  assert.match(runtime, /function ai_numeric_measurement/);
  assert.match(runtime, /function ai_has_ocr_measurement/);
  assert.match(runtime, /ai_gateway_ocr_extract\(\$imageDataUrl, 'fitness_ocr', \$options\)/);
});
