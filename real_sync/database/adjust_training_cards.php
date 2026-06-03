<?php
/**
 * 鍩硅鍗＄墖妯″潡璋冩暣鑴氭湰
 * 鐩爣锛氳涓冩鏇插拰鍗侀棶姝ｇ‘鍒嗗竷鍒板垵闃?杩涢樁锛岄攢鍞?鏁欑粌
 *
 * 姝ｇ‘鍒嗗竷閫昏緫锛?
 * 妯″潡1(鍝佺墝): 涓冩鏇叉杩?鍏ㄥ眬姒傝)
 * 妯″潡2(鎺ュ緟-閿€鍞垵闃?: 鍗侀棶(鍏?+涓冩鏇茬牬鍐?涓冩鏇查渶姹?娑堣垂鍔涘垽鏂?
 * 妯″潡4(浣撻獙璇?閿€鍞繘闃?: 涓冩鏇插紓璁?涓冩鏇查€煎崟+棰勮█鎴愪氦
 * 妯″潡5(娌熼€?鏁欑粌): 淇濈暀瀹堕暱娌熼€氬唴瀹?
 * 妯″潡6(缁垂-閿€鍞繘闃?: 涓冩鏇茬画璐?涓冩鏇茶浆浠嬬粛+涓冩鏇查€氬叧
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

echo "=== 鍩硅鍗＄墖妯″潡璋冩暣鑴氭湰 ===\n\n";

echo "銆愯皟鏁村墠鐨勫垎甯冦€慭n";
$stmt = $db->query("
    SELECT tc.id, tc.module_id, tc.card_code, tc.title,
           tm.module_name, tm.role_code, tm.level
    FROM training_cards tc
    JOIN training_modules tm ON tc.module_id = tm.id
    WHERE tc.card_code LIKE '%seven%' OR tc.card_code LIKE '%ten%'
       OR tc.card_code LIKE '%閿€鍞?' OR tc.title LIKE '%涓冩%'
       OR tc.title LIKE '%鍗侀棶%' OR tc.title LIKE '%棰勮█%'
       OR tc.title LIKE '%娑堣垂鍔?'
    ORDER BY tc.module_id, tc.id
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- [妯″潡{$row['module_id']}]{$row['module_name']}: {$row['title']}\n";
}

echo "\n銆愬紑濮嬭皟鏁淬€慭n";

// 瀹氫箟璋冩暣鏄犲皠 card_code => 鏂癿odule_id
$adjustments = [
    // 妯″潡1: 涓冩鏇叉杩?鍏ㄥ眬姒傝锛屾墍鏈夐攢鍞浉鍏冲矖浣嶉兘瑕佸)
    'seven-steps-overview' => 1,

    // 妯″潡2(閿€鍞垵闃?鎺ュ緟): 鍗侀棶鍏ㄦ祦绋?+ 鐮村啺 + 闇€姹傛寲鎺?
    'ten-questions-overview' => 2,       // 鍗侀棶姒傝堪
    'ten-questions-warmup-cards' => 2,    // 鍗侀棶鐮村啺璇濇湳
    'ten-questions-needs-cards' => 2,     // 鍗侀棶闇€姹傛寲鎺?
    'ten-questions-exam' => 2,            // 鍗侀棶閫氬叧鍗?
    'ten-questions-consumer-analysis' => 2, // 娑堣垂鍔涘垽鏂?
    'ten-questions-close-script' => 2,    // 棰勮█鎴愪氦璇濇湳
    'seven-steps-warmup' => 2,            // 涓冩鏇?鏆栧満鐮村啺
    'seven-steps-needs' => 2,            // 涓冩鏇?闇€姹傛寲鎺?

    // 妯″潡4(閿€鍞繘闃?浣撻獙璇?: 寮傝澶勭悊 + 閫煎崟鎴愪氦
    'seven-steps-objection' => 4,        // 涓冩鏇?寮傝澶勭悊
    'seven-steps-close' => 4,            // 涓冩鏇?閫煎崟鎴愪氦

    // 妯″潡5(鏁欑粌杩涢樁-瀹堕暱娌熼€?: 淇濈暀鍘熸牱锛堝闀挎矡閫氱浉鍏筹級
    // 涓嶈皟鏁翠换浣曞崱鐗?

    // 妯″潡6(閿€鍞繘闃?缁垂杞粙缁?: 缁垂 + 杞粙缁?+ 鏁翠綋鑰冩牳
    'seven-steps-renewal' => 6,         // 涓冩鏇?杞暀缁垂
    'seven-steps-referral' => 6,         // 涓冩鏇?涓诲姩杞粙缁?
    'seven-steps-exam' => 6,             // 涓冩鏇?鏁翠綋娴佺▼鑰冩牳
];

foreach ($adjustments as $cardCode => $newModuleId) {
    // 鑾峰彇鍗＄墖褰撳墠淇℃伅
    $stmt = $db->prepare("SELECT id, module_id, title FROM training_cards WHERE card_code = ?");
    $stmt->execute([$cardCode]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($card) {
        $oldModuleId = $card['module_id'];
        if ($oldModuleId != $newModuleId) {
            // 鑾峰彇鏂版棫妯″潡鍚嶇О
            $stmtOld = $db->prepare("SELECT module_name FROM training_modules WHERE id = ?");
            $stmtOld->execute([$oldModuleId]);
            $oldModuleName = $stmtOld->fetchColumn();

            $stmtNew = $db->prepare("SELECT module_name FROM training_modules WHERE id = ?");
            $stmtNew->execute([$newModuleId]);
            $newModuleName = $stmtNew->fetchColumn();

            // 鎵ц鏇存柊
            $updateStmt = $db->prepare("UPDATE training_cards SET module_id = ? WHERE card_code = ?");
            $updateStmt->execute([$newModuleId, $cardCode]);

            echo "璋冩暣: {$card['title']}\n";
            echo "  {$oldModuleName}[{$oldModuleId}] 鈫?{$newModuleName}[{$newModuleId}]\n";
        } else {
            echo "淇濇寔: {$card['title']} (妯″潡{$newModuleId}涓嶅彉)\n";
        }
    } else {
        echo "鏈壘鍒? {$cardCode}\n";
    }
}

echo "\n銆愯皟鏁村悗鐨勫垎甯冦€慭n";
$stmt = $db->query("
    SELECT tc.id, tc.module_id, tc.card_code, tc.title, tc.card_type,
           tm.module_name, tm.role_code, tm.level
    FROM training_cards tc
    JOIN training_modules tm ON tc.module_id = tm.id
    WHERE tc.card_code LIKE '%seven%' OR tc.card_code LIKE '%ten%'
       OR tc.card_code LIKE '%閿€鍞?' OR tc.title LIKE '%涓冩%'
       OR tc.title LIKE '%鍗侀棶%' OR tc.title LIKE '%棰勮█%'
       OR tc.title LIKE '%娑堣垂鍔?'
    ORDER BY tc.module_id, tc.card_type, tc.id
");
$currentModule = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($currentModule != $row['module_id']) {
        $currentModule = $row['module_id'];
        $levelNames = ['beginner'=>'鍒濋樁', 'intermediate'=>'杩涢樁', 'advanced'=>'楂橀樁'];
        $roleNames = ['newbie'=>'鏂板憳宸?, 'consultant'=>'閿€鍞?, 'coach'=>'鏁欑粌', 'manager'=>'搴楅暱'];
        echo "\n>>> 妯″潡{$row['module_id']}: {$row['module_name']} ";
        echo "({$roleNames[$row['role_code']]}/{$levelNames[$row['level']]})\n";
    }
    echo "  [{$row['card_type']}] {$row['title']}\n";
}

// 缁熻鍚勬ā鍧楁渶缁堝崱鐗囨暟
echo "\n銆愬悇妯″潡鏈€缁堝崱鐗囨暟銆慭n";
$stmt = $db->query("
    SELECT tm.id, tm.module_name, tm.role_code, tm.level, COUNT(tc.id) as cnt
    FROM training_modules tm
    LEFT JOIN training_cards tc ON tm.id = tc.module_id AND tc.status = 1
    WHERE tm.status = 1
    GROUP BY tm.id
    ORDER BY tm.id
");
$levelNames = ['beginner'=>'鍒濋樁', 'intermediate'=>'杩涢樁', 'advanced'=>'楂橀樁'];
$roleNames = ['newbie'=>'鏂板憳宸?, 'consultant'=>'閿€鍞?, 'coach'=>'鏁欑粌', 'manager'=>'搴楅暱'];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "妯″潡{$row['id']}: {$row['module_name']} ";
    echo "({$roleNames[$row['role_code']]}/{$levelNames[$row['level']]}) - {$row['cnt']}寮燶n";
}

echo "\n=== 璋冩暣瀹屾垚 ===\n";
