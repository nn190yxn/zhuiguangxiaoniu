import assert from 'node:assert/strict';
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
  assert.match(endpoint, /function ai_log_service_error\(string \$action, int \$userId, Throwable \$exception\): void/);
  assert.match(endpoint, /'action' => \$action/);
  assert.match(endpoint, /'user_id' => \$userId/);
  assert.match(endpoint, /'message' => \$exception->getMessage\(\)/);
  assert.match(endpoint, /ai-services-errors\.log/);
  assert.match(endpoint, /ai_log_service_error\(\$action, \$currentUserId, \$exception\)/);
});

test('fitness OCR bypasses the unused public image cache and vision fallback', () => {
  const ocrActionStart = endpoint.indexOf("if ($action === 'ocr')");
  const nextActionStart = endpoint.indexOf("if ($action === 'summer_camp_report')", ocrActionStart);
  const ocrAction = endpoint.slice(ocrActionStart, nextActionStart);

  assert.ok(ocrActionStart >= 0 && nextActionStart > ocrActionStart);
  assert.doesNotMatch(ocrAction, /ai_store_ocr_image\(/);
  assert.doesNotMatch(ocrAction, /ai_has_service\('doubao'\)/);
  assert.match(ocrAction, /\$result = ai_ocr_fitness_image\(\$imageDataUrl, '', \$prompt\);/);
});

test('base OCR runs independently and Doubao requires a public image URL', () => {
  const functionStart = runtime.indexOf('function ai_ocr_fitness_image(');
  const functionEnd = runtime.indexOf('function ai_zhipu_vision(', functionStart);
  const ocrFunction = runtime.slice(functionStart, functionEnd);
  const baiduCall = ocrFunction.indexOf('ai_baidu_ocr_text($imageDataUrl)');
  const doubaoCall = ocrFunction.indexOf('ai_doubao_vision($imageUrl, $prompt)');

  assert.ok(functionStart >= 0 && functionEnd > functionStart);
  assert.ok(baiduCall >= 0 && doubaoCall > baiduCall);
  assert.match(
    ocrFunction,
    /if \(!empty\(\$missingRatingFields\) && ai_has_service\('doubao'\) && \$imageUrl !== ''\)/,
  );
  assert.match(ocrFunction, /return \$result;/);
});

test('DeepSeek uses the model supported by the production gateway', () => {
  assert.match(runtime, /'deepseek_model' => trim\(\(string\) \(getenv\('DEEPSEEK_MODEL'\) \?: 'deepseek-v4-flash'\)\)/);
  assert.match(runtime, /\$model = trim\(\(string\) \(\$settings\['deepseek_model'\] \?\? 'deepseek-v4-flash'\)\)/);
  assert.match(runtime, /'model' => \$model/);
  assert.doesNotMatch(runtime, /'model' => 'deepseek-chat'/);
});
