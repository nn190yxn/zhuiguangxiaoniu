<?php
/**
 * 璁粌妯″潡鍜屽崱鐗囨暟鎹垵濮嬪寲
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
    die("Database connection failed: " . $e->getMessage());
}

echo "Starting data initialization...\n";

// Insert roles
$roles = [
    ['consultant', '璇剧▼椤鹃棶', '璐熻矗瀹㈡埛鎺ュ緟銆侀攢鍞浆鍖栥€佺画璐圭淮鎶?],
    ['coach', '鏁欑粌', '璐熻矗璇惧爞鏁欏銆佸鍛樿瘎浼般€佸弽棣堟矡閫?],
    ['manager', '搴楅暱', '璐熻矗闂ㄥ簵杩愯惀銆佸洟闃熺鐞嗐€佷笟缁╄揪鎴?],
    ['newbie', '鏂板憳宸?, '鍏ㄩ潰瀛︿範锛屾墍鏈夋ā鍧楅兘闇€瑕侀€氬叧'],
];

foreach ($roles as $role) {
    $stmt = $db->prepare("INSERT IGNORE INTO user_roles (role_code, role_name, description, sort_order) VALUES (?, ?, ?, ?)");
    $stmt->execute([$role[0], $role[1], $role[2], array_search($role[0], array_column($roles, 0))]);
}
echo "Roles inserted.\n";

// Insert training modules
$modules = [
    ['mod-brand', '鍝佺墝涓庝骇鍝佽鐭?, '浜嗚В杩藉厜灏忕墰鍝佺墝鐞嗗康銆佽绋嬩綋绯汇€佷骇鍝佷紭鍔?, 'newbie', '鍩虹', 'beginner', 10],
    ['mod-reception', '棣栨鍒板簵鎺ュ緟', '鎺屾彙鎺ュ緟娴佺▼銆佹湇鍔℃爣鍑嗐€佺牬鍐版妧宸?, 'consultant', '閿€鍞熀纭€', 'beginner', 15],
    ['mod-assessment', '浣撴祴璇勪及鎶€鑳?, '鎺屾彙鍎跨浣撹兘璇勪及鏂规硶銆丄CE璇勪及浣撶郴', 'coach', '涓撲笟鎶€鑳?, 'beginner', 12],
    ['mod-trial', '浣撻獙璇捐浆鍖?, '浣撻獙璇炬祦绋嬬鐞嗐€佽绋嬫帹鑽愩€佸紓璁鐞?, 'consultant', '閿€鍞繘闃?, 'intermediate', 15],
    ['mod-communication', '瀹堕暱娌熼€氳瘽鏈?, '鍚勭被鍦烘櫙娌熼€氳瘽鏈€佸弽棣堣〃杈俱€佹姇璇夊鐞?, 'coach', '鏈嶅姟鎶€鑳?, 'intermediate', 15],
    ['mod-renewal', '缁垂涓庤浆浠嬬粛', '缁垂鏃舵満鎶婃彙銆佽瘽鏈妧宸с€佽浆浠嬬粛婵€鍔?, 'consultant', '閿€鍞繘闃?, 'intermediate', 12],
    ['mod-management', '闂ㄥ簵鏃ュ父绠＄悊', '搴楅暱鑱岃矗銆佹暟鎹鐞嗐€佸洟闃熷甫棰?, 'manager', '绠＄悊鎶€鑳?, 'advanced', 15],
];

$sortOrder = 0;
foreach ($modules as $module) {
    $stmt = $db->prepare("INSERT IGNORE INTO training_modules (module_code, module_name, description, role_code, category, level, total_cards, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(array_merge($module, [$sortOrder++]));
}
echo "Modules inserted.\n";

// Get module IDs
$moduleIds = [];
$stmt = $db->query("SELECT id, module_code FROM training_modules");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $moduleIds[$row['module_code']] = $row['id'];
}

// Insert training cards for each module
$cards = [
    // mod-brand: 鍝佺墝涓庝骇鍝佽鐭?
    ['mod-brand', 'K', 'card-K-001', '杩藉厜灏忕墰鍝佺墝鏁呬簨', "杩藉厜灏忕墰鏄竴瀹朵笓娉ㄤ簬3-12宀佸効绔ヤ綋鑳藉煿璁殑杩為攣鏈烘瀯銆傛垜浠殑浣垮懡鏄?鐢ㄧ瀛﹁繍鍔ㄥ府鍔╁瀛愬仴搴锋垚闀?銆傚搧鐗屽悕绉板瘬鎰忥細杩藉厜 - 杩介€愬厜鏄庢湭鏉ワ紝鐗?- 鎴愬氨姣忎竴涓皬灏忚繍鍔ㄥ憳銆?, '杩藉厜璞″緛绉瀬鍚戜笂鐨勪汉鐢熻锛岀墰浠ｈ〃鍧氶煣鍜屾垚灏便€?],
    ['mod-brand', 'K', 'card-K-002', '鎴戜滑鐨勮绋嬩綋绯?, "杩藉厜灏忕墰璇剧▼浣撶郴鍒嗕负鍥涘ぇ鏉垮潡锛歕n1. 鎰熺粺璁粌锛?-6宀侊級- 淇冭繘鎰熻缁熷悎鑳藉姏鍙戝睍\n2. 浣撹兘璁粌锛?-9宀侊級- 鎻愬崌鍩虹杩愬姩绱犺川\n3. 鎶€鑳借缁冿紙9-12宀侊級- 瀛︿範涓撻」杩愬姩鎶€鑳絓n4. 浣撴祴杈炬爣锛堝悇骞撮緞娈碉級- 閽堝瀛︽牎浣撴祴杩涜涓撻」璁粌", '鏍规嵁瀛╁瓙骞撮緞閫夋嫨鍚堥€傝绋嬫澘鍧?],
    ['mod-brand', 'S', 'card-S-001', '浠嬬粛鍝佺墝鐞嗗康', "瀹堕暱鎮ㄥソ锛佽拷鍏夊皬鐗涙槸涓€瀹朵笓涓氬仛鍎跨浣撹兘鍩硅鐨勬満鏋勩€傛垜浠笉鏄偅绉嶇患鍚堟棭鏁欙紝鑰屾槸涓撴敞鍋氫綋鑳藉拰鎰熺粺璁粌銆傚緢澶氬闀块€夋嫨鎴戜滑锛屾槸鍥犱负鎴戜滑鏈変笁鐢插尰闄㈣繍鍔ㄥ悍澶嶇鑳屾櫙鐨勬暀缁冨洟闃燂紝杩樻湁鑷富鐮斿彂鐨勮绋嬩綋绯汇€?, '寮鸿皟涓撲笟鎬у拰宸紓鍖栦紭鍔?],
    ['mod-brand', 'S', 'card-S-002', '瑙ｇ瓟浠锋牸鐤戦棶', "鎴戜滑鐨勮绋嬫槸鎸夎鏃跺寘鏉ユ敹璐圭殑锛屽钩鍧囨瘡鑺傝鍦?50-200鍏冧箣闂淬€傚姣斿競鍦轰笂鍚岀被涓撲笟鏈烘瀯锛屾垜浠殑鎬т环姣斿緢楂樸€傚洜涓烘垜浠殑鏁欑粌閮芥湁涓撲笟璧勮川锛岃绋嬮兘鏄嚜涓荤爺鍙戠殑锛岃€屼笖姣忎釜瀛╁瓙閮芥湁鐙珛鐨勮缁冩。妗堛€?, '鍏堣浠峰€硷紝鍐嶆彁浠锋牸'],
    ['mod-brand', 'C', 'card-C-001', '鍝佺墝鐭ヨ瘑妫€鏌?, "璇锋鏌ヤ互涓嬬煡璇嗙偣鏄惁鎺屾彙锛歕n1. 杩藉厜灏忕墰涓撴敞鍝釜骞撮緞娈碉紵\n2. 涓夊ぇ璇剧▼鏉垮潡鏄粈涔堬紵\n3. 鍝佺墝浣垮懡鏄粈涔堬紵\n4. 鐩告瘮鏃╂暀鏈烘瀯鐨勫樊寮傜偣锛?, '鍏ㄩ儴鎺屾彙鎵嶈兘閫氳繃'],
    // mod-reception: 棣栨鍒板簵鎺ュ緟
    ['mod-reception', 'K', 'card-K-101', '鎺ュ緟娴佺▼8姝ユ硶', "棣栨鍒板簵鎺ュ緟8姝ユ硶锛歕n1. 棰勭害纭锛堥绾﹀悗2h鍐咃級\n2. 寤虹珛鏈嶅姟缇わ紙棰勭害鍚?h鍐咃級\n3. 鍒拌鍓?澶╃‘璁n4. 鍒板簵杩庢帴+绛惧埌\n5. 鐜浠嬬粛+闇€姹傛矡閫氾紙5min鍐咃級\n6. 浣撻獙璇撅紙45-60min锛塡n7. 璇惧悗鍙嶉+鏂规鎺ㄨ崘锛?0min鍐咃級\n8. 褰撴棩鎴愪氦浜ゆ帴/鏈垚浜ゅ垎娴?, '8姝ユ硶蹇呴』鍏ㄩ儴鎺屾彙'],
    ['mod-reception', 'S', 'card-S-101', '鐮村啺璇濇湳', "瀹濆疂浠婂ぉ琛ㄧ幇寰堟鍛€锛佸垰鎵嶆垜鍦ㄦ梺杈硅瀵燂紝浠栧湪骞宠　鏈ㄤ笂璧板緱瓒婃潵瓒婄ǔ浜嗭紝涓€鐪嬪氨鏄埍杩愬姩鐨勫皬鏈嬪弸銆傝闂疂瀹濆钩鏃跺枩娆㈢帺浠€涔堟父鎴忓憖锛?, '鍏堝じ瀛╁瓙锛屽啀闂闀匡紝鎷夎繎璺濈'],
    ['mod-reception', 'S', 'card-S-102', '闇€姹傛寲鎺樿瘽鏈?, "鎮ㄤ粖澶╂渶鎯抽€氳繃浣撻獙璇句簡瑙ｅ瀛愬摢鏂归潰鐨勬儏鍐靛憿锛熸槸鎯崇湅鐪嬪瀛愮殑杩愬姩鍩虹锛岃繕鏄兂浜嗚В浠栬窡鍚岄緞瀛╁瓙姣旀湁浠€涔堜紭鍔挎垨闇€瑕佸姞寮虹殑鍦版柟锛?, '浜嗚В瀹堕暱鐪熷疄鐩殑'],
    ['mod-reception', 'D', 'card-D-101', '鎺ュ緟婕旂粌鍦烘櫙', "鍦烘櫙锛氫竴浣?宀佸コ瀛╃涓€娆″埌搴楋紝鏈変簺瀹崇緸韬插湪濡堝韬悗銆俓n璇锋ā鎷熷畬鏁寸殑鎺ュ緟鐮村啺杩囩▼锛屽寘鎷細\n1. 濡備綍绉板懠瀛╁瓙\n2. 濡備綍娑堥櫎瀛╁瓙闄岀敓鎰焅n3. 濡備綍涓庡闀垮紑濮嬫矡閫?, '婕旂粌鍚嶢I璇勫垎'],
    ['mod-reception', 'C', 'card-C-101', '鎺ュ緟鏈嶅姟閫氬叧椤?, "璇锋鏌ヤ互涓嬫湇鍔℃槸鍚﹀仛鍒帮細\n1. 涓诲姩鍙嚭瀛╁瓙鍚嶅瓧\n2. 浠嬬粛闂ㄥ簵鐜锛堟礂鎵嬮棿銆佷紤鎭尯锛塡n3. 缁欏瀛愪僵鎴翠綋楠屽悕鐗孿n4. 椤鹃棶鍏ㄧ▼闄悓瀹堕暱\n5. 鏁欑粌璇惧悗3鍒嗛挓鍐呯粰鍑哄彛澶村弽棣?, '鍏ㄩ儴鍋氬埌鎵嶈兘閫氳繃'],
    // mod-assessment: 浣撴祴璇勪及
    ['mod-assessment', 'K', 'card-K-201', 'ACE璇勪及浣撶郴', "ACE鍎跨浣撹兘璇勪及浣撶郴鍖呭惈浜斿ぇ缁村害锛歕n1. 韬綋绱犺川锛堝姏閲忋€侀€熷害銆佽€愬姏锛塡n2. 鎰熺粺鑳藉姏锛堝墠搴銆佹湰浣撹銆佽Е瑙夛級\n3. 鍗忚皟鎬э紙骞宠　鎰熴€佽妭濂忔劅锛塡n4. 涓撴敞鍔涳紙娉ㄦ剰鍔涖€佸弽搴旈€熷害锛塡n5. 杩愬姩鎶€鑳斤紙璺戣烦鎶曟幏绛夛級", 'ACE浣撶郴鏄牳蹇冭瘎浼板伐鍏?],
    ['mod-assessment', 'K', 'card-K-202', '鍚勫勾榫勬浣撴祴閲嶇偣', "3-4宀侊細閲嶇偣璇勪及鎰熺粺鍩虹銆佸钩琛¤兘鍔沑n5-6宀侊細鍔犲叆鍗忚皟鎬с€佷笓娉ㄥ姏璇勪及\n7-9宀侊細寮€濮嬭瘎浼颁綋鑳界礌璐ㄥ拰杩愬姩鎶€鑳絓n10-12宀侊細閽堝浣撴祴杈炬爣椤圭洰涓撻」璇勪及", '涓嶅悓骞撮緞璇勪及閲嶇偣涓嶅悓'],
    ['mod-assessment', 'S', 'card-S-201', '浣撴祴鍙嶉璇濇湳', "XX濡堝锛屼粖澶╁疂瀹濆湪浣撴祴涓〃鐜伴潪甯稿ソ锛佷粬鍦ㄥ钩琛℃湪涓婅蛋浜?5姝ユ墠鎺変笅鏉ワ紝杩欎釜骞撮緞娈佃兘杈惧埌杩欎釜姘村钩寰堜笉閿欍€傜◢寰渶瑕佸姞寮虹殑鏄粬鐨勪笓娉ㄥ姏锛屽湪鎸佺画娉ㄦ剰鍔涙柟闈㈣繕鏈夋彁鍗囩┖闂淬€備笉杩囧埆鎷呭績锛岃繖浜涢兘鏄彲浠ラ€氳繃璁粌鏉ユ敼鍠勭殑銆?, '鍏堣浜偣锛屽啀鎻愬缓璁?],
    ['mod-assessment', 'D', 'card-D-201', '浣撴祴璇勪及婕旂粌', "鍦烘櫙锛?宀佺敺瀛╋紝浣撴祴鏄剧ず鍗忚皟鎬у亸寮憋紝璺宠穬鑳藉姏杈炬爣銆俓n璇峰悜瀹堕暱鍙嶉浣撴祴缁撴灉锛屽苟鎺ㄨ崘鍚堥€傜殑璇剧▼銆?, 'AI璇勪及娌熼€氭晥鏋?],
    ['mod-assessment', 'C', 'card-C-201', '浣撴祴鎿嶄綔閫氬叧椤?, "璇风‘璁や互涓嬫搷浣滄槸鍚﹁鑼冿細\n1. 娴嬭瘯鍓嶆槸鍚﹁闂瀛愯韩浣撶姸鍐礬n2. 鏄惁鎸夋爣鍑嗘祦绋嬭繘琛屾祴璇昞n3. 鏄惁鏈夎褰曟祴璇曟暟鎹甛n4. 鏄惁褰撳満缁欏嚭鍒濇璇勪及", '瑙勮寖鎿嶄綔纭繚鍑嗙‘鎬?],
];

// Insert cards
$stmt = $db->prepare("INSERT IGNORE INTO training_cards (module_id, card_type, card_code, title, content, tips, difficulty, score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($cards as $card) {
    if (isset($moduleIds[$card[0]])) {
        $stmt->execute([
            $moduleIds[$card[0]],
            $card[1],
            $card[2],
            $card[3],
            $card[4],
            $card[5] ?? null,
            $card[1] === 'K' ? 'easy' : ($card[1] === 'D' ? 'hard' : 'medium'),
            100
        ]);
    }
}
echo "Cards inserted.\n";

// Update module card counts
$updateStmt = $db->prepare("
    UPDATE training_modules tm
    SET tm.total_cards = (
        SELECT COUNT(*) FROM training_cards WHERE module_id = tm.id
    )
");
$updateStmt->execute();
echo "Module card counts updated.\n";

echo "Data initialization completed!\n";
