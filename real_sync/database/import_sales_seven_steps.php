<?php
/**
 * 瀵煎叆閿€鍞竷姝ユ洸鍒拌瘽鏈煡璇嗗簱鍜屽煿璁崱鐗?
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

echo "=== 閿€鍞竷姝ユ洸瀵煎叆鑴氭湰 ===\n\n";

// 閿€鍞竷姝ユ洸鏁版嵁
$sevenSteps = [
    [
        'step' => 1,
        'name' => '鏆栧満鐮村啺',
        'keywords' => ['鏆栧満', '鐮村啺', '鎵撴嫑鍛?, '鑷垜浠嬬粛', '寤虹珛淇′换'],
        'qa_script' => 'Q: 棣栨瑙侀潰濡備綍鐮村啺锛? . "\n" .
                      'A: "鎮ㄥソ锛佹垜鏄拷鍏夊皬鐗涚殑XX鏁欑粌锛岄潪甯搁珮鍏磋璇嗘偍鍜屽疂璐濓紒璇烽棶鎬庝箞绉板懠鎮ㄥ憿锛熷瀛愬彨浠€涔堝悕瀛楋紵浠婂勾澶氬ぇ浜嗭紵"' . "\n\n" .
                      'Q: 濡備綍浜嗚В瀹㈡埛鏉ユ簮娓犻亾锛? . "\n" .
                      'A: "鎮ㄦ槸鎬庝箞浜嗚В鍒版垜浠拷鍏夊皬鐗涚殑鍛紵鏄湅鍙嬫帹鑽愯繕鏄湅鍒版垜浠殑瀹ｄ紶锛?' . "\n\n" .
                      'Q: 濡備綍娑堥櫎瀹堕暱鎴掑蹇冪悊锛? . "\n" .
                      'A: "鎮ㄥ彲浠ュ厛甯﹀瀛愮啛鎮変竴涓嬬幆澧冿紝鎴戜滑杩欓噷鏄笓涓氬効绔ヨ繍鍔ㄤ腑蹇冿紝鎵€鏈夊櫒鏉愰兘鏄负瀛╁瓙璁捐鐨勶紝寰堝畨鍏ㄣ€傝瀛╁瓙鍏堢帺涓€鐜╋紝鐪嬬湅鍠滀笉鍠滄銆?',
        'knowledge' => '銆愭殩鍦虹牬鍐板叧閿偣銆? . "\n" .
                      '1. 涓诲姩鐑儏锛氱涓€鏃堕棿鎵撴嫑鍛硷紝灞曠幇涓撲笟褰㈣薄' . "\n" .
                      '2. 鏍囧噯鑷垜浠嬬粛锛?鎮ㄥソ锛佹垜鏄拷鍏夊皬鐗涚殑XX鏁欑粌"' . "\n" .
                      '3. 瀛╁瓙淇℃伅锛氬鍚嶃€佸勾榫勩€佹潵婧愭笭閬撱€佸钩鏃惰繍鍔ㄦ儏鍐? . "\n" .
                      '4. 鐜浠嬬粛锛氫笓涓氬櫒鏉愩€佸畨鍏ㄧ幆澧冦€佽绋嬬壒鑹? . "\n" .
                      '5. 鎷夎繎璺濈锛氱敤瀛╁瓙鎰熷叴瓒ｇ殑璇濋鍒囧叆',
        'deal_tips' => '銆愭殩鍦虹牬鍐拌皥鍗曡鐐广€? . "\n" .
                      '1. 绗竴鍗拌薄鍐冲畾鎴愪氦锛氱潃瑁呮暣娲併€佺儹鎯呬笓涓? . "\n" .
                      '2. 鐢ㄥ瀛愭墦寮€璇濋锛氳瀛╁瓙鍠滄浣? . "\n" .
                      '3. 蹇€熷缓绔嬩俊浠伙細灞曠ず涓撲笟璧勮川鍜屾垚鍔熸渚? . "\n" .
                      '4. 涓嶈鎬ヤ簬閿€鍞細鍏堝缓绔嬪叧绯伙紝鍐嶈皥鎴愪氦'
    ],
    [
        'step' => 2,
        'name' => '闇€姹傛寲鎺?,
        'keywords' => ['闇€姹?, '鎸栨帢', '鑳屾櫙', '鏈熸湜', '鐥涚偣'],
        'qa_script' => 'Q: 濡備綍鎸栨帢瀹堕暱鐪熷疄闇€姹傦紵' . "\n" .
                      'A: "鎮ㄥ笇鏈涘瀛愰€氳繃杩愬姩鏀瑰杽鍝柟闈㈠憿锛熸槸浣撹川銆佷笓娉ㄥ姏锛岃繕鏄兂璁╁瀛愬杩愬姩銆佸皯鐢熺梾锛熸垨鑰呮槸涓轰簡鍑嗗鏌愰」鑰冭瘯锛?',
        'knowledge' => '銆愰渶姹傛寲鎺樺叧閿偣銆? . "\n" .
                      '1. 浜嗚В瀛╁瓙鎴愰暱鑳屾櫙锛氬嚭鐢熸儏鍐点€佸彂鑲茬姸鍐? . "\n" .
                      '2. 浜嗚В瀛╁瓙鍏磋叮鐖卞ソ锛氬枩娆粈涔堣繍鍔ㄣ€佹€曚粈涔? . "\n" .
                      '3. 浜嗚В杩愬姩缁忓巻锛氫箣鍓嶅杩囦粈涔堛€佷负浠€涔堟病缁х画' . "\n" .
                      '4. 浜嗚В瀹堕暱鏈熸湜锛氭兂杈惧埌浠€涔堟晥鏋溿€佹椂闂村畨鎺? . "\n" .
                      '5. 浜嗚В鍐崇瓥浜猴細璋佸喅瀹氥€佽皝浠樿垂銆佽皝闄即',
        'deal_tips' => '銆愰渶姹傛寲鎺樿皥鍗曡鐐广€? . "\n" .
                      '1. 澶氶棶寮€鏀惧紡闂锛氫粈涔堛€佷负浠€涔堛€佹€庝箞鎯? . "\n" .
                      '2. 鎸栨帢鐥涚偣闇€姹傦細瀛╁瓙鏈変粈涔堥棶棰樿瀹堕暱鍥版壈' . "\n" .
                      '3. 纭鍐崇瓥浜猴細璋佹墠鏄湡姝ｈ兘鎷嶆澘鐨勪汉' . "\n" .
                      '4. 璁板綍瀹堕暱闇€姹傦細鏂逛究鍚庣画璺熻繘鍜屼釜鎬у寲鎺ㄨ崘'
    ],
    [
        'step' => 3,
        'name' => '浜у搧浠嬬粛',
        'keywords' => ['浜у搧', '浠嬬粛', '鍝佺墝', '璇剧▼', '鏈嶅姟'],
        'qa_script' => 'Q: 濡備綍浠嬬粛鍝佺墝瀹炲姏锛? . "\n" .
                      'A: "杩藉厜灏忕墰鏄吹闃虫湰鍦熶笓涓氬効绔ヨ繍鍔ㄥ搧鐗岋紝5瀹剁洿钀ラ棬搴楋紝40+涓撲笟鏁欑粌锛屼笘鐣屽啝鍐涘弬涓庤绋嬬爺鍙戯紝宸叉湇鍔?0涓?浼氬憳銆?' . "\n\n" .
                      'Q: 濡備綍浠嬬粛璇剧▼浣撶郴锛? . "\n" .
                      'A: "鎴戜滑鏍规嵁瀛╁瓙骞撮緞鍜岄渶姹傜瀛﹀垎榫勶細2-6宀佹劅缁熻缁冦€?-12宀佷綋鑳界鐞冦€?-14宀佽窇閰疯烦缁炽€?-14宀佷腑鑰冧綋娴嬶紝姣忛樁娈甸兘鏈夐拡瀵规€ф柟妗堛€?',
        'knowledge' => '銆愪骇鍝佷粙缁嶅叧閿偣銆? . "\n" .
                      '1. 鍝佺墝瀹炲姏锛氬啝鍐涚爺鍙戙€?搴楄繛閿併€佷笓涓氬畨鍏? . "\n" .
                      '2. 鏁欑粌璧勮川锛氭寔璇佷笂宀椼€佷笓涓氬煿璁€佺埍瀛╁瓙' . "\n" .
                      '3. 璇剧▼浣撶郴锛氬垎榫勫垎闃舵銆佺瀛﹁缁冦€佹晥鏋滃彲瑙? . "\n" .
                      '4. 鏈嶅姟娴佺▼锛氫綋娴嬭瘎浼般€佸畾鍒舵柟妗堛€佷笁涓湀鍙嶉' . "\n" .
                      '5. 宸紓鍖栵細鍏朵粬鏈烘瀯娌℃湁鐨勶紝鎴戜滑鏈?,
        'deal_tips' => '銆愪骇鍝佷粙缁嶈皥鍗曡鐐广€? . "\n" .
                      '1. 鐢ㄦ暟鎹璇濓細5瀹跺簵銆?0+鏁欑粌銆?0涓?浼氬憳' . "\n" .
                      '2. 鐢ㄦ渚嬭璇濓細鏌愭煇瀛╁瓙鏉ヤ箣鍓嶆€庢牱锛岀幇鍦ㄦ€庢牱' . "\n" .
                      '3. 鐢ㄦ潈濞佽璇濓細涓栫晫鍐犲啗鐮斿彂銆佷笓涓氭満鏋勮璇? . "\n" .
                      '4. 鍖归厤闇€姹傦細瀹堕暱鎯宠浠€涔堬紝鎴戜滑灏遍噸鐐逛粙缁嶄粈涔?
    ],
    [
        'step' => 4,
        'name' => '寮傝澶勭悊',
        'keywords' => ['寮傝', '澶勭悊', '浠锋牸', '鏃堕棿', '鏁堟灉', '瀹夊叏'],
        'qa_script' => 'Q: 瀹堕暱璇?澶吹浜?鎬庝箞澶勭悊锛? . "\n" .
                      'A: "鎴戠悊瑙ｆ偍鐨勯【铏戙€備笉杩囪繍鍔ㄦ槸褰卞搷瀛╁瓙涓€鐢熺殑浜嬶紝鐜板湪鎶曡祫杩愬姩鏀归€犳垚鏈渶浣庛€傛偍绠椾竴涓嬶紝涓€鍛ㄥ嚑鑺傝锛屾瘡鑺傝涓嶅埌XX鍏冿紝杩樺寘鍚笓涓氫綋娴嬨€佷釜鎬ф柟妗堛€佽繍鍔ㄤ繚闄┿€傝€屼笖鎴戜滑鏈€杩戞湁浼樻儬娲诲姩..."' . "\n\n" .
                      'Q: 瀹堕暱璇?娌℃椂闂?鎬庝箞澶勭悊锛? . "\n" .
                      'A: "鎴戝畬鍏ㄧ悊瑙ｆ偍蹇欍€傚叾瀹炰竴鍛ㄥ彧闇€瑕?-3涓皬鏃讹紝杩愬姩涓嶄粎涓嶆氮璐规椂闂达紝鍙嶈€岃兘鎻愬崌瀛╁瓙涓撴敞鍔涳紝瀛︿範鏁堢巼鏇撮珮銆傚緢澶氬闀垮彂鐜帮紝瀛╁瓙杩愬姩鍚庢垚缁╁弽鑰岃繘姝ヤ簡銆?' . "\n\n" .
                      'Q: 瀹堕暱璇?鏁堟灉涓嶆槑鏄?鎬庝箞澶勭悊锛? . "\n" .
                      'A: "鎮ㄥ叧娉ㄦ晥鏋滄槸瀵圭殑銆傛垜浠瘡涓変釜鏈堝仛浣撴祴瀵规瘮锛屽悓榫勫叏鍥芥帓鍚嶈繘姝ユ偍鑳界湅鍒般€傝€屼笖杩愬姩鏀归€犲ぇ鑴戯紝瀛╁瓙涓撴敞鍔涘ソ浜嗐€佸悆楗浜嗐€佺潯寰楀ソ浜嗭紝杩欎簺閮芥槸鍙樺寲銆備笁涓湀鍚庢偍鍐嶇湅銆?',
        'knowledge' => '銆愬紓璁鐞嗗洓姝ユ硶銆? . "\n" .
                      '1. 璁ゅ悓锛氬厛璁ゅ彲瀹堕暱鐨勯【铏戯紝"鎴戠悊瑙ｆ偍鐨勬兂娉?' . "\n" .
                      '2. 鎻愰棶锛氫簡瑙ｇ湡瀹炲師鍥狅紝"鎮ㄤ富瑕佹槸鎷呭績浠€涔堬紵"' . "\n" .
                      '3. 瑙ｇ瓟锛氶拡瀵规€цВ鍐筹紝缁欏嚭璇佹嵁鍜屾渚? . "\n" .
                      '4. 寮曞锛氭妸璇濋寮曞洖鍒拌绋嬩环鍊间笂',
        'deal_tips' => '銆愬紓璁鐞嗚皥鍗曡鐐广€? . "\n" .
                      '1. 浠锋牸寮傝锛氭媶瑙ｆ垚鏈€佸己璋冧环鍊笺€侀€傛椂浼樻儬' . "\n" .
                      '2. 鏃堕棿寮傝锛氬己璋冩晥鐜囥€佷翰瀛愰櫔浼淬€佷範鎯吇鎴? . "\n" .
                      '3. 鏁堟灉寮傝锛氭暟鎹姣斻€佹渚嬪睍绀恒€佽€愬績瑙ｉ噴' . "\n" .
                      '4. 瀹夊叏寮傝锛氫笓涓氬櫒鏉愩€佷笓涓氭暀缁冦€佸畨鍏ㄥ埗搴?
    ],
    [
        'step' => 5,
        'name' => '閫煎崟鎴愪氦',
        'keywords' => ['閫煎崟', '鎴愪氦', '浼樻儬', '绛惧崟', '闄愰'],
        'qa_script' => 'Q: 濡備綍鑷劧鎻愬嚭鎴愪氦锛? . "\n" .
                      'A: "浠婂ぉ鎶ュ悕鍙互浜彈XX浼樻儬锛岃€屼笖杩欎釜娲诲姩鏈懆灏辨埅姝簡銆傚鏋滄偍瑙夊緱鍚堥€傜殑璇濓紝鎴戝彲浠ュ府鎮ㄥ噯澶囧悎鍚屻€?' . "\n\n" .
                      'Q: 瀹堕暱鐘硅鲍涓嶅喅鎬庝箞鍔烇紵' . "\n" .
                      'A: "鎴戠悊瑙ｈ鍐冲畾涓€浠朵簨闇€瑕佽€冭檻銆備笉杩囪繖涓紭鎯犲悕棰濇湁闄愶紝浠婂ぉ鎶ュ悕鐨勮瘽杩樿兘璧犻€乆X浣撻獙璇俱€傛偍鐪嬫槸鍏堝畾涓嬫潵锛屽鏋滀箣鍚庢湁鍙樺寲鎴戜滑涔熷彲浠ヨ皟鏁淬€?',
        'knowledge' => '銆愰€煎崟鎴愪氦鍏鏂规硶銆? . "\n" .
                      '1. 浼樻儬闄愭椂锛氫粖澶╂姤鍚嶄韩XX鎶橈紝浠呴檺鏈湀' . "\n" .
                      '2. 闄愰閫煎崟锛氭湰鏈熷悕棰濅粎鍓X涓? . "\n" .
                      '3. 璧犲搧淇冩垚锛氭姤鍚嶅嵆閫佽繍鍔ㄨ澶?浣撻獙璇? . "\n" .
                      '4. 鍋囪鎴愪氦锛?鎮ㄦ槸鍒蜂俊鐢ㄥ崱杩樻槸寰俊锛?' . "\n" .
                      '5. 灏忕偣鎴愪氦锛氬厛瀹氫竴涓湀璇曡瘯' . "\n" .
                      '6. 瀹跺涵鎶曠エ锛氶個璇峰浜轰竴璧峰仛鍐冲畾',
        'deal_tips' => '銆愰€煎崟鎴愪氦璋堝崟瑕佺偣銆? . "\n" .
                      '1. 鎹曟崏鎴愪氦淇″彿锛氬闀块棶缁嗚妭銆侀棶浼樻儬銆佺偣澶磋鍙? . "\n" .
                      '2. 涓诲姩鎻愬嚭鎴愪氦锛氫笉瑕佺瓑瀹堕暱鑷繁璇? . "\n" .
                      '3. 浼樻儬瑕佺湡瀹烇細涓嶈铏氭瀯浼樻儬锛岀粰鐪熷疄鐨勫ソ澶? . "\n" .
                      '4. 绛惧崟瑕佸揩锛氫竴鏃︽湁鎰忓悜锛岀珛鍗充績鎴?
    ],
    [
        'step' => 6,
        'name' => '杞暀缁垂',
        'keywords' => ['杞暀', '缁垂', '瑙勫垝', '璇炬椂', '鍏崇郴'],
        'qa_script' => 'Q: 濡備綍鍋氳绋嬭鍒掞紵' . "\n" .
                      'A: "鏍规嵁瀛╁瓙鐨勬儏鍐碉紝鎴戝缓璁厛鎶X璇炬椂锛屽ぇ绾涓湀鐨勫涔犲懆鏈熴€傛垜浠細鍦?涓湀鍚庡仛绗竴娆″璇勶紝3涓湀鍚庡仛浣撴祴瀵规瘮锛屾偍鍙互鐪嬪埌鏄庢樉鐨勮繘姝ャ€?' . "\n\n" .
                      'Q: 濡備綍閭€璇蜂笅娆″埌搴楋紵' . "\n" .
                      'A: "瀛╁瓙浠婂ぉ鐨勪綋楠岄潪甯稿ソ銆備笅鍛ㄦ垜浠湁涓€鑺傚叕寮€璇撅紝XX鏁欑粌涓昏锛屾偍鍙互甯﹀瀛愭潵鍙傚姞锛屾垜甯偍棰勭害銆?"',
        'knowledge' => '銆愯浆鏁欑画璐瑰叧閿偣銆? . "\n" .
                      '1. 鍒跺畾瑙勫垝锛氭牴鎹瀛愰渶姹傚埗瀹?-3骞村涔犺鍒? . "\n" .
                      '2. 璇炬椂寤鸿锛氬憡鐭ュ悎鐞嗚鏃舵暟鍜岄璁℃晥鏋? . "\n" .
                      '3. 棰勭害涓嬫锛氱幇鍦洪绾︿笅娆″埌搴楁椂闂? . "\n" .
                      '4. 鍏崇郴缁存姢锛氬姞寰俊銆佸缓缇ゃ€佸彂閫佽鍚庡弽棣? . "\n" .
                      '5. 缁垂棰勮锛氭彁鍓?5澶╂彁閱掔画璐?,
        'deal_tips' => '銆愯浆鏁欑画璐硅皥鍗曡鐐广€? . "\n" .
                      '1. 鐜板満瑙勫垝锛氬綋鍦哄埗瀹氬涔犺鍒掞紝灞曠ず涓撲笟鎬? . "\n" .
                      '2. 鐩爣閿佸畾锛氳瀛╁瓙鏈夋槑纭殑瀛︿範鐩爣' . "\n" .
                      '3. 鎯呮劅璐︽埛锛氬浜掑姩銆佸鍏冲績銆佷笉鍙槸鍗栬' . "\n" .
                      '4. 缁垂婵€鍔憋細鎻愬墠閫氱煡浼樻儬锛屽煿鍏诲繝璇氬害'
    ],
    [
        'step' => 7,
        'name' => '涓诲姩杞粙缁?,
        'keywords' => ['杞粙缁?, '鍙ｇ', '鎺ㄨ崘', '璧勬簮', '瑁傚彉'],
        'qa_script' => 'Q: 濡備綍璇锋眰杞粙缁嶏紵' . "\n" .
                      'A: "闈炲父鎰熻阿鎮ㄩ€夋嫨杩藉厜灏忕墰锛佹偍鐨勫瀛愬湪鎴戜滑杩欓噷璁粌鏁堟灉濂界殑璇濓紝甯屾湜鎮ㄨ兘鎺ㄨ崘缁欒韩杈规湁闇€瑕佺殑鏈嬪弸銆傛垜浠湁鑰佸甫鏂板鍔憋紝鎮ㄦ帹鑽愪竴浣嶅闀挎姤鍚嶏紝鍙屾柟閮借兘鑾峰緱XX濂栧姳銆?' . "\n\n" .
                      'Q: 瀹堕暱璇?鎴戞病鏈夋湅鍙嬮渶瑕?鎬庝箞鍔烇紵' . "\n" .
                      'A: "娌″叧绯伙紝浠ュ悗濡傛灉鏈夋湅鍙嬫兂浜嗚В杩愬姩鏂归潰鐨勪簨鎯咃紝鍙互鍔犳垜寰俊锛屾湁浠€涔堥棶棰橀殢鏃堕棶鎴戙€傛垜浠篃浼氫笉瀹氭湡涓惧姙瀹堕暱娌欓緳娲诲姩锛屽埌鏃跺€欓個璇锋偍鍙傚姞銆?',
        'knowledge' => '銆愪富鍔ㄨ浆浠嬬粛鍥涚鏂瑰紡銆? . "\n" .
                      '1. 鑰佸甫鏂板鍔憋細鎺ㄨ崘鎶ュ悕鍚勫緱XX浼樻儬' . "\n" .
                      '2. 鏈嬪弸鍦堝垎浜細鍒嗕韩瀛╁瓙杩愬姩鐓х墖/瑙嗛' . "\n" .
                      '3. 鍙ｇ浼犳挱锛氳瀹堕暱涓诲姩鍚戞湅鍙嬫帹鑽? . "\n" .
                      '4. 璧勬簮鏀堕泦锛氭敹闆嗗闀胯仈绯绘柟寮忎究浜庡悗缁窡杩?,
        'deal_tips' => '銆愪富鍔ㄨ浆浠嬬粛璋堝崟瑕佺偣銆? . "\n" .
                      '1. 鏈€浣虫椂鏈猴細瀹堕暱绛惧崟婊℃剰鏃惰姹傝浆浠嬬粛' . "\n" .
                      '2. 缁欏嚭鐞嗙敱锛氳瀹堕暱鐭ラ亾鎺ㄨ崘瀵逛粬鏈嬪弸涔熸湁浠峰€? . "\n" .
                      '3. 绠€鍖栨祦绋嬶細鎻愪緵鑱旂郴鏂瑰紡锛岃瀹堕暱杞绘澗鎺ㄨ崘' . "\n" .
                      '4. 鎯呮劅璐︽埛锛氭棩甯哥淮鎶わ紝璁╁闀挎効鎰忓府浣犳帹鑽?
    ]
];

// 1. 瀵煎叆鍒拌瘽鏈煡璇嗗簱 - qa缁村害
echo "=== 瀵煎叆璇濇湳鐭ヨ瘑搴擄紙qa缁村害锛?==\n";
$dim_qa = 1;
$sort_order = 100;

foreach ($sevenSteps as $step) {
    $scene_code = 'seven-steps-step' . $step['step'];

    // 妫€鏌ユ槸鍚﹀瓨鍦?
    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$scene_code]);
    $existing = $stmt->fetch();

    $keywords = json_encode($step['keywords']);

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            '閿€鍞竷姝ユ洸-' . $step['name'],
            $keywords,
            $step['qa_script'],
            '鎺屾彙' . $step['name'] . '鐨勬爣鍑嗛棶绛旇瘽鏈?,
            $scene_code
        ]);
        echo "Updated (qa): 閿€鍞竷姝ユ洸-{$step['name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_qa,
            $scene_code,
            '閿€鍞竷姝ユ洸-' . $step['name'],
            $keywords,
            $step['qa_script'],
            '鎺屾彙' . $step['name'] . '鐨勬爣鍑嗛棶绛旇瘽鏈?,
            $sort_order++
        ]);
        echo "Inserted (qa): 閿€鍞竷姝ユ洸-{$step['name']}\n";
    }
}

// 2. 瀵煎叆鍒拌瘽鏈煡璇嗗簱 - knowledge缁村害
echo "\n=== 瀵煎叆璇濇湳鐭ヨ瘑搴擄紙knowledge缁村害锛?==\n";
$dim_knowledge = 2;
$sort_order = 200;

foreach ($sevenSteps as $step) {
    $scene_code = 'seven-steps-knowledge-' . $step['step'];

    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$scene_code]);
    $existing = $stmt->fetch();

    $keywords = array_map(function($k) { return $k . ',閿€鍞竷姝ユ洸'; }, $step['keywords']);

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            '閿€鍞竷姝ユ洸鐭ヨ瘑鐐?' . $step['name'],
            json_encode($keywords),
            $step['knowledge'],
            '鐞嗚В' . $step['name'] . '鐨勪笓涓氱煡璇嗙偣',
            $scene_code
        ]);
        echo "Updated (knowledge): 閿€鍞竷姝ユ洸鐭ヨ瘑鐐?{$step['name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_knowledge,
            $scene_code,
            '閿€鍞竷姝ユ洸鐭ヨ瘑鐐?' . $step['name'],
            json_encode($keywords),
            $step['knowledge'],
            '鐞嗚В' . $step['name'] . '鐨勪笓涓氱煡璇嗙偣',
            $sort_order++
        ]);
        echo "Inserted (knowledge): 閿€鍞竷姝ユ洸鐭ヨ瘑鐐?{$step['name']}\n";
    }
}

// 3. 瀵煎叆鍒拌瘽鏈煡璇嗗簱 - deal缁村害
echo "\n=== 瀵煎叆璇濇湳鐭ヨ瘑搴擄紙deal缁村害锛?==\n";
$dim_deal = 4;
$sort_order = 100;

foreach ($sevenSteps as $step) {
    $scene_code = 'seven-steps-deal-' . $step['step'];

    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$scene_code]);
    $existing = $stmt->fetch();

    $keywords = array_map(function($k) { return $k . ',璋堝崟'; }, $step['keywords']);

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            '閿€鍞竷姝ユ洸璋堝崟鎶€宸?' . $step['name'],
            json_encode($keywords),
            $step['deal_tips'],
            '鎺屾彙' . $step['name'] . '鐨勮皥鍗曟妧宸?,
            $scene_code
        ]);
        echo "Updated (deal): 閿€鍞竷姝ユ洸璋堝崟鎶€宸?{$step['name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_deal,
            $scene_code,
            '閿€鍞竷姝ユ洸璋堝崟鎶€宸?' . $step['name'],
            json_encode($keywords),
            $step['deal_tips'],
            '鎺屾彙' . $step['name'] . '鐨勮皥鍗曟妧宸?,
            $sort_order++
        ]);
        echo "Inserted (deal): 閿€鍞竷姝ユ洸璋堝崟鎶€宸?{$step['name']}\n";
    }
}

// 4. 瀵煎叆鍒板煿璁崱鐗?- 鍒涘缓閿€鍞竷姝ユ洸妯″潡鎴栬瀺鍏ョ幇鏈夋ā鍧?
echo "\n=== 瀵煎叆鍩硅鍗＄墖 ===\n";

$trainingCards = [
    // 铻嶅叆瀹堕暱娌熼€氳瘽鏈ā鍧?mod-communication=5)
    [
        'module_id' => 5,
        'card_type' => 'K',
        'card_code' => 'seven-steps-overview',
        'title' => '閿€鍞竷姝ユ洸姒傝堪',
        'content' => '閿€鍞竷姝ユ洸鏄拷鍏夊皬鐗涚殑鏍囧噯閿€鍞祦绋嬶細' . "\n" .
                         '1. 鏆栧満鐮村啺 - 寤虹珛淇′换' . "\n" .
                         '2. 闇€姹傛寲鎺?- 浜嗚В鐪熷疄闇€姹? . "\n" .
                         '3. 浜у搧浠嬬粛 - 灞曠ず鍝佺墝浠峰€? . "\n" .
                         '4. 寮傝澶勭悊 - 瑙ｇ瓟瀹堕暱鐤戣檻' . "\n" .
                         '5. 閫煎崟鎴愪氦 - 淇冩垚绛惧崟' . "\n" .
                         '6. 杞暀缁垂 - 鏈嶅姟缁垂' . "\n" .
                         '7. 涓诲姩杞粙缁?- 鍙ｇ瑁傚彉',
        'tips' => '閿€鍞祦绋?涓冩鏇?鏍囧噯璇濇湳'
    ],
    [
        'module_id' => 5,
        'card_type' => 'S',
        'card_code' => 'seven-steps-warmup',
        'title' => '閿€鍞竷姝ユ洸璇濇湳鍗?鏆栧満鐮村啺',
        'content' => '銆愭殩鍦虹牬鍐版爣鍑嗚瘽鏈€? . "\n" .
                         '寮€鍦虹櫧锛?鎮ㄥソ锛佹垜鏄拷鍏夊皬鐗涚殑XX鏁欑粌锛岄潪甯搁珮鍏磋璇嗘偍鍜屽疂璐濓紒"' . "\n" .
                         '鑾峰彇淇℃伅锛?璇烽棶鎬庝箞绉板懠鎮ㄥ憿锛熷瀛愬彨浠€涔堝悕瀛楋紵浠婂勾澶氬ぇ浜嗭紵"',
        'tips' => '鏆栧満,鐮村啺,璇濇湳'
    ],
    [
        'module_id' => 5,
        'card_type' => 'S',
        'card_code' => 'seven-steps-needs',
        'title' => '閿€鍞竷姝ユ洸璇濇湳鍗?闇€姹傛寲鎺?,
        'content' => '銆愰渶姹傛寲鎺樻爣鍑嗚瘽鏈€? . "\n" .
                         '"鎮ㄥ笇鏈涘瀛愰€氳繃杩愬姩鏀瑰杽鍝柟闈㈠憿锛熸槸浣撹川銆佷笓娉ㄥ姏锛岃繕鏄兂璁╁瀛愬杩愬姩銆佸皯鐢熺梾锛?"' . "\n" .
                         '杩介棶锛?瀛╁瓙骞虫椂鏈変粈涔堝叴瓒ｇ埍濂斤紵涔嬪墠鏈夋姤杩囧叾浠栬繍鍔ㄨ绋嬪悧锛?',
        'tips' => '闇€姹?鎸栨帢,璇濇湳'
    ],
    [
        'module_id' => 5,
        'card_type' => 'D',
        'card_code' => 'seven-steps-objection',
        'title' => '閿€鍞竷姝ユ洸婕旂粌鍗?寮傝澶勭悊',
        'content' => '銆愬紓璁鐞嗗洓姝ユ硶婕旂粌銆? . "\n" .
                         '1. 璁ゅ悓锛?鎴戠悊瑙ｆ偍鐨勬兂娉?' . "\n" .
                         '2. 鎻愰棶锛?鎮ㄤ富瑕佹槸鎷呭績浠€涔堬紵"' . "\n" .
                         '3. 瑙ｇ瓟锛氶拡瀵规€цВ鍐? . "\n" .
                         '4. 寮曞锛氬洖褰掕绋嬩环鍊? . "\n\n" .
                         '銆愬父瑙佸紓璁簲瀵广€? . "\n" .
                         '- 浠锋牸璐碉細鎷嗚В鎴愭湰+寮鸿皟浠峰€? . "\n" .
                         '- 娌℃椂闂达細鏁堢巼+涔犳儻鍏绘垚' . "\n" .
                         '- 鏁堟灉涓嶆槑鏄撅細鏁版嵁+妗堜緥灞曠ず',
        'tips' => '寮傝澶勭悊,婕旂粌,搴斿'
    ],
    [
        'module_id' => 5,
        'card_type' => 'S',
        'card_code' => 'seven-steps-close',
        'title' => '閿€鍞竷姝ユ洸璇濇湳鍗?閫煎崟鎴愪氦',
        'content' => '銆愰€煎崟鎴愪氦鍏鏂规硶銆? . "\n" .
                         '1. 浼樻儬闄愭椂锛?浠婂ぉ鎶ュ悕浜玐X鎶?' . "\n" .
                         '2. 闄愰閫煎崟锛?鍚嶉浠呭墿XX涓?' . "\n" .
                         '3. 璧犲搧淇冩垚锛?鎶ュ悕鍗抽€乆X"' . "\n" .
                         '4. 鍋囪鎴愪氦锛?鎮ㄦ槸鍒蜂俊鐢ㄥ崱杩樻槸寰俊锛?' . "\n" .
                         '5. 灏忕偣鎴愪氦锛?鍏堝畾涓€涓湀璇曡瘯"' . "\n" .
                         '6. 瀹跺涵鎶曠エ锛?閭€璇峰浜轰竴璧峰喅瀹?',
        'tips' => '閫煎崟,鎴愪氦,璇濇湳'
    ],
    // 铻嶅叆缁垂杞粙缁嶆ā鍧?mod-renewal=6)
    [
        'module_id' => 6,
        'card_type' => 'K',
        'card_code' => 'seven-steps-renewal',
        'title' => '閿€鍞竷姝ユ洸鐭ヨ瘑鐐?杞暀缁垂',
        'content' => '銆愯浆鏁欑画璐瑰叧閿偣銆? . "\n" .
                         '1. 鍒跺畾瑙勫垝锛氭牴鎹渶姹傚埗瀹?-3骞村涔犺鍒? . "\n" .
                         '2. 璇炬椂寤鸿锛氬悎鐞嗚鏃舵暟鍜岄璁℃晥鏋? . "\n" .
                         '3. 棰勭害涓嬫锛氱幇鍦洪绾︿笅娆″埌搴? . "\n" .
                         '4. 鍏崇郴缁存姢锛氬姞寰俊銆佸缓缇ゃ€佽鍚庡弽棣? . "\n" .
                         '5. 缁垂棰勮锛氭彁鍓?5澶╂彁閱?,
        'tips' => '杞暀,缁垂,鐭ヨ瘑鐐?
    ],
    [
        'module_id' => 6,
        'card_type' => 'S',
        'card_code' => 'seven-steps-referral',
        'title' => '閿€鍞竷姝ユ洸璇濇湳鍗?涓诲姩杞粙缁?,
        'content' => '銆愯姹傝浆浠嬬粛鏍囧噯璇濇湳銆? . "\n" .
                         '"闈炲父鎰熻阿鎮ㄩ€夋嫨杩藉厜灏忕墰锛佹偍鐨勫瀛愬湪鎴戜滑杩欓噷璁粌鏁堟灉濂界殑璇濓紝甯屾湜鎮ㄨ兘鎺ㄨ崘缁欒韩杈规湁闇€瑕佺殑鏈嬪弸銆傛垜浠湁鑰佸甫鏂板鍔憋紝鎮ㄦ帹鑽愪竴浣嶅闀挎姤鍚嶏紝鍙屾柟閮借兘鑾峰緱XX濂栧姳銆?',
        'tips' => '杞粙缁?璇濇湳,鑰佸甫鏂?
    ],
    [
        'module_id' => 6,
        'card_type' => 'C',
        'card_code' => 'seven-steps-exam',
        'title' => '閿€鍞竷姝ユ洸閫氬叧鍗?鏁翠綋娴佺▼',
        'content' => '銆愰攢鍞竷姝ユ洸閫氬叧鑰冩牳銆? . "\n" .
                         '1. 鑳藉畬鏁磋鍑洪攢鍞竷姝ユ洸鍚嶇О' . "\n" .
                         '2. 鑳介拡瀵规瘡涓楠よ鍑哄叧閿姩浣? . "\n" .
                         '3. 鑳芥ā鎷熸紨缁冩殩鍦虹牬鍐板埌閫煎崟鎴愪氦' . "\n" .
                         '4. 鑳藉鐞?绉嶄互涓婂父瑙佸紓璁? . "\n" .
                         '5. 鑳借鍑鸿浆浠嬬粛鐨勮€佸甫鏂版斂绛?,
        'tips' => '鑰冩牳,閫氬叧,涓冩鏇?
    ]
];

foreach ($trainingCards as $card) {
    // 妫€鏌ユ槸鍚﹀凡瀛樺湪
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
    echo "妯″潡{$row['module_id']}: {$row['count']}寮犲崱鐗嘰n";
}
