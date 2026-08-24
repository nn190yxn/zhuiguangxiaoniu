<?php
/** Safe, idempotent sales training-card import/rollback CLI (PHP 7.4). */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Forbidden\n"); }
require __DIR__ . '/../api/config.php';

const SALES_LOCK = 'supercalf_sales_training_cards_v1';
const PACKAGE_VERSION = 'sales-training-cards.v1';
const MANIFEST_VERSION = 'sales-training-cards-manifest.v1';
const SIMILARITY_THRESHOLD = 0.80;
const MODULE_FIELDS = ['module_name','description','level','role_code','category','required_score','total_cards','status'];
const CARD_FIELDS = ['card_type','title','content','options','standard_answer','tips','difficulty','score','sort_order','status'];

function failCli($message) { throw new RuntimeException($message); }
function usage() { return "Usage:\n  import <json> --sha256 HASH [--report PATH] [--apply --backup-dir DIR --manifest PATH] [--allow-update] [--ack-manual-review]\n  rollback <json> --sha256 HASH --manifest PATH [--report PATH] [--apply --backup-dir DIR]\n"; }
function parseArgs(array $argv) {
    if (count($argv) < 3 || !in_array($argv[1], ['import','rollback'], true)) failCli(usage());
    $out = ['command'=>$argv[1], 'json'=>$argv[2], 'apply'=>false, 'allow_update'=>false, 'ack_manual_review'=>false];
    if ($argv[2] === '' || substr($argv[2], 0, 2) === '--') failCli('JSON path is required');
    $valueFlags = ['--sha256'=>'sha256','--report'=>'report','--backup-dir'=>'backup_dir','--manifest'=>'manifest'];
    $boolFlags = ['--apply'=>'apply','--allow-update'=>'allow_update','--ack-manual-review'=>'ack_manual_review'];
    $seen = [];
    for ($i=3; $i<count($argv); $i++) {
        $arg=$argv[$i];
        if (isset($seen[$arg])) failCli('Duplicate argument: '.$arg);
        $seen[$arg]=true;
        if (isset($boolFlags[$arg])) { $out[$boolFlags[$arg]]=true; continue; }
        if (!isset($valueFlags[$arg])) failCli('Unknown argument: '.$arg);
        if (!isset($argv[$i+1]) || $argv[$i+1]==='' || substr($argv[$i+1],0,2)==='--') failCli('Missing value: '.$arg);
        $out[$valueFlags[$arg]]=$argv[++$i];
    }
    if (!isset($out['sha256']) || !preg_match('/^[0-9a-f]{64}$/D', $out['sha256'])) failCli('SHA-256 must be exactly 64 lowercase hexadecimal characters');
    if ($out['allow_update'] && ($out['command']!=='import' || !$out['apply'])) failCli('--allow-update requires import --apply');
    if ($out['ack_manual_review'] && ($out['command']!=='import' || !$out['apply'])) failCli('--ack-manual-review requires import --apply');
    if ($out['apply'] && (!isset($out['backup_dir']) || !isset($out['manifest']))) failCli('Import apply requires --backup-dir and --manifest');
    if ($out['command']==='rollback' && !isset($out['manifest'])) failCli('Rollback requires --manifest');
    if ($out['command']==='rollback' && $out['apply'] && !isset($out['backup_dir'])) failCli('Rollback apply requires --backup-dir');
    return $out;
}
function readPackage($path, $sha) {
    if (!is_file($path) || !is_readable($path)) failCli('Package is not a readable file');
    $actual=hash_file('sha256',$path); if (!hash_equals($sha,$actual)) failCli('Package SHA-256 mismatch');
    $raw=file_get_contents($path); $p=json_decode($raw,true);
    if (!is_array($p) || json_last_error()!==JSON_ERROR_NONE) failCli('Invalid package JSON');
    $keys=['schema_version','generator_version','source_range','counts','modules','cards'];
    if (array_keys($p)!==$keys) failCli('Package top-level keys/order do not match contract');
    if ($p['schema_version']!==PACKAGE_VERSION) failCli('Unsupported package version');
    if (!is_string($p['generator_version']) || !preg_match('/^\d+\.\d+\.\d+$/D',$p['generator_version'])) failCli('Invalid generator version');
    if ($p['source_range']!==['first'=>'SALES-0001','last'=>'SALES-0075']) failCli('Invalid source range');
    if ($p['counts']!==['source_cards'=>75,'modules'=>3,'cards'=>300,'K'=>75,'S'=>75,'D'=>75,'C'=>75]) failCli('Invalid declared counts');
    if (!is_array($p['modules']) || count($p['modules'])!==3 || !is_array($p['cards']) || count($p['cards'])!==300) failCli('Invalid actual counts');
    validatePackageRows($p); return $p;
}
function exactKeys($row,$keys,$what) { if (!is_array($row) || array_keys($row)!==$keys) failCli($what.' keys/order invalid'); }
function validText($v,$max,$nullable=false) { return ($nullable && $v===null) || (is_string($v) && strlen($v)>0 && mb_strlen($v,'UTF-8')<=$max); }
function validatePackageRows($p) {
    $moduleCodes=[]; $expected=[
      'sales-ability-foundation'=>['beginner',100,'easy'], 'sales-ability-advanced'=>['intermediate',84,'medium'], 'sales-ability-expert'=>['advanced',116,'hard']];
    foreach ($p['modules'] as $m) {
        exactKeys($m,['module_code','module_name','description','level','role_code','category','required_score','total_cards','status'],'Module');
        if (!is_string($m['module_code']) || !isset($expected[$m['module_code']]) || isset($moduleCodes[$m['module_code']])) failCli('Invalid/duplicate module_code');
        $moduleCodes[$m['module_code']]=true;
        if (!validText($m['module_name'],100)||!validText($m['description'],1000)||!validText($m['category'],50)) failCli('Invalid module text/length');
        if ($m['level']!==$expected[$m['module_code']][0] || $m['role_code']!=='consultant' || $m['required_score']!==60 || $m['total_cards']!==$expected[$m['module_code']][1] || $m['status']!==1) failCli('Invalid module enum/value');
    }
    $codes=[];$types=['K'=>0,'S'=>0,'D'=>0,'C'=>0];$per=[];
    foreach ($p['cards'] as $c) {
        exactKeys($c,['module_code','card_code','card_type','title','content','options','standard_answer','tips','difficulty','score','sort_order','status'],'Card');
        if (!isset($expected[$c['module_code']]) || !is_string($c['card_code']) || !preg_match('/^sales-(\d{4})-([ksdc])$/D',$c['card_code'],$mm) || isset($codes[$c['card_code']])) failCli('Invalid/duplicate card_code');
        $n=(int)$mm[1]; $type=strtoupper($mm[2]); if ($n<1||$n>75||$c['card_type']!==$type) failCli('Card code/type mismatch');
        $wantedModule=$n<=25?'sales-ability-foundation':($n<=46?'sales-ability-advanced':'sales-ability-expert');
        if ($c['module_code']!==$wantedModule || $c['difficulty']!==$expected[$wantedModule][2]) failCli('Card module/difficulty mismatch');
        $seq=['K'=>1,'S'=>2,'D'=>3,'C'=>4];
        if ($c['sort_order']!==$n*10+$seq[$type] || $c['score']!==100 || $c['status']!==1) failCli('Card numeric value invalid');
        foreach (['title'=>200,'content'=>65535,'tips'=>65535] as $k=>$max) if (!validText($c[$k],$max)) failCli('Invalid '.$k);
        if (!validText($c['standard_answer'],65535,true)) failCli('Invalid standard_answer');
        if ($c['options']!==null) { if (!is_array($c['options']) || array_values($c['options'])!==$c['options']) failCli('Options must be an array'); foreach($c['options'] as $v) if(!is_string($v)||$v===''||mb_strlen($v,'UTF-8')>1000) failCli('Invalid option value'); if(strlen(json_encode($c['options'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))>65535) failCli('Options too long'); }
        if ($type === 'C' && (!is_array($c['options']) || count($c['options']) !== 5)) failCli('C cards require exactly five options');
        if ($type !== 'C' && $c['options'] !== null) failCli('Only C cards may contain options');
        if ($type === 'S' && $c['standard_answer'] === null) failCli('S cards require a standard answer');
        if ($type !== 'S' && $c['standard_answer'] !== null) failCli('Only S cards may contain a standard answer');
        $codes[$c['card_code']]=true;$types[$type]++;$per[$wantedModule]=isset($per[$wantedModule])?$per[$wantedModule]+1:1;
    }
    if ($types!==['K'=>75,'S'=>75,'D'=>75,'C'=>75] || $per!==['sales-ability-foundation'=>100,'sales-ability-advanced'=>84,'sales-ability-expert'=>116]) failCli('Derived counts invalid');
}
function preflight(PDO $db) {
    $required=['training_modules'=>['id','module_code','module_name','description','role_code','category','level','required_score','total_cards','sort_order','status'], 'training_cards'=>['id','module_id','card_type','card_code','title','content','options','standard_answer','tips','difficulty','score','sort_order','status'], 'user_progress'=>['id','module_id','card_id']];
    $meta=[];
    foreach ($required as $table=>$columns) {
        $status=$db->query('SHOW TABLE STATUS LIKE '.$db->quote($table))->fetch(PDO::FETCH_ASSOC);
        if (!$status || strcasecmp((string)$status['Engine'],'InnoDB')!==0) failCli($table.' must exist and use InnoDB');
        $rows=$db->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC); $by=[];
        foreach($rows as $r)$by[$r['Field']]=$r;
        foreach($columns as $c)if(!isset($by[$c]))failCli($table.' missing required column '.$c);
        $meta[$table]=$by;
    }
    $minimumVarchar = [
        'training_modules' => ['module_code'=>50,'module_name'=>100],
        'training_cards' => ['card_code'=>50,'title'=>200],
    ];
    foreach ($minimumVarchar as $table => $columns) {
        foreach ($columns as $column => $minimum) {
            $type = strtolower((string)$meta[$table][$column]['Type']);
            if (!preg_match('/^varchar\((\d+)\)$/D',$type,$match) || (int)$match[1] < $minimum) failCli($table.'.'.$column.' varchar length is too small');
        }
    }
    foreach (['description'=>true] as $column=>$unused) if (!preg_match('/text/i',(string)$meta['training_modules'][$column]['Type'])) failCli('training_modules.'.$column.' must be TEXT');
    foreach (['content','standard_answer','tips'] as $column) if (!preg_match('/text/i',(string)$meta['training_cards'][$column]['Type'])) failCli('training_cards.'.$column.' must be TEXT');
    if (strcasecmp((string)$meta['training_cards']['options']['Type'],'json')!==0) failCli('training_cards.options must be JSON');
    $type=(string)$meta['training_cards']['card_type']['Type']; if (stripos($type,'enum(')!==0) failCli('training_cards.card_type must be ENUM'); foreach(['K','S','D','C'] as $v)if(strpos($type,"'".$v."'")===false)failCli('card_type enum must contain K/S/D/C');
    foreach(['training_modules'=>'module_code','training_cards'=>'card_code'] as $table=>$column) {
        $indexes = $db->query('SHOW INDEX FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $uniqueIndexes = [];
        foreach ($indexes as $index) {
            if ((int)$index['Non_unique'] === 0) {
                $uniqueIndexes[$index['Key_name']][(int)$index['Seq_in_index']] = $index['Column_name'];
            }
        }
        $ok = false;
        foreach ($uniqueIndexes as $indexColumns) {
            ksort($indexColumns);
            if (array_values($indexColumns) === [$column]) {
                $ok = true;
            }
        }
        if (!$ok) failCli($table.'.'.$column.' requires a single-column unique index');
    }
    return $meta;
}
function normalizeOptions($v) { if ($v===null||$v==='')return null; $x=is_string($v)?json_decode($v,true):$v; return json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function databaseOptions($v) { return $v===null ? null : json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function normModule($r) {
    $out = [];
    foreach (MODULE_FIELDS as $field) {
        $out[$field] = in_array($field,['required_score','total_cards','status'],true) ? (int)$r[$field] : ($r[$field] === null ? null : (string)$r[$field]);
    }
    return $out;
}
function normCard($r,$moduleCode) {
    $out = ['module_code'=>(string)$moduleCode];
    foreach (CARD_FIELDS as $field) {
        if ($field === 'options') $out[$field] = normalizeOptions($r[$field]);
        elseif (in_array($field,['score','sort_order','status'],true)) $out[$field] = (int)$r[$field];
        else $out[$field] = $r[$field] === null ? null : (string)$r[$field];
    }
    return $out;
}
function similarity($a,$b) { $a=preg_replace('/[^\p{L}\p{N}]+/u','',mb_strtolower($a,'UTF-8'));$b=preg_replace('/[^\p{L}\p{N}]+/u','',mb_strtolower($b,'UTF-8')); if($a===$b)return 1.0; similar_text($a,$b,$pct);return $pct/100; }
function loadState(PDO $db,$p) {
    $mCodes=array_column($p['modules'],'module_code');$cCodes=array_column($p['cards'],'card_code');
    $mods=selectIn($db,'SELECT * FROM training_modules WHERE module_code IN (%s)','module_code',$mCodes);
    $cards=selectIn($db,'SELECT tc.*,tm.module_code AS resolved_module_code FROM training_cards tc JOIN training_modules tm ON tm.id=tc.module_id WHERE tc.card_code IN (%s)','card_code',$cCodes);
    $allMods=$db->query('SELECT id,module_code,module_name FROM training_modules')->fetchAll(PDO::FETCH_ASSOC);
    $allCards=$db->query('SELECT id,card_code,title,content FROM training_cards')->fetchAll(PDO::FETCH_ASSOC);
    return [$mods,$cards,$allMods,$allCards];
}
function selectIn(PDO $db,$sql,$key,$values) { $marks=implode(',',array_fill(0,count($values),'?'));$s=$db->prepare(sprintf($sql,$marks));$s->execute($values);$out=[];foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r[$key]]=$r;return $out; }
function diffPackage(PDO $db,$p) {
    list($mods,$cards,$allMods,$allCards)=loadState($db,$p);$d=['modules'=>['insert'=>[],'skip'=>[],'update_pending'=>[]],'cards'=>['insert'=>[],'skip'=>[],'update_pending'=>[]],'conflicts'=>[],'manual_review'=>[]];
    $targetM=array_column($p['modules'],'module_code');$targetC=array_column($p['cards'],'card_code');
    foreach($p['modules'] as $m){$code=$m['module_code'];if(isset($mods[$code])){$same=normModule($m)===normModule($mods[$code]);$d['modules'][$same?'skip':'update_pending'][]=$code;}else{$d['modules']['insert'][]=$code;foreach($allMods as $x)if(!in_array($x['module_code'],$targetM,true)&&similarity($m['module_name'],$x['module_name'])>=SIMILARITY_THRESHOLD)$d['manual_review'][]=['kind'=>'module_title','incoming'=>$code,'existing'=>$x['module_code']];}}
    foreach($p['cards'] as $c){$code=$c['card_code'];if(isset($cards[$code])){$same=normCard($c,$c['module_code'])===normCard($cards[$code],$cards[$code]['resolved_module_code']);$d['cards'][$same?'skip':'update_pending'][]=$code;}else{$d['cards']['insert'][]=$code;$hash=hash('sha256',(string)$c['content']);foreach($allCards as $x)if(!in_array($x['card_code'],$targetC,true)&&(similarity($c['title'],$x['title'])>=SIMILARITY_THRESHOLD||hash('sha256',(string)$x['content'])===$hash))$d['manual_review'][]=['kind'=>'card_similarity','incoming'=>$code,'existing'=>$x['card_code']];}}
    return $d;
}
function summary($d,$p,$mode='dry-run') { return ['ok'=>true,'mode'=>$mode,'summary'=>['modules'=>['insert'=>count($d['modules']['insert']),'skip'=>count($d['modules']['skip']),'update_pending'=>count($d['modules']['update_pending'])],'cards'=>['insert'=>count($d['cards']['insert']),'skip'=>count($d['cards']['skip']),'update_pending'=>count($d['cards']['update_pending'])],'conflicts'=>count($d['conflicts']),'manual_review'=>count($d['manual_review']),'types'=>['K'=>75,'S'=>75,'D'=>75,'C'=>75],'module_card_counts'=>['sales-ability-foundation'=>100,'sales-ability-advanced'=>84,'sales-ability-expert'=>116]],'details'=>$d]; }
function validateOutputPath($path,$mustNotExist=true) {
    if($path===''||strpos($path,"\0")!==false)failCli('Invalid output path');$parent=realpath(dirname($path));if($parent===false||!is_dir($parent)||!is_writable($parent))failCli('Output parent must exist and be writable');
    $web=realpath(__DIR__.'/..');$prefix=rtrim(str_replace('\\','/',$web),'/').'/';$candidate=rtrim(str_replace('\\','/',$parent),'/').'/';
    if(strncmp(strtolower($candidate),strtolower($prefix),strlen($prefix))===0||strcasecmp(rtrim($candidate,'/'),rtrim($prefix,'/'))===0)failCli('Output path must be outside web root');
    if($mustNotExist&&file_exists($path))failCli('Refusing to overwrite existing file: '.$path);return $parent;
}
function atomicJson($path,$data,$mustNotExist=true) { validateOutputPath($path,$mustNotExist);$tmp=$path.'.tmp.'.bin2hex(random_bytes(8));$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);if($json===false||file_put_contents($tmp,$json."\n",LOCK_EX)===false){@unlink($tmp);failCli('Cannot write temporary JSON');}if(!chmod($tmp,0600)||!rename($tmp,$path)){@unlink($tmp);failCli('Cannot atomically publish JSON');}return hash_file('sha256',$path); }
function tableSnapshot(PDO $db,$table){$create=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);return ['create'=>$create[1],'count'=>(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()];}
function backup(PDO $db,$p,$dir,$label) { validateOutputPath(rtrim($dir,'/\\').DIRECTORY_SEPARATOR.'probe',false);$path=rtrim($dir,'/\\').DIRECTORY_SEPARATOR.$label.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
    list($mods,$cards)=loadState($db,$p);$ids=[];foreach($cards as $c)$ids[]=(int)$c['id'];$progress=[];if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$s=$db->prepare('SELECT * FROM user_progress WHERE card_id IN ('.$marks.')');$s->execute($ids);$progress=$s->fetchAll(PDO::FETCH_ASSOC);} $data=['schema'=>'sales-training-backup.v1','created_at'=>gmdate('c'),'tables'=>[],'target_modules'=>array_values($mods),'target_cards'=>array_values($cards),'related_user_progress'=>$progress];foreach(['training_modules','training_cards','user_progress'] as $t)$data['tables'][$t]=tableSnapshot($db,$t);$hash=atomicJson($path,$data,true);return ['path'=>$path,'sha256'=>$hash]; }
function counts(PDO $db){return ['training_modules'=>(int)$db->query('SELECT COUNT(*) FROM training_modules')->fetchColumn(),'training_cards'=>(int)$db->query('SELECT COUNT(*) FROM training_cards')->fetchColumn(),'user_progress'=>(int)$db->query('SELECT COUNT(*) FROM user_progress')->fetchColumn()];}
function rowsDigestExceptCodes(PDO $db,$table,$codeColumn,$codes){
    if (!$codes) {
        $rows = $db->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $marks = implode(',',array_fill(0,count($codes),'?'));
        $s = $db->prepare('SELECT * FROM `'.$table.'` WHERE `'.$codeColumn.'` NOT IN ('.$marks.') ORDER BY id');
        $s->execute($codes);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function rowsDigestExceptIds(PDO $db,$table,$ids){
    if (!$ids) {
        $rows = $db->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $marks = implode(',',array_fill(0,count($ids),'?'));
        $s = $db->prepare('SELECT * FROM `'.$table.'` WHERE id NOT IN ('.$marks.') ORDER BY id');
        $s->execute(array_values($ids));
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function assertImport(PDO $db,$p,$oldDigest,$mutableCodes){$mCodes=$mutableCodes['modules'];$cCodes=$mutableCodes['cards'];if(rowsDigestExceptCodes($db,'training_modules','module_code',$mCodes)!==$oldDigest['modules']||rowsDigestExceptCodes($db,'training_cards','card_code',$cCodes)!==$oldDigest['cards'])failCli('Preexisting rows changed');
    list($mods,$cards)=loadState($db,$p);if(count($mods)!==3||count($cards)!==300)failCli('Expected 3 modules and 300 cards');$types=['K'=>0,'S'=>0,'D'=>0,'C'=>0];$per=[];
    $packageCards=[];foreach($p['cards'] as $packageCard)$packageCards[$packageCard['card_code']]=$packageCard;
    foreach($cards as $c){if(!isset($mods[$c['resolved_module_code']])||(int)$c['module_id']!==(int)$mods[$c['resolved_module_code']]['id'])failCli('Orphan/wrong module card');if(!isset($packageCards[$c['card_code']])||normCard($c,$c['resolved_module_code'])!==normCard($packageCards[$c['card_code']],$packageCards[$c['card_code']]['module_code']))failCli('Card fields differ from package: '.$c['card_code']);$types[$c['card_type']]++;$per[$c['resolved_module_code']]=isset($per[$c['resolved_module_code']])?$per[$c['resolved_module_code']]+1:1;}
    if($types!==['K'=>75,'S'=>75,'D'=>75,'C'=>75])failCli('Type assertion failed');foreach($p['modules'] as $m){$x=$mods[$m['module_code']];if(normModule($x)!==normModule($m)||($per[$m['module_code']]??0)!==$m['total_cards'])failCli('Module fields differ from package: '.$m['module_code']);}
    $orphans=(int)$db->query('SELECT COUNT(*) FROM training_cards tc LEFT JOIN training_modules tm ON tm.id=tc.module_id WHERE tm.id IS NULL')->fetchColumn();if($orphans)failCli('Orphan cards exist');
}
function applyImport(PDO $db,$p,$a,$initial) {
    if(($initial['modules']['update_pending']||$initial['cards']['update_pending'])&&!$a['allow_update'])failCli('Updates pending; --allow-update is required');if($initial['conflicts'])failCli('Conflicts block apply');if($initial['manual_review']&&!$a['ack_manual_review'])failCli('Manual review requires --ack-manual-review');
    validateOutputPath($a['manifest'],true);$locked=false;$tx=false;
    try{$s=$db->prepare('SELECT GET_LOCK(?, 10)');$s->execute([SALES_LOCK]);if((int)$s->fetchColumn()!==1)failCli('Could not obtain named lock');$locked=true;preflight($db);$d=diffPackage($db,$p);if(($d['modules']['update_pending']||$d['cards']['update_pending'])&&!$a['allow_update'])failCli('Diff changed: update blocked');if($d['conflicts']||($d['manual_review']&&!$a['ack_manual_review']))failCli('Diff changed: conflict/manual review blocked');
      $before=counts($db);$backup=backup($db,$p,$a['backup_dir'],'sales-import-backup');$mutableCodes=['modules'=>array_merge($d['modules']['insert'],$d['modules']['update_pending']),'cards'=>array_merge($d['cards']['insert'],$d['cards']['update_pending'])];$old=['modules'=>rowsDigestExceptCodes($db,'training_modules','module_code',$mutableCodes['modules']),'cards'=>rowsDigestExceptCodes($db,'training_cards','card_code',$mutableCodes['cards'])];
      $batch=bin2hex(random_bytes(16));$pending=['schema_version'=>MANIFEST_VERSION,'status'=>'pending','batch_id'=>$batch,'package_sha256'=>$a['sha256'],'backup'=>$backup,'before_counts'=>$before,'planned_diff'=>$d,'inserted'=>['modules'=>[],'cards'=>[]],'updated'=>['modules'=>[],'cards'=>[]],'skipped'=>['modules'=>$d['modules']['skip'],'cards'=>$d['cards']['skip']]];atomicJson($a['manifest'],$pending,true);
      $db->beginTransaction();$tx=true;$again=diffPackage($db,$p);if($again!==$d)failCli('Diff changed inside transaction');$max=(int)$db->query('SELECT COALESCE(MAX(sort_order),0) FROM training_modules')->fetchColumn();$moduleIds=[];
      foreach($p['modules'] as $m){$code=$m['module_code'];if(in_array($code,$d['modules']['insert'],true)){$max++;$q=$db->prepare('INSERT INTO training_modules (module_code,module_name,description,role_code,category,level,required_score,total_cards,sort_order,status) VALUES (?,?,?,?,?,?,?,?,?,?)');$q->execute([$code,$m['module_name'],$m['description'],$m['role_code'],$m['category'],$m['level'],$m['required_score'],0,$max,$m['status']]);$moduleIds[$code]=(int)$db->lastInsertId();$pending['inserted']['modules'][]=['id'=>$moduleIds[$code],'code'=>$code];}else{$q=$db->prepare('SELECT id FROM training_modules WHERE module_code=?');$q->execute([$code]);$moduleIds[$code]=(int)$q->fetchColumn();if(in_array($code,$d['modules']['update_pending'],true)){$q=$db->prepare('UPDATE training_modules SET module_name=?,description=?,role_code=?,category=?,level=?,required_score=?,status=? WHERE id=?');$q->execute([$m['module_name'],$m['description'],$m['role_code'],$m['category'],$m['level'],$m['required_score'],$m['status'],$moduleIds[$code]]);$pending['updated']['modules'][]=['id'=>$moduleIds[$code],'code'=>$code];}}}
      foreach($p['cards'] as $c){$vals=[$moduleIds[$c['module_code']],$c['card_type'],$c['title'],$c['content'],databaseOptions($c['options']),$c['standard_answer'],$c['tips'],$c['difficulty'],$c['score'],$c['sort_order'],$c['status']];if(in_array($c['card_code'],$d['cards']['insert'],true)){$q=$db->prepare('INSERT INTO training_cards (module_id,card_type,card_code,title,content,options,standard_answer,tips,difficulty,score,sort_order,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute(array_merge(array_slice($vals,0,2),[$c['card_code']],array_slice($vals,2)));$pending['inserted']['cards'][]=['id'=>(int)$db->lastInsertId(),'code'=>$c['card_code']];}elseif(in_array($c['card_code'],$d['cards']['update_pending'],true)){$q=$db->prepare('UPDATE training_cards SET module_id=?,card_type=?,title=?,content=?,options=?,standard_answer=?,tips=?,difficulty=?,score=?,sort_order=?,status=? WHERE card_code=?');$q->execute(array_merge($vals,[$c['card_code']]));$q=$db->prepare('SELECT id FROM training_cards WHERE card_code=?');$q->execute([$c['card_code']]);$pending['updated']['cards'][]=['id'=>(int)$q->fetchColumn(),'code'=>$c['card_code']];}}
      foreach($moduleIds as $code=>$id){$q=$db->prepare('UPDATE training_modules SET total_cards=(SELECT COUNT(*) FROM training_cards WHERE module_id=?) WHERE id=?');$q->execute([$id,$id]);}assertImport($db,$p,$old,$mutableCodes);$pending['prepared_at']=gmdate('c');$pending['recoverable_from_pending']=true;atomicJson($a['manifest'],$pending,false);$db->commit();$tx=false;$completed=$pending;$completed['status']='completed';$completed['completed_at']=gmdate('c');$completed['after_counts']=counts($db);try{atomicJson($a['manifest'],$completed,false);}catch(Throwable $manifestError){throw new RuntimeException('database committed; manifest remains recoverable pending at '.$a['manifest'].': '.$manifestError->getMessage(),0,$manifestError);}$result=summary($d,$p,'apply');$result['database_committed']=true;$result['manifest']=$a['manifest'];$result['backup']=$backup;return $result;
    }finally{if($tx&&$db->inTransaction())$db->rollBack();if($locked){$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->execute([SALES_LOCK]);}}
}
function readManifest($path,$sha){validateExternalInputPath($path);if(!is_file($path)||!is_readable($path))failCli('Manifest unreadable');$m=json_decode(file_get_contents($path),true);if(!is_array($m)||($m['schema_version']??'')!==MANIFEST_VERSION)failCli('Manifest schema invalid');$status=$m['status']??'';if($status!=='completed'&& !($status==='pending'&&($m['recoverable_from_pending']??false)===true))failCli('Manifest must be completed or explicitly recoverable pending');if(!isset($m['package_sha256'])||!hash_equals($sha,$m['package_sha256']))failCli('Manifest/package SHA mismatch');if(!empty($m['updated']['modules'])||!empty($m['updated']['cards']))failCli('Automatic rollback refused: batch contains updates; restore manually from backup');return $m;}
function rollbackCheck(PDO $db,$m){$plan=['cards'=>[],'modules'=>[]];$moduleIds=[];foreach($m['inserted']['modules'] as $x)$moduleIds[(int)$x['id']]=$x['code'];foreach($m['inserted']['cards'] as $x){$q=$db->prepare('SELECT id,card_code,module_id FROM training_cards WHERE id=?');$q->execute([(int)$x['id']]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r||(int)$r['id']!==(int)$x['id']||$r['card_code']!==$x['code'])failCli('Inserted card ID/code mismatch');$q=$db->prepare('SELECT COUNT(*) FROM user_progress WHERE card_id=?');$q->execute([$x['id']]);if((int)$q->fetchColumn()>0)failCli('Rollback blocked by user_progress');$plan['cards'][]=$x;}
 foreach($moduleIds as $id=>$code){$q=$db->prepare('SELECT module_code FROM training_modules WHERE id=?');$q->execute([$id]);if($q->fetchColumn()!==$code)failCli('Inserted module ID/code mismatch');$known=[];foreach($m['inserted']['cards'] as $x)$known[]=(int)$x['id'];$marks=$known?implode(',',array_fill(0,count($known),'?')):'0';$q=$db->prepare('SELECT COUNT(*) FROM training_cards WHERE module_id=? AND id NOT IN ('.$marks.')');$q->execute(array_merge([$id],$known));if((int)$q->fetchColumn()>0)failCli('Rollback blocked by unknown module card');$q=$db->prepare('SELECT COUNT(*) FROM user_progress WHERE module_id=?');$q->execute([$id]);if((int)$q->fetchColumn()>0)failCli('Rollback blocked by module progress');$plan['modules'][]=['id'=>$id,'code'=>$code];}return $plan;}
function validateExternalInputPath($path) {
    $real = realpath($path);
    if ($real === false) failCli('Input path does not exist');
    $web = realpath(__DIR__.'/..');
    $webPrefix = rtrim(str_replace('\\','/',$web),'/').'/';
    $candidate = str_replace('\\','/',$real);
    if (strncmp(strtolower($candidate),strtolower($webPrefix),strlen($webPrefix)) === 0) failCli('Manifest path must be outside web root');
}
function runRollback(PDO $db,$p,$a){$m=readManifest($a['manifest'],$a['sha256']);preflight($db);$plan=rollbackCheck($db,$m);if(!$a['apply'])return ['ok'=>true,'mode'=>'rollback-dry-run','plan'=>$plan];$locked=false;$tx=false;try{$q=$db->prepare('SELECT GET_LOCK(?,10)');$q->execute([SALES_LOCK]);if((int)$q->fetchColumn()!==1)failCli('Could not obtain named lock');$locked=true;preflight($db);$plan=rollbackCheck($db,$m);$before=counts($db);$backup=backup($db,$p,$a['backup_dir'],'sales-rollback-backup');$insertedModuleIds=array_map(function($x){return (int)$x['id'];},$m['inserted']['modules']);$insertedCardIds=array_map(function($x){return (int)$x['id'];},$m['inserted']['cards']);$allBefore=['modules'=>rowsDigestExceptIds($db,'training_modules',$insertedModuleIds),'cards'=>rowsDigestExceptIds($db,'training_cards',$insertedCardIds)];$db->beginTransaction();$tx=true;$plan=rollbackCheck($db,$m);foreach($plan['cards'] as $x){$q=$db->prepare('DELETE FROM training_cards WHERE id=? AND card_code=?');$q->execute([$x['id'],$x['code']]);if($q->rowCount()!==1)failCli('Precise card delete failed');}foreach($plan['modules'] as $x){$q=$db->prepare('DELETE FROM training_modules WHERE id=? AND module_code=? AND NOT EXISTS (SELECT 1 FROM training_cards WHERE module_id=?)');$q->execute([$x['id'],$x['code'],$x['id']]);if($q->rowCount()!==1)failCli('Precise module delete failed');}if(rowsDigestExceptIds($db,'training_modules',$insertedModuleIds)!==$allBefore['modules']||rowsDigestExceptIds($db,'training_cards',$insertedCardIds)!==$allBefore['cards'])failCli('Non-batch rows changed');$db->commit();$tx=false;return ['ok'=>true,'mode'=>'rollback-apply','status'=>'completed','database_committed'=>true,'package_sha256'=>$a['sha256'],'manifest'=>$a['manifest'],'deleted'=>$plan,'backup'=>$backup,'before_counts'=>$before,'after_counts'=>counts($db)];}finally{if($tx&&$db->inTransaction())$db->rollBack();if($locked){$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([SALES_LOCK]);}}}

try {
    $args = parseArgs($argv);
    $package = readPackage($args['json'],$args['sha256']);
    // Default dry-run is database-read-only. An explicit --report permits only this requested filesystem output.
    if (isset($args['report'])) validateOutputPath($args['report'],true);
    $db = getDB();
    preflight($db);
    if ($args['command']==='import') {
        $diff=diffPackage($db,$package);
        $result=$args['apply']?applyImport($db,$package,$args,$diff):summary($diff,$package);
    } else {
        $result=runRollback($db,$package,$args);
    }
    if (isset($args['report'])) {
        try {
            atomicJson($args['report'],$result,true);
        } catch (Throwable $reportError) {
            if (!empty($result['database_committed'])) {
                $result['report_warning']=$reportError->getMessage();
                fwrite(STDERR,'WARNING: database committed; report write failed: '.$reportError->getMessage()."\n");
            } else {
                throw $reportError;
            }
        }
    }
    fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n");
    exit(0);
} catch(Throwable $e) {
    fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");
    exit(1);
}
