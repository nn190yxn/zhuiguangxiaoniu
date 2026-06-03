<?php
/**
 * 瀵煎叆閿€鍞熀纭€鍗侀棶鍒拌瘽鏈煡璇嗗簱鍜屽煿璁崱鐗?
 * 鐢ㄤ簬鏂扮娴佺▼鐨勭牬鍐板拰闇€姹傛寲鎺?
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$dbHost = 'localhost';
$dbName = '_122_51_223_46';
$dbUser = '_122_51_223_46';
$dbPass = '<通过安全渠道获取>';

try {
    $db = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

echo "=== 閿€鍞熀纭€鍗侀棶瀵煎叆鑴氭湰 ===\n\n";

// 閿€鍞熀纭€鍗侀棶鏁版嵁
$salesTenQuestions = [
    [
        'number' => 1,
        'question' => '瀹堕暱涔嬪墠鏈変簡瑙ｈ繃鎴戜滑杩藉厜灏忕墰鍝佺墝鍚楋紵',
        'purpose' => '浜嗚В瀹㈡埛璁ょ煡搴?,
        'response' => '瀹堕暱"娌℃湁"鏃跺洖绛旓細"鎴戠粰鎮ㄧ畝鍗曚粙缁嶄竴涓?锛堟湁寮曞鎬х殑鍒囧叆閿€鍞幆鑺傦紝鑰屼笉鏄‖閿€鍞級',
        'keywords' => ['鍝佺墝浜嗚В', '寮€鍦虹櫧', '璁ょ煡搴?, '鐮村啺'],
        'scenario' => 'warmup'
    ],
    [
        'number' => 2,
        'question' => '鎮ㄤ粖澶╂槸鎬庝箞杩囨潵鐨勫憿锛?,
        'purpose' => '鍒ゆ柇璺濈',
        'response' => '閫氳繃璇㈤棶浜ら€氭柟寮忓垽鏂闀夸綇鍧€涓庨棬搴楃殑璺濈锛屼负鍚庣画鏈嶅姟鍗婂緞鍜屾湇鍔￠娆″仛鍙傝€?,
        'keywords' => ['璺濈', '浜ら€?, '浣忓潃', '鐮村啺'],
        'scenario' => 'warmup'
    ],
    [
        'number' => 3,
        'question' => '鐪嬪瀛愮殑鎬ф牸鎸哄ソ鐨勶紝涔嬪墠鏈変笂杩囧叴瓒ｇ彮鍚楋紵',
        'purpose' => '浜嗚В鏁欒偛鐞嗗康鍜屾秷璐瑰姏',
        'response' => '瀹堕暱"鏈変笂杩囩敾鐢诲拰閽㈢惔"鏃讹細锛堜簡瑙ｅ闀跨殑鏁欒偛鐞嗗康锛屽悓鏃剁敾鐢诲拰閽㈢惔鍦ㄥ競鍦轰笂灞炰簬璇惧崟浠峰亸楂樼殑璇剧▼锛屽垽鏂闀跨殑娑堣垂鍔涳級',
        'keywords' => ['鍏磋叮鐝?, '鏁欒偛鐞嗗康', '娑堣垂鍔?, '闇€姹傛寲鎺?],
        'scenario' => 'needs'
    ],
    [
        'number' => 4,
        'question' => '浠栦笂閽㈢惔杩欎釜鍏磋叮鐝浜嗗涔呬簡锛?,
        'purpose' => '鍒ゆ柇缁垂鎰忔効鍜屾姤鍚嶅懆鏈?,
        'response' => '鐪嬬湅瀹堕暱杩戞湡鏄惁缁垂锛屽悓鏃剁煡閬撳綋鏃朵粬鎶ュ悕鐨勮鍖呮槸鍗婂勾杩樻槸涓€骞达紝鎻愬墠鍒ゆ柇瀹堕暱鐨勬秷璐逛範鎯拰缁垂鎰忔効',
        'keywords' => ['缁垂', '璇惧寘鍛ㄦ湡', '娑堣垂涔犳儻', '闇€姹傛寲鎺?],
        'scenario' => 'needs'
    ],
    [
        'number' => 5,
        'question' => '閭ｅ綋鏃跺杩欎釜鍏磋叮鐝槸鎮ㄧ粰瀛╁瓙鎶ュ悕鐨勫悧锛?,
        'purpose' => '鍒ゆ柇鍐崇瓥浜?,
        'response' => '浜嗚В璋佹墠鏄渶缁堝喅绛栦汉锛屾柟渚垮悗缁皥鍗曟椂鎵惧鍏抽敭浜?,
        'keywords' => ['鍐崇瓥浜?, '鍐崇瓥閾?, '闇€姹傛寲鎺?],
        'scenario' => 'needs'
    ],
    [
        'number' => 6,
        'question' => '骞虫椂涓婂叴瓒ｇ彮鏄濡堟偍甯﹀ス鍘昏繕鏄埜鐖稿憿锛?,
        'purpose' => '鍒ゆ柇鎺ラ€佷汉',
        'response' => '浜嗚В璋佹槸涓昏鎺ラ€佷汉锛屽悗缁湇鍔℃椂闇€瑕佺淮鎶ゅソ涓庢帴閫佷汉鐨勫叧绯?,
        'keywords' => ['鎺ラ€佷汉', '鏈嶅姟瀵硅薄', '闇€姹傛寲鎺?],
        'scenario' => 'needs'
    ],
    [
        'number' => 7,
        'question' => '鎮ㄥ綋鏃剁粰瀛╁瓙涔板挶浠繖涓綋楠岃鍖呮槸涓轰粈涔堝憿锛?,
        'purpose' => '鍒ゆ柇浣撻獙鍔ㄦ満',
        'response' => '浜嗚В瀹堕暱涓轰粈涔堣瀛╁瓙浣撻獙杩愬姩璇撅紝鏄富鍔ㄦ兂鍋氳繕鏄鍔ㄥ畨鎺?,
        'keywords' => ['浣撻獙鍔ㄦ満', '闇€姹傛寲鎺?, '鎰忓悜鍒ゆ柇'],
        'scenario' => 'needs'
    ],
    [
        'number' => 8,
        'question' => '骞虫椂鍛ㄦ湯鏀惧亣鏈夊甫瀛╁瓙鍦ㄥ皬鍖烘ゼ涓嬪仛杩愬姩鍚楋紵',
        'purpose' => '鍒ゆ柇杩愬姩鐞嗗康',
        'response' => '浜嗚В瀹堕暱瀵硅繍鍔ㄧ殑閲嶈绋嬪害鍜屾棩甯歌繍鍔ㄤ範鎯?,
        'keywords' => ['杩愬姩鐞嗗康', '鏃ュ父涔犳儻', '闇€姹傛寲鎺?],
        'scenario' => 'needs'
    ],
    [
        'number' => 9,
        'question' => '涔嬪墠鏈夋病鏈変笂杩囪繍鍔ㄨ锛?,
        'purpose' => '鍒ゆ柇杩愬姩缁忓巻',
        'response' => '濡傛灉涓嶆槸绗竴娆′笂杩愬姩璇撅紝椤鹃棶瑕佸皢鑷韩鏈烘瀯鍜屽叾浠栬繍鍔ㄦ満鏋勭殑"宸紓鍖?鍜?浠峰€?浣撶幇鍑烘潵',
        'keywords' => ['杩愬姩缁忓巻', '宸紓鍖?, '绔炰簤', '闇€姹傛寲鎺?],
        'scenario' => 'needs'
    ],
    [
        'number' => 10,
        'question' => '骞虫椂鎮ㄦ槸鍛ㄤ腑鏈夌┖杩樻槸鍛ㄦ湯鏈夌┖鍛紵鎴戠粰鎮ㄧ湅鐪嬫牎鍖虹殑璇捐〃锛屾偍鐪嬬湅鍝釜鏃堕棿娈垫湁绌猴紵',
        'purpose' => '閿佸畾鏃堕棿锛岄瑷€鎴愪氦娉曞垯',
        'response' => '鎻愬墠閿佸畾瀹堕暱鏃堕棿锛屽啀杩涜鎶ヤ环锛屾槸"棰勮█鎴愪氦娉曞垯"鐨勪綋鐜?,
        'keywords' => ['鏃堕棿', '鎺掕', '棰勮█鎴愪氦', '閫煎崟'],
        'scenario' => 'close'
    ]
];

// 1. 瀵煎叆鍒拌瘽鏈煡璇嗗簱 - qa缁村害
echo "=== 瀵煎叆璇濇湳鐭ヨ瘑搴擄紙qa缁村害锛?==\n";
$dim_qa = 1;
$sort_order = 150;

foreach ($salesTenQuestions as $q) {
    $scene_code = 'ten-questions-q' . $q['number'];

    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$scene_code]);
    $existing = $stmt->fetch();

    $standard_script = "銆愰棶棰樸€憑$q['question']}\n\n銆愮洰鐨勩€憑$q['purpose']}\n\n銆愬弬鑰冨洖绛斻€憑$q['response']}";
    $keywords = json_encode($q['keywords']);

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            '閿€鍞崄闂甉' . $q['number'] . '-' . $q['scenario'],
            $keywords,
            $standard_script,
            '绗? . $q['number'] . '闂細' . $q['purpose'],
            $scene_code
        ]);
        echo "Updated (qa): 閿€鍞崄闂甉{$q['number']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_qa,
            $scene_code,
            '閿€鍞崄闂甉' . $q['number'] . '-' . $q['scenario'],
            $keywords,
            $standard_script,
            '绗? . $q['number'] . '闂細' . $q['purpose'],
            $sort_order++
        ]);
        echo "Inserted (qa): 閿€鍞崄闂甉{$q['number']}\n";
    }
}

// 2. 瀵煎叆鍒拌瘽鏈煡璇嗗簱 - knowledge缁村害锛堟瘡涓棶棰樼殑娣卞害瑙ｆ瀽锛?
echo "\n=== 瀵煎叆璇濇湳鐭ヨ瘑搴擄紙knowledge缁村害锛?==\n";
$dim_knowledge = 2;
$sort_order = 250;

$knowledgeItems = [
    [
        'scene_code' => 'ten-questions-warmup',
        'scene_name' => '閿€鍞崄闂?鐮村啺鐜妭瑕佺偣',
        'keywords' => ['鐮村啺', '寮€鍦?, '鍝佺墝浠嬬粛', '璺濈鍒ゆ柇'],
        'standard_script' => '銆愮牬鍐扮幆鑺備笁瑕佺礌銆? . "\n" .
                           '1. 涓诲姩鐑儏锛氱涓€鍗拌薄鍐冲畾鍚庣画鎴愪氦' . "\n" .
                           '2. 鏍囧噯寮€鍦猴細绠€鍗曡嚜鎴戜粙缁?鍝佺墝浠嬬粛' . "\n" .
                           '3. 浜嗚В淇℃伅锛氭潵婧愭笭閬撱€佷氦閫氭柟寮忋€佽窛绂昏繙杩? . "\n\n" .
                           '銆怮1-Q2 鐮村啺闂銆? . "\n" .
                           '- Q1浜嗚В璁ょ煡搴︼細娌″惉杩囩殑瑕佺畝鍗曚粙缁嶅搧鐗? . "\n" .
                           '- Q2鍒ゆ柇璺濈锛氫簡瑙ｅ鎴锋潵婧愭柟鍚戝拰鏈嶅姟鍗婂緞',
        'tips' => '鐮村啺鏄缓绔嬩俊浠荤殑鍏抽敭闃舵'
    ],
    [
        'scene_code' => 'ten-questions-needs',
        'scene_name' => '閿€鍞崄闂?闇€姹傛寲鎺樿鐐?,
        'keywords' => ['闇€姹傛寲鎺?, '娑堣垂鍔?, '鍐崇瓥浜?, '鏁欒偛鐞嗗康'],
        'standard_script' => '銆愰渶姹傛寲鎺樹簲姝ユ硶銆? . "\n" .
                           '1. 浜嗚В鏁欒偛鐞嗗康锛氫笂杩囧摢浜涘叴瓒ｇ彮锛圦3锛? . "\n" .
                           '2. 鍒ゆ柇娑堣垂鍔涳細閫氳繃鍏磋叮鐝被鍨嬪垽鏂紙鐢荤敾閽㈢惔=楂樻秷璐癸級' . "\n" .
                           '3. 鍒ゆ柇缁垂鎰忔効锛氫箣鍓嶈绋嬪澶氫箙浜嗭紙Q4锛? . "\n" .
                           '4. 鎵惧噯鍐崇瓥浜猴細璋佺粰瀛╁瓙鎶ュ悕鐨勶紙Q5锛? . "\n" .
                           '5. 纭畾鎺ラ€佷汉锛氳皝鏉ュ甫瀛╁瓙涓婅锛圦6锛? . "\n\n" .
                           '銆愬叧閿俊鍙枫€? . "\n" .
                           '- 鍏磋叮鐝=鏁欒偛閲嶈' . "\n" .
                           '- 閽㈢惔鐢荤敾=娑堣垂鍔涘己' . "\n" .
                           '- 杩戞湡缁垂=涔犳儻鑹ソ',
        'tips' => '闇€姹傛寲鎺樺喅瀹氭垚浜よ川閲?
    ],
    [
        'scene_code' => 'ten-questions-motivation',
        'scene_name' => '閿€鍞崄闂?浣撻獙鍔ㄦ満鍒ゆ柇',
        'keywords' => ['浣撻獙鍔ㄦ満', '鎰忓悜鍒ゆ柇', '涓诲姩vs琚姩'],
        'standard_script' => '銆愪綋楠屽姩鏈哄垎鏋愩€? . "\n" .
                           'Q7: 涓轰粈涔堜拱浣撻獙璇撅紵' . "\n" .
                           '- 涓诲姩鎯充簡瑙?楂樻剰鍚? . "\n" .
                           '- 鏈嬪弸鎺ㄨ崘=涓瓑鎰忓悜' . "\n" .
                           '- 琚姩瀹夋帓=浣庢剰鍚? . "\n\n" .
                           'Q8: 骞虫椂鏈夊甫瀛╁瓙杩愬姩鍚楋紵' . "\n" .
                           '- 缁忓父杩愬姩=杩愬姩鐞嗗康濂? . "\n" .
                           '- 鍋跺皵杩愬姩=闇€瑕佹暀鑲? . "\n" .
                           '- 涓嶈繍鍔?闇€瑕佸煿鍏? . "\n\n" .
                           'Q9: 涓婅繃杩愬姩璇惧悧锛? . "\n" .
                           '- 娌′笂杩?鏁欒偛鎴愭湰楂? . "\n" .
                           '- 涓婅繃鍏朵粬=瑕佺獊鍑哄樊寮傚寲',
        'tips' => '鏍规嵁鍔ㄦ満璋冩暣璋堝崟绛栫暐'
    ],
    [
        'scene_code' => 'ten-questions-close',
        'scene_name' => '閿€鍞崄闂?棰勮█鎴愪氦娉曞垯',
        'keywords' => ['棰勮█鎴愪氦', '鏃堕棿閿佸畾', '閫煎崟', '绛惧崟'],
        'standard_script' => '銆愰瑷€鎴愪氦娉曞垯銆? . "\n" .
                           'Q10: 骞虫椂鎮ㄦ槸鍛ㄤ腑鏈夌┖杩樻槸鍛ㄦ湯鏈夌┖鍛紵' . "\n" .
                           '鈫?鎴戠粰鎮ㄧ湅鐪嬫牎鍖虹殑璇捐〃' . "\n" .
                           '鈫?鎮ㄧ湅鐪嬪摢涓椂闂存鏈夌┖锛? . "\n\n" .
                           '銆愪负浠€涔堣鍏堥攣鏃堕棿銆? . "\n" .
                           '1. 瀹堕暱鏃堕棿宸插畾=鎰忓悜搴﹂珮' . "\n" .
                           '2. 閿佸畾鏃堕棿鍚庡啀鎶ヤ环=鍑忓皯浠锋牸鎶楁嫆' . "\n" .
                           '3. 鎻愬墠绾﹁=闄嶄綆娴佸け鐜? . "\n\n" .
                           '銆愪娇鐢ㄦ椂鏈恒€? . "\n" .
                           '鍦ㄦ姤浠蜂箣鍓嶄娇鐢紝鍏堣瀹堕暱鎰熷彈鍒版湇鍔★紝鍐嶈皥浠锋牸',
        'tips' => '鍏堟湇鍔″悗鎴愪氦锛岄檷浣庝环鏍兼姉鎷?
    ]
];

foreach ($knowledgeItems as $item) {
    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$item['scene_code']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            $item['scene_name'],
            json_encode($item['keywords']),
            $item['standard_script'],
            $item['tips'],
            $item['scene_code']
        ]);
        echo "Updated (knowledge): {$item['scene_name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_knowledge,
            $item['scene_code'],
            $item['scene_name'],
            json_encode($item['keywords']),
            $item['standard_script'],
            $item['tips'],
            $sort_order++
        ]);
        echo "Inserted (knowledge): {$item['scene_name']}\n";
    }
}

// 3. 瀵煎叆鍒拌瘽鏈煡璇嗗簱 - deal缁村害锛堣皥鍗曟妧宸э級
echo "\n=== 瀵煎叆璇濇湳鐭ヨ瘑搴擄紙deal缁村害锛?==\n";
$dim_deal = 4;
$sort_order = 150;

$dealItems = [
    [
        'scene_code' => 'ten-questions-deal-warmup',
        'scene_name' => '閿€鍞崄闂皥鍗曟妧宸?鐮村啺闃舵',
        'keywords' => ['鐮村啺璋堝崟', '寮€鍦?, '寤虹珛淇′换', '绗竴鍗拌薄'],
        'standard_script' => '銆愮牬鍐伴樁娈佃皥鍗曡鐐广€? . "\n" .
                           '1. 绗竴鍗拌薄锛氱潃瑁呬笓涓氥€佺儹鎯呯湡璇? . "\n" .
                           '2. 璁╁瀛愬枩娆綘锛氬厛璺熷瀛愮帺璧锋潵' . "\n" .
                           '3. 鐢ㄩ棶棰樺紑鍦猴細寮€鏀惧紡闂浜嗚В淇℃伅' . "\n" .
                           '4. 涓嶈鎬ヤ簬閿€鍞細鍏堝缓绔嬪叧绯? . "\n" .
                           '5. 瑙傚療瀹堕暱鍙嶅簲锛氬垽鏂剰鍚戠▼搴?,
        'tips' => '鐮村啺鍐冲畾瀹堕暱鎰夸笉鎰挎剰缁х画鍚綘璁?
    ],
    [
        'scene_code' => 'ten-questions-deal-needs',
        'scene_name' => '閿€鍞崄闂皥鍗曟妧宸?闇€姹傛寲鎺橀樁娈?,
        'keywords' => ['闇€姹傛寲鎺樿皥鍗?, '娑堣垂鍔涘垽鏂?, '鍐崇瓥浜哄垎鏋?, '鎰忓悜鍒ゆ柇'],
        'standard_script' => '銆愰渶姹傛寲鎺樿皥鍗曟牳蹇冭鐐广€? . "\n" .
                           '1. 閫氳繃鍏磋叮鐝垽鏂秷璐瑰姏' . "\n" .
                           '   - 閽㈢惔/鐢荤敾/椹湳 = 楂樻秷璐瑰姏' . "\n" .
                           '   - 琛楄垶/璺嗘嫵閬?= 涓瓑娑堣垂鍔? . "\n" .
                           '   - 娌′笂杩?= 闇€鏁欒偛鍩瑰吇' . "\n\n" .
                           '2. 閫氳繃缁垂鍒ゆ柇涔犳儻' . "\n" .
                           '   - 缁垂杩?= 涔犳儻鑹ソ' . "\n" .
                           '   - 娌＄画璐?= 闇€寮鸿皟鏁堟灉' . "\n\n" .
                           '3. 鎵惧噯鍐崇瓥浜? . "\n" .
                           '   - 濡堝涓诲 = 閲嶇偣璇存湇濡堝' . "\n" .
                           '   - 鐖哥埜涓诲 = 鐖哥埜閫昏緫寮猴紝閲嶆暟鎹? . "\n" .
                           '   - 涓€璧峰喅瀹?= 闇€瑕佸悓鏃剁淮鎶?,
        'tips' => '闇€姹傛寲鎺樿秺娣憋紝鎴愪氦瓒婂鏄?
    ],
    [
        'scene_code' => 'ten-questions-deal-close',
        'scene_name' => '閿€鍞崄闂皥鍗曟妧宸?棰勮█鎴愪氦搴旂敤',
        'keywords' => ['棰勮█鎴愪氦', '鏃堕棿閿佸畾', '閫煎崟鎶€宸?, '绛惧崟鏃舵満'],
        'standard_script' => '銆愰瑷€鎴愪氦娉曞垯璇﹁В銆? . "\n" .
                           '鏍稿績璇濇湳锛? . "\n" .
                           '"骞虫椂鎮ㄦ槸鍛ㄤ腑鏈夌┖杩樻槸鍛ㄦ湯鏈夌┖鍛紵"' . "\n" .
                           '"鎴戠粰鎮ㄧ湅鐪嬫牎鍖虹殑璇捐〃"' . "\n" .
                           '"鎮ㄧ湅鐪嬪摢涓椂闂存鏈夌┖锛? ' . "\n\n" .
                           '銆愬簲鐢ㄦ椂鏈恒€? . "\n" .
                           '1. 鍦ㄦ姤浠蜂箣鍓嶇敤' . "\n" .
                           '2. 瀹堕暱琛ㄧず鏈夊叴瓒ｆ椂鐢? . "\n" .
                           '3. 澶勭悊瀹屼环鏍煎紓璁悗鐢? . "\n\n" .
                           '銆愭晥鏋溿€? . "\n" .
                           '1. 閿佸畾鏃堕棿=鎰忓悜纭' . "\n" .
                           '2. 鍏堢害璇惧悗鎶ヤ环=闄嶄綆鎶楁嫆' . "\n" .
                           '3. 绾﹀畾鏃堕棿=鎵胯鎴愪氦',
        'tips' => '鐢ㄥソ棰勮█鎴愪氦锛屾垚鍗曠巼鎻愬崌50%'
    ]
];

foreach ($dealItems as $item) {
    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$item['scene_code']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            $item['scene_name'],
            json_encode($item['keywords']),
            $item['standard_script'],
            $item['tips'],
            $item['scene_code']
        ]);
        echo "Updated (deal): {$item['scene_name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_deal,
            $item['scene_code'],
            $item['scene_name'],
            json_encode($item['keywords']),
            $item['standard_script'],
            $item['tips'],
            $sort_order++
        ]);
        echo "Inserted (deal): {$item['scene_name']}\n";
    }
}

// 4. 瀵煎叆鍒板煿璁崱鐗?
echo "\n=== 瀵煎叆鍩硅鍗＄墖 ===\n";

$trainingCards = [
    // 铻嶅叆棣栨鍒板簵鎺ュ緟妯″潡(mod-reception=2) - 鐮村啺鐩稿叧
    [
        'module_id' => 2,
        'card_type' => 'K',
        'card_code' => 'ten-questions-overview',
        'title' => '閿€鍞熀纭€鍗侀棶姒傝堪',
        'content' => '銆愰攢鍞熀纭€鍗侀棶銆戠敤浜庢柊绛炬祦绋嬬殑鐮村啺鍜岄渶姹傛寲鎺橈細' . "\n" .
                     'Q1-Q2 鐮村啺鐜妭锛氫簡瑙ｈ鐭ュ害銆佸垽鏂窛绂? . "\n" .
                     'Q3-Q6 闇€姹傛寲鎺橈細浜嗚В鏁欒偛鐞嗗康銆佹秷璐瑰姏銆佸喅绛栦汉' . "\n" .
                     'Q7-Q9 鍔ㄦ満鍒嗘瀽锛氫綋楠屽姩鏈恒€佽繍鍔ㄧ悊蹇点€佽繍鍔ㄧ粡鍘? . "\n" .
                     'Q10 鎴愪氦閿佸畾锛氶瑷€鎴愪氦娉曞垯锛岄攣瀹氭椂闂?,
        'tips' => '鍗侀棶鏄柊绛惧紑鍙ｇ殑鍏抽敭'
    ],
    [
        'module_id' => 2,
        'card_type' => 'S',
        'card_code' => 'ten-questions-warmup-cards',
        'title' => '閿€鍞崄闂瘽鏈崱-鐮村啺鐜妭',
        'content' => '銆怮1-Q2 鐮村啺璇濇湳銆? . "\n\n" .
                     'Q1: "瀹堕暱涔嬪墠鏈変簡瑙ｈ繃鎴戜滑杩藉厜灏忕墰鍝佺墝鍚楋紵"' . "\n" .
                     '鈫?娌℃湁锛?鎴戠粰鎮ㄧ畝鍗曚粙缁嶄竴涓?' . "\n\n" .
                     'Q2: "鎮ㄤ粖澶╂槸鎬庝箞杩囨潵鐨勫憿锛?' . "\n" .
                     '鈫?鍒ゆ柇璺濈鍜屾湇鍔″崐寰?,
        'tips' => '寮€鍦鸿鐑儏鑷劧锛屼笉瑕佷竴涓婃潵灏遍攢鍞?
    ],
    [
        'module_id' => 2,
        'card_type' => 'D',
        'card_code' => 'ten-questions-needs-cards',
        'title' => '閿€鍞崄闂瘽鏈崱-闇€姹傛寲鎺?,
        'content' => '銆怮3-Q9 闇€姹傛寲鎺樿瘽鏈€? . "\n\n" .
                     'Q3: "涔嬪墠鏈変笂杩囧叴瓒ｇ彮鍚楋紵"' . "\n" .
                     '鈫?閽㈢惔/鐢荤敾=楂樻秷璐瑰姏' . "\n\n" .
                     'Q4: "瀛︿簡澶氫箙浜嗭紵"' . "\n" .
                     '鈫?鍒ゆ柇缁垂鎰忔効' . "\n\n" .
                     'Q5: "鏄偍缁欏瀛愭姤鍚嶇殑鍚楋紵"' . "\n" .
                     '鈫?鍒ゆ柇鍐崇瓥浜? . "\n\n" .
                     'Q6: "璋佸甫瀛╁瓙涓婅锛?' . "\n" .
                     '鈫?鍒ゆ柇鎺ラ€佷汉',
        'tips' => '閫氳繃鍏磋叮鐝被鍨嬪拰鏁伴噺鍒ゆ柇娑堣垂鍔?
    ],
    [
        'module_id' => 2,
        'card_type' => 'C',
        'card_code' => 'ten-questions-exam',
        'title' => '閿€鍞崄闂€氬叧鍗?,
        'content' => '銆愰攢鍞崄闂€氬叧鑰冩牳銆? . "\n" .
                     '1. 鑳藉畬鏁磋鍑洪攢鍞崄闂殑鍐呭' . "\n" .
                     '2. 鑳借鍑烘瘡闂殑鐩殑鍜屽垽鏂€昏緫' . "\n" .
                     '3. 鑳芥ā鎷熸紨缁僎1-Q2鐮村啺璇濇湳' . "\n" .
                     '4. 鑳介€氳繃鍏磋叮鐝被鍨嬪垽鏂秷璐瑰姏' . "\n" .
                     '5. 鑳借鍑洪瑷€鎴愪氦娉曞垯鐨勪娇鐢ㄦ椂鏈?,
        'tips' => '鑰冩牳閫氳繃鎵嶈兘杩涜瀹為檯璋堝崟'
    ],
    // 铻嶅叆瀹堕暱娌熼€氳瘽鏈ā鍧?mod-communication=5)
    [
        'module_id' => 5,
        'card_type' => 'K',
        'card_code' => 'ten-questions-consumer-analysis',
        'title' => '娑堣垂鍔涘垽鏂煡璇嗙偣',
        'content' => '銆愰€氳繃鍏磋叮鐝垽鏂秷璐瑰姏銆? . "\n\n" .
                     '楂樻秷璐瑰姏鐗瑰緛锛? . "\n" .
                     '- 閽㈢惔锛堣鍗曚环楂橈紝闇€闀挎湡鎶曞叆锛? . "\n" .
                     '- 鐢荤敾/缇庢湳锛堝缇庡煿鍏伙紝楂樻姇鍏ワ級' . "\n" .
                     '- 椹湳/楂樺皵澶紙楂樼杩愬姩锛? . "\n" .
                     '- 澶栬/鍏ㄨ剳寮€鍙? . "\n\n" .
                     '涓瓑娑堣垂鍔涚壒寰侊細' . "\n" .
                     '- 琛楄垶/璺嗘嫵閬? . "\n" .
                     '- 娓告吵/缇芥瘺鐞? . "\n\n" .
                     '闇€鏁欒偛鍩瑰吇锛? . "\n" .
                     '- 娌′笂杩囦换浣曞叴瓒ｇ彮',
        'tips' => '娑堣垂鍔涘垽鏂槸闇€姹傛寲鎺樼殑鏍稿績'
    ],
    [
        'module_id' => 5,
        'card_type' => 'S',
        'card_code' => 'ten-questions-close-script',
        'title' => '棰勮█鎴愪氦璇濇湳鍗?,
        'content' => '銆怮10棰勮█鎴愪氦璇濇湳銆? . "\n\n" .
                     '鏍囧噯璇濇湳锛? . "\n" .
                     '"骞虫椂鎮ㄦ槸鍛ㄤ腑鏈夌┖杩樻槸鍛ㄦ湯鏈夌┖鍛紵"' . "\n" .
                     '"鎴戠粰鎮ㄧ湅鐪嬫牎鍖虹殑璇捐〃"' . "\n" .
                     '"鎮ㄧ湅鐪嬪摢涓椂闂存鏈夌┖锛? ' . "\n\n" .
                     '浣跨敤鏃舵満锛氭姤浠蜂箣鍓? . "\n" .
                     '鐩殑锛氶攣瀹氭椂闂村悗鍐嶆姤浠凤紝鍑忓皯浠锋牸鎶楁嫆',
        'tips' => '鍏堥攣鏃堕棿鍚庢姤浠凤紝杩欐槸绛惧崟鐨勫叧閿竴姝?
    ]
];

foreach ($trainingCards as $card) {
    $stmt = $db->prepare("SELECT id FROM training_cards WHERE card_code = ?");
    $stmt->execute([$card['card_code']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateSql = "UPDATE training_cards SET module_id = ?, card_type = ?, title = ?, content = ?, tips = ? WHERE card_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            $card['module_id'],
            $card['card_type'],
            $card['title'],
            $card['content'],
            $card['tips'],
            $card['card_code']
        ]);
        echo "Updated (card): {$card['title']}\n";
    } else {
        $insertSql = "INSERT INTO training_cards (module_id, card_type, card_code, title, content, tips, status)
                       VALUES (?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $card['module_id'],
            $card['card_type'],
            $card['card_code'],
            $card['title'],
            $card['content'],
            $card['tips']
        ]);
        echo "Inserted (card): {$card['title']}\n";
    }
}

echo "\n=== 瀵煎叆瀹屾垚 ===\n";

// 缁熻鍚勭淮搴︽暟鎹?
echo "\n=== 褰撳墠鏁版嵁缁熻 ===\n";
$stmt = $db->query("SELECT dimension_id, COUNT(*) as count FROM script_knowledge GROUP BY dimension_id ORDER BY dimension_id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $dimNames = ['', '闂瓟璇濇湳', '涓撲笟鐭ヨ瘑鐐?, '璇惧悗鐐硅瘎', '鐙珛璋堝崟'];
    echo "{$dimNames[$row['dimension_id']]}: {$row['count']}鏉n";
}

$stmt = $db->query("SELECT module_id, COUNT(*) as count FROM training_cards GROUP BY module_id ORDER BY module_id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $moduleNames = [1=>'鍝佺墝', 2=>'鎺ュ緟', 3=>'璇勪及', 4=>'浣撻獙', 5=>'娌熼€?, 6=>'缁垂', 7=>'绠＄悊'];
    echo "妯″潡{$row['module_id']}({$moduleNames[$row['module_id']]}): {$row['count']}寮犲崱鐗嘰n";
}
