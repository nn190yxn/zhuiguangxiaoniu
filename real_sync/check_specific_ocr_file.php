<?php
declare(strict_types=1);

require '/www/wwwroot/122.51.223.46/api/config.php';
require '/www/wwwroot/122.51.223.46/api/ai-runtime.php';

$file = $argv[1] ?? '';
if ($file === '') {
    fwrite(STDERR, "missing filename\n");
    exit(1);
}

$imagePath = '/www/wwwroot/122.51.223.46/wp-content/uploads/ocr-cache/' . basename($file);
$imageDataUrl = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($imagePath));
$imageUrl = 'http://122.51.223.46/wp-content/uploads/ocr-cache/' . basename($file);
$prompt = <<<'PROMPT'
请从这张儿童体测报告中提取以下数据，并以JSON格式返回，只返回JSON不要其他文字：

当前只识别“3-6岁幼儿组”项目，不属于该年龄段的项目一律返回 null，不要猜测，不要混填。
重要：请逐项识别测试值和图片上原始评级标签，评级文字优先以图片原文为准，不要自行改写。身体形态项目常见评级可能是"标准""正常""偏高""偏低""偏胖""偏瘦"。

1. 儿童基本信息：姓名、性别、年龄（岁和月）、测试日期
2. 身体形态：身高(cm)、体重(kg)
3. 当前年龄段项目及评级：
- 立定跳远(cm) -> standing_jump / standing_jump_rating
- 双脚连续跳(秒) -> continuous_jump / continuous_jump_rating
- 网球掷远(m) -> tennis_throw / tennis_throw_rating
- 坐位体前屈(cm) -> sit_reach / sit_reach_rating
- 走平衡木(秒) -> balance_beam / balance_beam_rating
- 10米折返跑(秒) -> shuttle_run / shuttle_run_rating

评级从图片中直接读取，必须是以下之一：优秀、良好、中等、合格、一般、较差、欠佳、标准、偏胖、偏瘦、正常、偏高、偏低、待提升。如果图片中某项没有评级，设为null。

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
  "weight_rating": "评级或null"
}

如果某项数据无法识别，请设为null。只返回JSON。
PROMPT;

try {
    $ocrText = ai_baidu_ocr_text($imageDataUrl);
    echo "FILE: " . basename($file) . "\n";
    echo "=== BAIDU OCR TEXT ===\n";
    echo $ocrText . "\n\n";

    $structured = ai_ocr_fitness_image($imageDataUrl, $imageUrl, $prompt);
    echo "=== STRUCTURED RESULT ===\n";
    echo json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $exception) {
    echo 'ERROR: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
