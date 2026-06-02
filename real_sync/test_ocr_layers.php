<?php
declare(strict_types=1);

require '/www/wwwroot/122.51.223.46/api/config.php';
require '/www/wwwroot/122.51.223.46/api/ai-runtime.php';

$imagePath = '/www/wwwroot/122.51.223.46/wp-content/uploads/ocr-cache/ocr-20260508-134637-a416f38a8fe6.jpg';
$imageDataUrl = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($imagePath));
$imageUrl = 'http://122.51.223.46/wp-content/uploads/ocr-cache/ocr-20260508-134637-a416f38a8fe6.jpg';
$prompt = <<<'PROMPT'
请从这张儿童体测报告中提取以下数据，并以JSON格式返回，只返回JSON不要其他文字：

当前只识别“7-12岁学龄组”项目，不属于该年龄段的项目一律返回 null，不要猜测，不要混填。
重要：请逐项识别测试值和图片上原始评级标签，评级文字优先以图片原文为准，不要自行改写。学龄组常见评级可能是"优秀""良好""中等""欠佳""较差"，身体形态项目常见评级可能是"标准""正常""偏高""偏低""偏胖""偏瘦"。

1. 儿童基本信息：姓名、性别、年龄（岁和月）、测试日期
2. 身体形态：身高(cm)、体重(kg)
3. 当前年龄段项目及评级：
- 立定跳远(cm) -> standing_jump / standing_jump_rating
- 一分钟跳绳(次) -> jump_rope / jump_rope_rating
- 仰卧起坐(次) -> situp / situp_rating
- 坐位体前屈(cm) -> sit_reach / sit_reach_rating
- 台阶测试(次) -> step_test / step_test_rating
- 4x10米往返跑(秒) -> shuttle_run_4x10 / shuttle_run_4x10_rating

评级从图片中直接读取，必须是以下之一：优秀、良好、中等、合格、一般、较差、欠佳、标准、偏胖、偏瘦、正常、偏高、偏低、待提升。如果图片中某项没有评级，设为null。

注意：评级非常重要，请务必仔细识别每一项的评级文字，不要遗漏，不要把"中等"改写成"合格"，也不要把"标准/正常"改写成"优秀"。

JSON格式：
{
  "name": "姓名",
  "gender": "男/女",
  "ageYears": 数字,
  "ageMonths": 数字,
  "testDate": "YYYY-MM-DD",
  "height": 数字,
  "height_rating": "评级或null",
  "weight": 数字,
  "weight_rating": "评级或null",
  "standing_jump": 数字,
  "standing_jump_rating": "评级或null",
  "jump_rope": 数字,
  "jump_rope_rating": "评级或null",
  "situp": 数字,
  "situp_rating": "评级或null",
  "sit_reach": 数字,
  "sit_reach_rating": "评级或null",
  "step_test": 数字,
  "step_test_rating": "评级或null",
  "shuttle_run_4x10": 数字,
  "shuttle_run_4x10_rating": "评级或null"
}

如果某项数据无法识别，请设为null。只返回JSON。
PROMPT;

try {
    $ocrText = ai_baidu_ocr_text($imageDataUrl);
    echo "=== BAIDU OCR TEXT ===\n";
    echo $ocrText . "\n\n";

    $structured = ai_ocr_fitness_image($imageDataUrl, $imageUrl, $prompt);
    echo "=== STRUCTURED RESULT ===\n";
    echo json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $exception) {
    echo 'ERROR: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
