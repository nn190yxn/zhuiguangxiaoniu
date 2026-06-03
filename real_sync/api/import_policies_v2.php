<?php
/**
 * 鍒跺害鏁版嵁瀵煎叆鑴氭湰 v2
 * 灏嗕綋绯绘枃浠跺鍏ュ埌 policies 琛?
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

$basePath = '/www/wwwroot/122.51.223.46/浣撶郴鏂囦欢_鏈€缁堢増/';

$policies = [
    // 01_闂ㄥ簵杩愯惀鏍囧噯浣撶郴
    ['file' => '01_闂ㄥ簵杩愯惀鏍囧噯浣撶郴/01A_寮€鍏冲簵SOP.md', 'key' => '01a-switch-store', 'title' => '寮€鍏冲簵SOP', 'category' => '闂ㄥ簵杩愯惀', 'keywords' => '寮€鍏冲簵,SOP,鏍囧噯娴佺▼'],
    ['file' => '01_闂ㄥ簵杩愯惀鏍囧噯浣撶郴/01B_璇惧墠璇句腑璇惧悗娴佺▼.md', 'key' => '01b-class-flow', 'title' => '璇惧墠璇句腑璇惧悗娴佺▼', 'category' => '闂ㄥ簵杩愯惀', 'keywords' => '璇剧▼,娴佺▼,璇惧墠,璇句腑,璇惧悗'],
    ['file' => '01_闂ㄥ簵杩愯惀鏍囧噯浣撶郴/01C_鍗敓涓庡畨鍏ㄦ爣鍑?md', 'key' => '01c-hygiene-safety', 'title' => '鍗敓涓庡畨鍏ㄦ爣鍑?, 'category' => '闂ㄥ簵杩愯惀', 'keywords' => '鍗敓,瀹夊叏,鏍囧噯'],
    ['file' => '01_闂ㄥ簵杩愯惀鏍囧噯浣撶郴/01D_璁惧涓庣墿鏂欑鐞?md', 'key' => '01d-equipment', 'title' => '璁惧涓庣墿鏂欑鐞?, 'category' => '闂ㄥ簵杩愯惀', 'keywords' => '璁惧,鐗╂枡,绠＄悊'],
    ['file' => '01_闂ㄥ簵杩愯惀鏍囧噯浣撶郴/01E_绐佸彂浜嬩欢搴旀€ュ鐞?md', 'key' => '01e-emergency', 'title' => '绐佸彂浜嬩欢搴旀€ュ鐞?, 'category' => '闂ㄥ簵杩愯惀', 'keywords' => '绐佸彂,搴旀€?澶勭悊'],
    ['file' => '01_闂ㄥ簵杩愯惀鏍囧噯浣撶郴/01F_鍚堝悓绠＄悊缁熶竴瑙勮寖.md', 'key' => '01f-contract', 'title' => '鍚堝悓绠＄悊缁熶竴瑙勮寖', 'category' => '闂ㄥ簵杩愯惀', 'keywords' => '鍚堝悓,绠＄悊,瑙勮寖'],
    ['file' => '01_闂ㄥ簵杩愯惀鏍囧噯浣撶郴/01G_浣撴祴宸ヤ綔娴佺▼涓庢爣鍑?md', 'key' => '01g-assessment', 'title' => '浣撴祴宸ヤ綔娴佺▼涓庢爣鍑?, 'category' => '闂ㄥ簵杩愯惀', 'keywords' => '浣撴祴,璇勪及,娴佺▼'],

    // 02_浜哄憳绠＄悊浣撶郴
    ['file' => '02_浜哄憳绠＄悊浣撶郴/02A_宀椾綅璇存槑涔?md', 'key' => '02a-job-desc', 'title' => '宀椾綅璇存槑涔?, 'category' => '浜哄憳绠＄悊', 'keywords' => '宀椾綅,鑱岃矗,璇存槑'],
    ['file' => '02_浜哄憳绠＄悊浣撶郴/02B_鎷涜仒娴佺▼涓庢爣鍑?md', 'key' => '02b-recruitment', 'title' => '鎷涜仒娴佺▼涓庢爣鍑?, 'category' => '浜哄憳绠＄悊', 'keywords' => '鎷涜仒,娴佺▼,鏍囧噯'],
    ['file' => '02_浜哄憳绠＄悊浣撶郴/02C_鏂板憳宸ュ叆鑱屽煿璁?md', 'key' => '02c-onboarding', 'title' => '鏂板憳宸ュ叆鑱屽煿璁?, 'category' => '浜哄憳绠＄悊', 'keywords' => '鍏ヨ亴,鍩硅,鏂板憳宸?],
    ['file' => '02_浜哄憳绠＄悊浣撶郴/02D_鏁欑粌鏄熺骇鏅嬪崌浣撶郴.md', 'key' => '02d-promotion', 'title' => '鏁欑粌鏄熺骇鏅嬪崌浣撶郴', 'category' => '浜哄憳绠＄悊', 'keywords' => '鏅嬪崌,鏄熺骇,鏁欑粌'],
    ['file' => '02_浜哄憳绠＄悊浣撶郴/02E_钖叕缁撴瀯.md', 'key' => '02e-compensation', 'title' => '钖叕缁撴瀯', 'category' => '浜哄憳绠＄悊', 'keywords' => '钖叕,宸ヨ祫,绂忓埄'],
    ['file' => '02_浜哄憳绠＄悊浣撶郴/02F_绂昏亴绠＄悊.md', 'key' => '02f-resignation', 'title' => '绂昏亴绠＄悊', 'category' => '浜哄憳绠＄悊', 'keywords' => '绂昏亴,绠＄悊'],
    ['file' => '02_浜哄憳绠＄悊浣撶郴/02G_宸ヤ綔閲忕鐞嗘爣鍑?md', 'key' => '02g-workload', 'title' => '宸ヤ綔閲忕鐞嗘爣鍑?, 'category' => '浜哄憳绠＄悊', 'keywords' => '宸ヤ綔閲?绠＄悊'],

    // 03_搴楅暱绠＄悊鏈哄埗
    ['file' => '03_搴楅暱绠＄悊鏈哄埗/03A_搴楅暱浼氳绠＄悊浣撶郴.md', 'key' => '03a-meeting', 'title' => '搴楅暱浼氳绠＄悊浣撶郴', 'category' => '搴楅暱绠＄悊', 'keywords' => '浼氳,搴楅暱,绠＄悊'],
    ['file' => '03_搴楅暱绠＄悊鏈哄埗/03B_搴楅暱鏁版嵁绠＄悊浣撶郴.md', 'key' => '03b-data', 'title' => '搴楅暱鏁版嵁绠＄悊浣撶郴', 'category' => '搴楅暱绠＄悊', 'keywords' => '鏁版嵁,绠＄悊,浣撶郴'],
    ['file' => '03_搴楅暱绠＄悊鏈哄埗/03C_搴楅暱鏃ュ懆鏈堝伐浣滄祦.md', 'key' => '03c-workflow', 'title' => '搴楅暱鏃ュ懆鏈堝伐浣滄祦', 'category' => '搴楅暱绠＄悊', 'keywords' => '宸ヤ綔娴?鏃ュ懆鏈?],
    ['file' => '03_搴楅暱绠＄悊鏈哄埗/03D_搴楅暱宸″簵妫€鏌ヤ綋绯?md', 'key' => '03d-inspection', 'title' => '搴楅暱宸″簵妫€鏌ヤ綋绯?, 'category' => '搴楅暱绠＄悊', 'keywords' => '宸″簵,妫€鏌?],
    ['file' => '03_搴楅暱绠＄悊鏈哄埗/03E_搴楅暱甯甫涓庡府鎵朵綋绯?md', 'key' => '03e-mentoring', 'title' => '搴楅暱甯甫涓庡府鎵朵綋绯?, 'category' => '搴楅暱绠＄悊', 'keywords' => '甯甫,甯壎,搴楅暱'],
    ['file' => '03_搴楅暱绠＄悊鏈哄埗/03F_搴楅暱鑷垜鎴愰暱宸ュ叿.md', 'key' => '03f-growth', 'title' => '搴楅暱鑷垜鎴愰暱宸ュ叿', 'category' => '搴楅暱绠＄悊', 'keywords' => '鎴愰暱,鑷垜,搴楅暱'],
    ['file' => '03_搴楅暱绠＄悊鏈哄埗/03G_鐫ｅ鑰冩牳鏍囧噯.md', 'key' => '03g-supervisor', 'title' => '鐫ｅ鑰冩牳鏍囧噯', 'category' => '搴楅暱绠＄悊', 'keywords' => '鐫ｅ,鑰冩牳'],
    ['file' => '03_搴楅暱绠＄悊鏈哄埗/03H_搴楅暱缁忚惀闂幆鎬诲垯.md', 'key' => '03h-closed-loop', 'title' => '搴楅暱缁忚惀闂幆鎬诲垯', 'category' => '搴楅暱绠＄悊', 'keywords' => '缁忚惀,闂幆'],

    // 04_鏈嶅姟鏍囧噯浣撶郴
    ['file' => '04_鏈嶅姟鏍囧噯浣撶郴/04A_棣栨鍒板簵鎺ュ緟鏍囧噯.md', 'key' => '04a-reception', 'title' => '棣栨鍒板簵鎺ュ緟鏍囧噯', 'category' => '鏈嶅姟鏍囧噯', 'keywords' => '鎺ュ緟,鍒板簵,鏈嶅姟'],
    ['file' => '04_鏈嶅姟鏍囧噯浣撶郴/04B_瀹堕暱娌熼€氳瘽鏈爣鍑?md', 'key' => '04b-communication', 'title' => '瀹堕暱娌熼€氳瘽鏈爣鍑?, 'category' => '鏈嶅姟鏍囧噯', 'keywords' => '娌熼€?璇濇湳,瀹堕暱'],
    ['file' => '04_鏈嶅姟鏍囧噯浣撶郴/04C_缁垂瑙﹁揪涓庤窡杩?md', 'key' => '04c-renewal', 'title' => '缁垂瑙﹁揪涓庤窡杩?, 'category' => '鏈嶅姟鏍囧噯', 'keywords' => '缁垂,瑙﹁揪,璺熻繘'],
    ['file' => '04_鏈嶅姟鏍囧噯浣撶郴/04D_鎶曡瘔澶勭悊娴佺▼.md', 'key' => '04d-complaint', 'title' => '鎶曡瘔澶勭悊娴佺▼', 'category' => '鏈嶅姟鏍囧噯', 'keywords' => '鎶曡瘔,澶勭悊,娴佺▼'],
    ['file' => '04_鏈嶅姟鏍囧噯浣撶郴/04E_浼氬憳棣栨湀鏈嶅姟璺熻繘鏍囧噯.md', 'key' => '04e-first-month', 'title' => '浼氬憳棣栨湀鏈嶅姟璺熻繘鏍囧噯', 'category' => '鏈嶅姟鏍囧噯', 'keywords' => '棣栨湀,璺熻繘,鏈嶅姟'],
    ['file' => '04_鏈嶅姟鏍囧噯浣撶郴/04F_浼氬憳鏈嶅姟涓庣画璐逛富閾捐矾鎬诲垯.md', 'key' => '04f-member-service', 'title' => '浼氬憳鏈嶅姟涓庣画璐逛富閾捐矾鎬诲垯', 'category' => '鏈嶅姟鏍囧噯', 'keywords' => '浼氬憳,缁垂,鏈嶅姟'],

    // 05_鏁欏鏍囧噯浣撶郴
    ['file' => '05_鏁欏鏍囧噯浣撶郴/05A_ACE钀藉湴鎵ц鏍囧噯.md', 'key' => '05a-ace', 'title' => 'ACE钀藉湴鎵ц鏍囧噯', 'category' => '鏁欏鏍囧噯', 'keywords' => 'ACE,鏁欏,鎵ц'],
    ['file' => '05_鏁欏鏍囧噯浣撶郴/05B_鍚勮绋嬫暀瀛OP.md', 'key' => '05b-course-sop', 'title' => '鍚勮绋嬫暀瀛OP', 'category' => '鏁欏鏍囧噯', 'keywords' => '璇剧▼,SOP,鏁欏'],
    ['file' => '05_鏁欏鏍囧噯浣撶郴/05C_瀛﹀憳鍗囩彮鑰冩牳鏍囧噯.md', 'key' => '05c-promotion', 'title' => '瀛﹀憳鍗囩彮鑰冩牳鏍囧噯', 'category' => '鏁欏鏍囧噯', 'keywords' => '鍗囩彮,鑰冩牳,瀛﹀憳'],

    // 06_涓氱哗绠＄悊浣撶郴
    ['file' => '06_涓氱哗绠＄悊浣撶郴/06A_鐩爣鍒嗚В涓嶬DI鎸囨爣.md', 'key' => '06a-kdi', 'title' => '鐩爣鍒嗚В涓嶬DI鎸囨爣', 'category' => '涓氱哗绠＄悊', 'keywords' => '鐩爣,KDI,鎸囨爣'],
    ['file' => '06_涓氱哗绠＄悊浣撶郴/06B_婵€鍔辨柟妗?md', 'key' => '06b-incentive', 'title' => '婵€鍔辨柟妗?, 'category' => '涓氱哗绠＄悊', 'keywords' => '婵€鍔?鏂规'],
    ['file' => '06_涓氱哗绠＄悊浣撶郴/06C_鍏抽敭鑺傜偣钀ラ攢.md', 'key' => '06c-marketing', 'title' => '鍏抽敭鑺傜偣钀ラ攢', 'category' => '涓氱哗绠＄悊', 'keywords' => '钀ラ攢,鑺傜偣'],

    // 鍏朵粬
    ['file' => '00_鎴愰暱鍩洪噾绠＄悊鍔炴硶.md', 'key' => 'growth-fund', 'title' => '鎴愰暱鍩洪噾绠＄悊鍔炴硶', 'category' => '閫氱敤', 'keywords' => '鍩洪噾,鎴愰暱'],
    ['file' => '00A_鍏ㄤ綋绯荤粺涓€鍘熷垯.md', 'key' => 'unified-principles', 'title' => '鍏ㄤ綋绯荤粺涓€鍘熷垯', 'category' => '鎬荤翰', 'keywords' => '鍘熷垯,缁熶竴,瑙勮寖'],
    ['file' => '00_杩藉厜灏忕墰杩為攣杩愯惀浣撶郴_鎬荤翰.md', 'key' => 'system-outline', 'title' => '杩藉厜灏忕墰杩為攣杩愯惀浣撶郴鎬荤翰', 'category' => '鎬荤翰', 'keywords' => '浣撶郴,鎬荤翰,杩愯惀'],
    ['file' => '07_鍝佺墝涓€鑷存€ф爣鍑?md', 'key' => 'brand-consistency', 'title' => '鍝佺墝涓€鑷存€ф爣鍑?, 'category' => '鍝佺墝', 'keywords' => '鍝佺墝,涓€鑷?鏍囧噯'],
    ['file' => '08_浣撶郴鎺ㄨ繘璁″垝.md', 'key' => 'implementation-plan', 'title' => '浣撶郴鎺ㄨ繘璁″垝', 'category' => '鎬荤翰', 'keywords' => '鎺ㄨ繘,璁″垝'],
];

$imported = 0;
$skipped = 0;

foreach ($policies as $policy) {
    $filePath = $basePath . $policy['file'];

    if (!file_exists($filePath)) {
        echo "Not found: {$filePath}\n";
        continue;
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        echo "Read failed: {$filePath}\n";
        continue;
    }

    // Check if exists
    $stmt = $db->prepare("SELECT id FROM policies WHERE doc_key = ?");
    $stmt->execute([$policy['key']]);
    if ($stmt->fetch()) {
        $stmt = $db->prepare("UPDATE policies SET title = ?, content = ?, category = ?, keywords = ?, updated_at = NOW() WHERE doc_key = ?");
        $stmt->execute([
            $policy['title'],
            $content,
            $policy['category'],
            $policy['keywords'],
            $policy['key']
        ]);
        echo "Updated: {$policy['title']}\n";
    } else {
        $stmt = $db->prepare("INSERT INTO policies (doc_key, title, content, category, keywords, is_need_confirm) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $policy['key'],
            $policy['title'],
            $content,
            $policy['category'],
            $policy['keywords']
        ]);
        echo "Imported: {$policy['title']}\n";
    }
    $imported++;
}

echo "\nDone! Total: " . $imported . " policies processed.\n";
