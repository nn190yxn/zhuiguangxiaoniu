<?php
/**
 * 瀵煎叆閿€鍞瘽鏈疩&A鍒拌瘽鏈煡璇嗗簱
 * 鏁版嵁鏉ユ簮: 杩藉厜灏忕墰鍎跨杩愬姩甯歌鐤戦棶鍥炵瓟锛?023.3鏈堬級.pdf - 閿€鍞瘒
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

echo "Connected to database. Starting Q&A import...\n";

// Q&A entries for sales objections (dimension_id = 1)
$qa_entries = [
    [
        'scene_code' => 'child-not-interested',
        'scene_name' => '瀛╁瓙涓嶅枩娆紝绛夋湁鍏磋叮鍐嶆潵',
        'keywords' => json_encode(['涓嶅枩娆?, '娌″叴瓒?, '绛変竴绛?]),
        'standard_script' => '瀹堕暱鎮ㄥソ锛岄鍏堣繍鍔ㄥ浜庡瀛愭潵璇达紝涓嶆槸涓€涓€夐」锛岃€屾槸涓€涓繀椤婚」銆傚瀛愭彁楂樹綋璐紝鏈夊ソ鐨勮韩浣擄紝鎵嶈兘鏈潵閫傚簲婵€鐑堢殑瀛︿範鐜锛屼綋鑲蹭腑鑰冪幇鍦ㄥ垎鍊艰秺鏉ヨ秺閲嶏紝涔熷厖鍒嗚鏄庡浗瀹跺浜庡瀛愯韩浣撲綋榄勭殑閲嶈銆傚悓鏃讹紝杩愬姩涔熻兘璁╁瀛愭湁鏇村潥闊х殑鎬ф牸锛屾洿鍔犵殑鐙珛鑷俊銆傛偍涓嶄細鐪嬪埌浠讳綍涓€涓埍杩愬姩鐨勪汉鏄秷鏋併€佸鍍绘姂閮佺殑锛岃繍鍔ㄦ槸涓€涓繀椤婚」涓旀槸缁堣韩鐨勬姇璧勩€?,
        'customer_intent_signals' => json_encode([
            'high' => ['閭ｅ厛鎶ヤ竴鏈熻瘯璇?, '澶氬皯閽变竴鑺傝'],
            'low' => ['涓嶉渶瑕?, '绠椾簡'],
            'medium' => ['鎴戝啀鎯虫兂', '鍥炲幓鍟嗛噺涓€涓?]
        ]),
        'tips' => '寮鸿皟杩愬姩鐨勫繀闇€鎬у拰闀挎湡浠峰€?
    ],
    [
        'scene_code' => 'price-too-high',
        'scene_name' => '浠锋牸澶珮',
        'keywords' => json_encode(['澶吹', '浠锋牸楂?, '渚垮疁鐐?]),
        'standard_script' => '棣栧厛闈炲父鎰熻阿瀵硅拷鍏夊皬鐗涜绋嬬殑璁ゅ彲锛屾垜浠搧鐗屾槸璐靛窞鐪佸効绔ヨ繍鍔ㄧ殑棰嗗厛鍝佺墝銆傝拷鍏夊皬鐗涙槸涓€瀹惰繛閿佸搧鐗岋紝鍦ㄩ€夊潃鐜锛堢閲戯級銆佸笀璧勩€佽澶囦互鍙婃暀鐮斾笂鎴戜滑鐨勬姇鍏ラ兘浼氭瘮涓綋鎴烽棬搴楅珮璁稿銆備环鏍兼槸涓€鏂归潰锛屼絾鏄搧璐ㄦ槸鏇撮噸瑕佺殑锛屾垜浠殑浠锋牸缁濆鏄€т环姣旂殑銆傝€屾垜浠殑璇剧▼浣撶郴涓嶄粎浠呮槸鍏充簬瀛╁瓙浣撹兘涓婄殑鎻愬崌锛屾垜浠洿鍔犲叧娉ㄥ瀛愬湪鎬ф牸濉戦€狅紝璁╁瀛愬吇鎴愬潥闊х殑鎬ф牸锛屽苟涓旀洿鍔犵嫭绔嬭嚜淇°€?,
        'customer_intent_signals' => json_encode([
            'high' => ['閭ｆ湁浠€涔堜紭鎯?, '鑳戒究瀹滃灏?],
            'low' => ['澶吹浜?, '涓嶉渶瑕?],
            'medium' => ['鏈夌偣璐?, '鑰冭檻涓€涓?]
        ]),
        'tips' => '寮鸿皟鍝佽川鍜屽搧鐗屼环鍊硷紝鑰岄潪鍗曠函浠锋牸'
    ],
    [
        'scene_code' => 'need-consult-husband',
        'scene_name' => '瑕佸洖鍘婚棶鑰佸叕',
        'keywords' => json_encode(['闂€佸叕', '鍟嗛噺', '鍥炲鎯虫兂']),
        'standard_script' => 'XX濡堝锛屾偍涓€鐪嬪氨鏄竴浣嶆棦閲嶈瀛╁瓙鐨勬暀鑲诧紝鍙堥潪甯哥収椤捐€佸叕鎰熷彈鐨勫ソ濡堝銆備笉杩囨湁鍑犱釜闂锛屾偍缁欒€佸叕浠嬬粛鍜变滑璇剧▼鐨勬椂鍊欙紝鑳藉儚鎴戜竴鏍疯杩板緱瀹屾暣璇︾粏鍚楋紵杩欏彲鑳戒細褰卞搷鑰佸叕鐨勫垽鏂€傛偍闂€佸叕鐨勬剰瑙佹棤闈炲氨鏄兂缁煎悎鑰冮噺鏉冭　涓€涓嬶紝涓嶅Θ鎮ㄥ厛缁欏瀛愭姤锛岃瀛╁瓙鍏堝鐫€銆傜瓑鑰佸叕鏈夋椂闂翠簡鍐嶅憡璇変粬锛屽鏋滀粬鏈潵灏变細鍚屾剰鐨勮瘽锛岄偅杩欏氨绠楁槸涓€涓儕鍠溿€傚鏋滀粬鏈変笉鍚岀殑鎰忚锛屽彲浠ョ瓑瀛╁瓙涓婅鏃惰浠栭€佽繃鏉ワ紝鍒版椂鍊欐垜鍐嶈窡浠栬缁嗕粙缁嶃€?,
        'customer_intent_signals' => json_encode([
            'high' => ['閭ｆ垜鍏堟姤', '浣犺窡浠栬'],
            'low' => ['杩樻槸绛変粬鍚屾剰'],
            'medium' => ['鎴戝洖鍘婚棶闂?]
        ]),
        'tips' => '甯姪瀹堕暱鍋氬喅瀹氾紝娑堥櫎椤捐檻'
    ],
    [
        'scene_code' => 'distance-far',
        'scene_name' => '璺濈杩滐紝鑰佷汉鎺ラ€佷笉渚?,
        'keywords' => json_encode(['璺濈杩?, '澶繙', '鎺ラ€?]),
        'standard_script' => '瀹堕暱闈炲父鐞嗚В锛屽钩鏃ュ伐浣滃氨涓嶅鏄撹繕瑕佹帴閫併€傛垜浠満鏋勪篃鏈夊緢澶氭槸鍦?0鍏噷澶栬繃鏉ヤ笂璇剧殑锛屼篃鏈夊緢澶氫竴鍛ㄤ笂涓夎妭璇剧殑銆傞偅鎮ㄨ寰椾粬浠负浠€涔堜細閫夋嫨杩欎箞涔呴兘鍧氭寔涓嬫潵锛熻鏄庢垜浠殑璇剧▼瀹堕暱鏄鍙殑锛屼粬浠寰楄姳杩欑偣鏃堕棿璁╁瀛愪綋璐ㄤ笂寰楀埌澧炲己銆佷綋鑳戒笂寰楀埌鍙樺寲銆佹€ф牸涓婂緱鍒板閫犳槸鍊煎緱鐨勩€傛棦鐒堕€夋嫨璁╁疂璐濆锛岃偗瀹氶€夋嫨鏈€涓撲笟銆佹渶鏈変繚闅滅殑銆?,
        'customer_intent_signals' => json_encode([
            'high' => ['涔熸槸', '鏈夐亾鐞?],
            'low' => ['澶繙浜?],
            'medium' => ['鑰冭檻鑰冭檻']
        ]),
        'tips' => '鐢ㄥ叾浠栧闀跨殑鍧氭寔妗堜緥璇存湇'
    ],
    [
        'scene_code' => 'father-disagree',
        'scene_name' => '鐖哥埜涓嶅悓鎰?,
        'keywords' => json_encode(['鐖哥埜', '鑰佸叕', '涓嶅悓鎰?, '瑙夊緱娌″繀瑕?]),
        'standard_script' => '鐩镐俊鐖哥埜涓€瀹氶潪甯哥埍瀛╁瓙锛屽彧瑕佹槸瀵瑰瀛愭垚闀挎湁甯姪鐨勮绋嬶紝涓€瀹氭効鎰忚瀛╁瓙鍙傚姞銆備絾浠婂ぉ鐖哥埜娌℃潵锛屽彲鑳借繕涓嶅お浜嗚В璇剧▼瀵瑰瀛愮殑鐩婂銆傚洜涓鸿繖涓彮绾у浐瀹氬浣嶄笉澶氫簡锛屽彲浠ュ厛缁欏瀛愬畾涓嬫潵銆備笅鍥炲彲浠ヨ鐖哥埜鏉ヤ竴瓒熸牎鍖猴紝鎴戦潪甯告効鎰忚窡鐖哥埜鍒嗕韩浣撹偛鏁欒偛鐨勭悊蹇碉紝鍒版椂鍊欑埜鐖镐竴瀹氫細娆ｈ祻濡堝鎮ㄥ仛寰楁槑鏅虹殑鍐冲畾锛?,
        'customer_intent_signals' => json_encode([
            'high' => ['閭ｆ垜鍏堝畾涓嬫潵', '涓嬫甯︿粬鏉?],
            'low' => ['绛変粬鍚屾剰鍐嶈'],
            'medium' => ['鎴戝啀鎯虫兂']
        ]),
        'tips' => '璧炵編濡堝鐨勫喅瀹氾紝鍚屾椂閭€璇风埜鐖告潵浜嗚В'
    ],
    [
        'scene_code' => 'how-long-effect',
        'scene_name' => '澶氫箙鏈夋晥鏋?,
        'keywords' => json_encode(['澶氫箙', '鏁堟灉', '瑙佹晥']),
        'standard_script' => '杩愬姩鏄竴浠堕渶瑕佹寔缁潥鎸佺殑浜嬫儏锛屼竴鑸瀛?-3涓湀灏辨湁涓€涓槑鏄剧殑鍒濇鍙樺寲锛屽彲鑳芥槸韬綋浣撹川鏂归潰鐨勩€佽繍鍔ㄧ礌璐ㄦ柟闈㈢殑鎻愬崌锛屽寘鎷帉鎻′竴浜涘熀鏈殑杩愬姩鎶€鑳姐€傚湪杩欐湡闂达紝瀛╁瓙鐨勬€ф牸銆佸績鐞嗕篃浼氭湁寰堢Н鏋佺殑鍙樺寲锛屾瘮濡傝瀛╁瓙鍙樺緱鏇存湁瑙勫垯鎰忚瘑銆佹洿鍔犺嚜淇°€佹洿鍔犵Н鏋侀槼鍏夈€佹洿鎰挎剰璺熷皬鏈嬪弸娌熼€氬崗浣溿€傚湪杩藉厜灏忕墰锛屾瘡涓€涓繍鍔ㄩ」鐩兘鏈夊搴旂殑璇剧▼浣撶郴锛屽畾鏈熺殑鑰冪骇鍜屾祴璇曡瀛╁瓙浠ュ強瀹堕暱鏄庣‘鐭ラ亾涓嶅悓瀛︿範鍛ㄦ湡瀵瑰簲鐨勫涔犳晥鏋溿€?,
        'customer_intent_signals' => json_encode([
            'high' => ['閭ｅ氨鎶ュ悕', '鍏堣瘯涓€涓?],
            'low' => ['澶箙浜?],
            'medium' => ['鑰冭檻鑰冭檻']
        ]),
        'tips' => '缁欏嚭鍏蜂綋鏃堕棿棰勬湡锛屽缓绔嬪悎鐞嗘湡鏈?
    ],
    [
        'scene_code' => 'too-much-study',
        'scene_name' => '瀛﹀お澶氫笢瑗夸簡锛屼笉鎯冲瀛愬お绱?,
        'keywords' => json_encode(['澶疮', '瀛﹀お澶?, '鍘嬪姏澶?]),
        'standard_script' => '濡堝缁欏瀛愰€夋嫨浜嗛偅涔堝鐨勮绋嬶紝璇存槑濡堝瀵瑰瀛愭湁鐫€寮虹儓鐨勬暀鑲叉剰璇嗐€傛垜浠繖閲岀殑璇剧▼閮芥槸浠ュ瀛愪韩鍙楄繍鍔ㄧ殑蹇箰涓轰富锛屼細铻嶅叆寰堝鐨勬父鎴忓湪閲岄潰銆備笓涓氭湁瓒ｇ殑杩愬姩璇句笉浠呰兘澶熷府鍔╁瀛愬寮轰綋璐ㄣ€佹彁鍗囧厤鐤姏锛岃繕鑳藉緢濂界殑閲婃斁瀛╁瓙鍥犱负杩囧鐨勫涔犲甫鏉ョ殑鍘嬫姂鎯呯华銆傝壇濂界殑浣撻瓌锛屾墠鑳借瀛╁瓙鏇村ソ鐨勫簲瀵圭箒蹇欑殑瀛︿笟銆傝繍鍔ㄨ宸茬粡鏄瀛愭垚闀跨殑鍒氶渶浜嗐€?,
        'customer_intent_signals' => json_encode([
            'high' => ['涔熸槸', '鏈夐亾鐞?],
            'low' => ['杩樻槸绠椾簡'],
            'medium' => ['鎴戝啀鎯虫兂']
        ]),
        'tips' => '灏嗚繍鍔ㄥ畾浣嶄负鏀炬澗鑰岄潪璐熸媴'
    ],
    [
        'scene_code' => 'school-pe-enough',
        'scene_name' => '瀛︽牎鏈変綋鑲茶锛屼笉闇€瑕侀澶栨姤',
        'keywords' => json_encode(['浣撹偛璇?, '瀛︽牎', '涓嶉渶瑕?]),
        'standard_script' => '瀛︽牎浣撹偛璇惧洜涓烘槸澶х彮璇炬暀瀛︼紝閫氬父璁粌椤圭洰涓嶅叏闈紝娌℃湁閽堝鎬с€佽叮鍛虫€т綆锛屽緢澶氬瀛愬湪瀛︽牎鐨勪綋鑲茶涓〃鐜颁笉濂斤紝鐢氳嚦浼氫抚澶卞浜庤繍鍔ㄧ殑鍏磋叮锛屼粠鑰屽け鍘昏嚜淇″績銆傝€屾垜浠拷鍏夊皬鐗涙湰韬洿缁曠潃瀛╁瓙鐢熼暱鍙戣偛鐨勫懆鏈熺壒鐐癸紝涓嶆柇鐮斿彂鍏锋湁瓒ｅ懗鎬с€佷篃鏈夋寫鎴樻劅銆佹垚灏辨劅鐨勮绋嬨€傚皬鐝埗鑳藉鏇村ソ鐨勫叧娉ㄥ瀛愶紝鍙婃椂璋冩暣璁粌鏂规銆?,
        'customer_intent_signals' => json_encode([
            'high' => ['纭疄', '鏈夐亾鐞?],
            'low' => ['瀛︽牎灏卞浜?],
            'medium' => ['鑰冭檻涓€涓?]
        ]),
        'tips' => '瀵规瘮瀛︽牎浣撹偛璇剧殑灞€闄愭€?
    ],
    [
        'scene_code' => 'already-has-other-class',
        'scene_name' => '宸茬粡鍦ㄥ鑸炶箞/璺嗘嫵閬撲簡',
        'keywords' => json_encode(['鑸炶箞', '璺嗘嫵閬?, '宸茬粡鏈?, '涓嶉渶瑕?]),
        'standard_script' => '瀹堕暱锛屾牴鎹?鍒?2宀佸瀛愮殑鍙戣偛鐗圭偣锛屼綋鑳借鏈€閫傚悎瀛╁瓙锛屼篃鏈夊姪浜庡瀛愮殑鐢熼暱鍙戣偛銆傝繃鏃╃殑鎺ヨЕ涓撻」璇剧▼锛屼竴鏂归潰瀹规槗璁╁瀛愬彈浼わ紝鍙︿竴鏂归潰鍥犱负缂轰箯浣撹兘鍩虹锛屽瀛愬涔犵殑杈冩參锛屼笉瀹规槗寤虹珛鑷俊蹇冦€傚悓鏃跺ぇ閮ㄥ垎鐨勪笓椤硅繍鍔ㄩ兘鏄崟杈硅繍鍔紝瀹规槗閫犳垚瀛╁瓙浣撴€佸彂鐢熼棶棰樸€傛垜浠殑璇剧▼鏄墍鏈夎繍鍔ㄦ渶鍩虹鍜屾渶鏍稿績鐨勮缁冿紝鏄笉鍐茬獊鐨勩€傚鏋滄嬁鍚冮キ鏉ユ瘮鍠伙紝鑸炶箞銆佽穯鎷抽亾杩欑被璇剧▼灏辨槸鑿滐紝浣撹兘璇剧▼灏辨槸绫抽キ銆?,
        'customer_intent_signals' => json_encode([
            'high' => ['鏈夐亾鐞?, '閭ｅ彲浠ヤ竴璧峰'],
            'low' => ['涓嶉渶瑕佷簡'],
            'medium' => ['鑰冭檻涓€涓?]
        ]),
        'tips' => '鐢ㄦ瘮鍠昏鏄庝綋鑳芥槸鍩虹'
    ],
    [
        'scene_code' => 'worry-child-cannot-persist',
        'scene_name' => '鎷呭績瀛╁瓙鍧氭寔涓嶄笅鏉?,
        'keywords' => json_encode(['鍧氭寔', '鍧氭寔涓嶄笅鏉?, '閫€']),
        'standard_script' => '濡堝鏄媴蹇冨瀛愬潥鎸佷笉涓嬫潵锛屾槸涔堬紵寰堣兘鐞嗚В濡堝鐨勬媴蹇с€傚叧浜庡潥鎸侊紝鏄拰鐩爣鍜岃繃绋嬩腑鐨勫垎瑙ｆ湁鍏崇郴鐨勩€傛垜浠殑璇剧▼鏈夊搴旂殑鏃堕棿娈靛拰瀵瑰簲鐨勯樁娈电洰鏍囥€?. 鑰佸笀鐨勬巿璇炬槸鍚︽湁瓒ｅ懗銆佸舰寮忋€佹暀甯堟湰浜虹殑褰卞搷鍔涢兘寰堥噸瑕侊紝鍐冲畾瀛╁瓙鐨勫叴瓒ｇ偣锛?. 杩囩▼涓鏍￠兘瑕佹竻鏅板瀛愬湪杩欓噷瀛︿範鐨勯暱鏈熺洰鏍囷紱3. 涓€鏃﹀瀛愯繘鍏ヨ鐪熸湡锛岃嚜鐒跺氨鍏ラ棬浜嗐€傚瀛愭湁杩涙锛屽闀挎湁淇″績鏈夋柟娉曪紝瀛︽牎鏈夌洃鐫ｏ紝涓夋柟绱у瘑缁撳悎锛屼竴瀹氭槸鍙互鍧氭寔鐨勩€?,
        'customer_intent_signals' => json_encode([
            'high' => ['鏈夐亾鐞?, '閭ｅ氨鎶ュ悕'],
            'low' => ['杩樻槸绠椾簡'],
            'medium' => ['鎴戝啀鎯虫兂']
        ]),
        'tips' => '鍒嗚В鍧氭寔鐨勮绱狅紝寤虹珛淇″績'
    ],
    [
        'scene_code' => 'frequently-absent',
        'scene_name' => '瀹堕暱棰戠箒璇峰亣',
        'keywords' => json_encode(['璇峰亣', '缁忓父涓嶆潵', '缂鸿']),
        'standard_script' => 'XX濡堝锛岄鍏堟劅璋㈡偍姣忔閮界粰鎴戞墦涓嫑鍛艰鍋囷紝娌℃湁璁╁皬鏄庣洿鎺ユ椃璇俱€傚挶鎶婂瀛愰€佽繃鏉ュ煿璁紝姣曠珶涔熻姳浜嗕笉灏戦挶锛屾兂瑕佸瀛愯兘澶熻缁冨嚭鏁堟灉銆傜粡甯歌鍋囧鏄撳鑷村瀛愮殑鍩硅杩涘害璺熶笉涓婂幓锛屼竴鏃︽媺寮€鐨勫樊璺濇瘮杈冨ぇ涔嬪悗锛屽浜庡瀛愮殑鍩硅鍏磋叮鍜屽煿璁嚜淇″績鑲畾浼氭湁褰卞搷銆傚彟澶栵紝涔熷鏄撹浠栦骇鐢熶笉绠″仛浠讳綍浜嬫儏鍘熸潵閮芥槸鍙互闅忔剰璇峰亣鐨勯敊瑙夛紝涓嶅埄浜庡瀛愬吇鎴愬潥鎸佺殑濂藉搧璐ㄤ互鍙婂潥闊х殑鎬ф牸銆?,
        'customer_intent_signals' => json_encode([
            'high' => ['濂界殑', '鎴戞敞鎰忎竴涓?],
            'low' => ['娌″姙娉?],
            'medium' => ['鐭ラ亾浜?]
        ]),
        'tips' => '娓╁拰鎻愰啋闀挎湡鍧氭寔鐨勯噸瑕佹€?
    ],
    [
        'scene_code' => 'child-not-want-renew',
        'scene_name' => '瀛╁瓙涓嶆兂瀛︿簡锛屼笉缁垂',
        'keywords' => json_encode(['涓嶇画璐?, '涓嶆兂瀛?, '鏀惧純浜?]),
        'standard_script' => 'XX濡堝锛屽叾瀹炶繖绉嶅浜嗕竴娈垫椂闂村氨涓嶆兂瀛︿簡鐨勬儏鍐靛湪寰堝瀛╁瓙韬笂閮芥湁鎵€浣撶幇锛岃繖寰堟甯搞€傝繍鍔ㄦ湰韬氨鏄瀛愭垚闀跨殑鍒氶渶锛岃繍鍔ㄨ兘鍔涚殑楂樹綆鐢氳嚦褰卞搷瀛︿範鐨勮兘鍔涖€傚吇鎴愯繍鍔ㄤ範鎯紝涔熻兘甯姪瀛╁瓙鍙樺緱鏇翠紭绉€銆傛垜浠幇鍦ㄥ簲璇ヤ竴璧锋惡鎵嬪郊姝ら厤鍚堬紝澶氳窡瀛╁瓙娌熼€氾紝澶氬幓榧撳姳浠栵紝澶氬幓寮曞浠栵紝甯粬鎺掕В鎺夎繖绉嶅帉瀛︽儏缁€傚叾瀹炰箣鍓嶅瀛愭湁杩囪繖鏂归潰鐨勮〃鐜帮紝鎴戜滑閮藉府浠栧緢濂界殑璋冩暣杩囨潵浜嗐€?,
        'customer_intent_signals' => json_encode([
            'high' => ['閭ｅ啀璇曡瘯', '濂藉惂'],
            'low' => ['绠椾簡'],
            'medium' => ['鎴戝啀鎯虫兂']
        ]),
        'tips' => '鐞嗚В瀛╁瓙锛岀粰鍑洪紦鍔卞拰鏀寔'
    ],
    [
        'scene_code' => 'closing-techniques',
        'scene_name' => '鍏冲崟璇濇湳',
        'keywords' => json_encode(['鍏冲崟', '鎴愪氦', '鎶ュ悕']),
        'standard_script' => '鐩存帴鍏冲崟锛氭偍杩欒竟娌℃湁鍏朵粬闂锛屽挶浠粖澶╁姙涓€涓嬫墜缁惂锛佹偍鏄埛鍗¤繕鏄敮浠樺疂鍛紵鍋囧畾鎴愪氦锛氱洰鍓嶉€傚悎瀹濊礉杩欎釜骞撮緞娈垫湁鍛ㄤ簩鍛ㄥ洓鍛ㄦ棩杩欏嚑涓彮锛屾偍鐪嬬湅浣犱滑鍝釜鏃堕棿鏂逛究銆?淇濊瘉"鍏冲崟锛氬瀛愪氦缁欐垜浠紝鎮ㄦ斁蹇冦€傜浉淇℃垜浠紝涓€瀹氫細璁╂偍瑙夊緱鐗╄秴鎵€鍊硷紝瀛﹀緱濂戒簡澶氬府鎴戞帹鑽愪簺鏈嬪弸杩囨潵锛?閫犳ⅵ"鍏冲崟锛氬挶浠疂璐濈幇鍦ㄥ紑濮嬮敾鐐艰捣鏉ワ紝鍗婂勾鍚庝綋璐ㄦ瘮鍚岄緞灏忔湅鍙嬪ソ锛屽叾浠栧濡堝湪涓哄瀛愮粡甯告劅鍐掑彂鐑ц窇鍖婚櫌鐨勬椂鍊欙紝鎮ㄤ笉鐢紝瑕佺渷澶氬皯蹇冿紝鐪佸灏戞椂闂达紝瀛╁瓙涔熷皯閬灏戠姜鍟婏紒',
        'customer_intent_signals' => json_encode([
            'high' => ['鍒峰崱', '鏀粯瀹?],
            'low' => ['鍐嶈€冭檻'],
            'medium' => ['鍝釜鏃堕棿']
        ]),
        'tips' => '鏍规嵁瀹堕暱绫诲瀷閫夋嫨鍚堥€傜殑鍏冲崟鏂瑰紡'
    ],
];

// Insert Q&A entries
$dim_id = 1; // qa dimension
$sort_order = 100;

foreach ($qa_entries as $entry) {
    $keywords = is_string($entry['keywords']) ? $entry['keywords'] : json_encode($entry['keywords']);
    $intent = isset($entry['customer_intent_signals']) ?
        (is_string($entry['customer_intent_signals']) ? $entry['customer_intent_signals'] : json_encode($entry['customer_intent_signals'])) : null;

    $stmt = $db->prepare("
        INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, customer_intent_signals, tips, sort_order, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE scene_name = VALUES(scene_name), keywords = VALUES(keywords),
            standard_script = VALUES(standard_script), customer_intent_signals = VALUES(customer_intent_signals), tips = VALUES(tips)
    ");
    $stmt->execute([
        $dim_id,
        $entry['scene_code'],
        $entry['scene_name'],
        $keywords,
        $entry['standard_script'],
        $intent,
        $entry['tips'],
        $sort_order++
    ]);
    echo "Inserted/Updated: " . $entry['scene_name'] . "\n";
}

echo "\nQ&A entries imported: " . count($qa_entries) . "\n";
echo "Import completed!\n";
