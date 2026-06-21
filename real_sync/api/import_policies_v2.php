<?php
/**
 * 制度数据导入脚本 v2
 * 将体系文件导入到 policies 表
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$dbHost = 'localhost';
$dbName = '_122_51_223_46';
$dbUser = '_122_51_223_46';
$dbPass = 'Yaoxiuning190';

try {
    $db = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$basePath = '/www/wwwroot/122.51.223.46/体系文件_最终版/';

$policies = [
    // 01_门店运营标准体系
    ['file' => '01_门店运营标准体系/01A_开关店SOP.md', 'key' => '01a-switch-store', 'title' => '开关店SOP', 'category' => '门店运营', 'keywords' => '开关店,SOP,标准流程'],
    ['file' => '01_门店运营标准体系/01B_课前课中课后流程.md', 'key' => '01b-class-flow', 'title' => '课前课中课后流程', 'category' => '门店运营', 'keywords' => '课程,流程,课前,课中,课后'],
    ['file' => '01_门店运营标准体系/01C_卫生与安全标准.md', 'key' => '01c-hygiene-safety', 'title' => '卫生与安全标准', 'category' => '门店运营', 'keywords' => '卫生,安全,标准'],
    ['file' => '01_门店运营标准体系/01D_设备与物料管理.md', 'key' => '01d-equipment', 'title' => '设备与物料管理', 'category' => '门店运营', 'keywords' => '设备,物料,管理'],
    ['file' => '01_门店运营标准体系/01E_突发事件应急处理.md', 'key' => '01e-emergency', 'title' => '突发事件应急处理', 'category' => '门店运营', 'keywords' => '突发,应急,处理'],
    ['file' => '01_门店运营标准体系/01F_合同管理统一规范.md', 'key' => '01f-contract', 'title' => '合同管理统一规范', 'category' => '门店运营', 'keywords' => '合同,管理,规范'],
    ['file' => '01_门店运营标准体系/01G_体测工作流程与标准.md', 'key' => '01g-assessment', 'title' => '体测工作流程与标准', 'category' => '门店运营', 'keywords' => '体测,评估,流程'],

    // 02_人员管理体系
    ['file' => '02_人员管理体系/02A_岗位说明书.md', 'key' => '02a-job-desc', 'title' => '岗位说明书', 'category' => '人员管理', 'keywords' => '岗位,职责,说明'],
    ['file' => '02_人员管理体系/02B_招聘流程与标准.md', 'key' => '02b-recruitment', 'title' => '招聘流程与标准', 'category' => '人员管理', 'keywords' => '招聘,流程,标准'],
    ['file' => '02_人员管理体系/02C_新员工入职培训.md', 'key' => '02c-onboarding', 'title' => '新员工入职培训', 'category' => '人员管理', 'keywords' => '入职,培训,新员工'],
    ['file' => '02_人员管理体系/02D_教练星级晋升体系.md', 'key' => '02d-promotion', 'title' => '教练星级晋升体系', 'category' => '人员管理', 'keywords' => '晋升,星级,教练'],
    ['file' => '02_人员管理体系/02E_薪酬结构.md', 'key' => '02e-compensation', 'title' => '薪酬结构', 'category' => '人员管理', 'keywords' => '薪酬,工资,福利'],
    ['file' => '02_人员管理体系/02F_离职管理.md', 'key' => '02f-resignation', 'title' => '离职管理', 'category' => '人员管理', 'keywords' => '离职,管理'],
    ['file' => '02_人员管理体系/02G_工作量管理标准.md', 'key' => '02g-workload', 'title' => '工作量管理标准', 'category' => '人员管理', 'keywords' => '工作量,管理'],

    // 03_店长管理机制
    ['file' => '03_店长管理机制/03A_店长会议管理体系.md', 'key' => '03a-meeting', 'title' => '店长会议管理体系', 'category' => '店长管理', 'keywords' => '会议,店长,管理'],
    ['file' => '03_店长管理机制/03B_店长数据管理体系.md', 'key' => '03b-data', 'title' => '店长数据管理体系', 'category' => '店长管理', 'keywords' => '数据,管理,体系'],
    ['file' => '03_店长管理机制/03C_店长日周月工作流.md', 'key' => '03c-workflow', 'title' => '店长日周月工作流', 'category' => '店长管理', 'keywords' => '工作流,日周月'],
    ['file' => '03_店长管理机制/03D_店长巡店检查体系.md', 'key' => '03d-inspection', 'title' => '店长巡店检查体系', 'category' => '店长管理', 'keywords' => '巡店,检查'],
    ['file' => '03_店长管理机制/03E_店长帮带与帮扶体系.md', 'key' => '03e-mentoring', 'title' => '店长帮带与帮扶体系', 'category' => '店长管理', 'keywords' => '帮带,帮扶,店长'],
    ['file' => '03_店长管理机制/03F_店长自我成长工具.md', 'key' => '03f-growth', 'title' => '店长自我成长工具', 'category' => '店长管理', 'keywords' => '成长,自我,店长'],
    ['file' => '03_店长管理机制/03G_督导考核标准.md', 'key' => '03g-supervisor', 'title' => '督导考核标准', 'category' => '店长管理', 'keywords' => '督导,考核'],
    ['file' => '03_店长管理机制/03H_店长经营闭环总则.md', 'key' => '03h-closed-loop', 'title' => '店长经营闭环总则', 'category' => '店长管理', 'keywords' => '经营,闭环'],

    // 04_服务标准体系
    ['file' => '04_服务标准体系/04A_首次到店接待标准.md', 'key' => '04a-reception', 'title' => '首次到店接待标准', 'category' => '服务标准', 'keywords' => '接待,到店,服务'],
    ['file' => '04_服务标准体系/04B_家长沟通话术标准.md', 'key' => '04b-communication', 'title' => '家长沟通话术标准', 'category' => '服务标准', 'keywords' => '沟通,话术,家长'],
    ['file' => '04_服务标准体系/04C_续费触达与跟进.md', 'key' => '04c-renewal', 'title' => '续费触达与跟进', 'category' => '服务标准', 'keywords' => '续费,触达,跟进'],
    ['file' => '04_服务标准体系/04D_投诉处理流程.md', 'key' => '04d-complaint', 'title' => '投诉处理流程', 'category' => '服务标准', 'keywords' => '投诉,处理,流程'],
    ['file' => '04_服务标准体系/04E_会员首月服务跟进标准.md', 'key' => '04e-first-month', 'title' => '会员首月服务跟进标准', 'category' => '服务标准', 'keywords' => '首月,跟进,服务'],
    ['file' => '04_服务标准体系/04F_会员服务与续费主链路总则.md', 'key' => '04f-member-service', 'title' => '会员服务与续费主链路总则', 'category' => '服务标准', 'keywords' => '会员,续费,服务'],

    // 05_教学标准体系
    ['file' => '05_教学标准体系/05A_ACE落地执行标准.md', 'key' => '05a-ace', 'title' => 'ACE落地执行标准', 'category' => '教学标准', 'keywords' => 'ACE,教学,执行'],
    ['file' => '05_教学标准体系/05B_各课程教学SOP.md', 'key' => '05b-course-sop', 'title' => '各课程教学SOP', 'category' => '教学标准', 'keywords' => '课程,SOP,教学'],
    ['file' => '05_教学标准体系/05C_学员升班考核标准.md', 'key' => '05c-promotion', 'title' => '学员升班考核标准', 'category' => '教学标准', 'keywords' => '升班,考核,学员'],

    // 06_业绩管理体系
    ['file' => '06_业绩管理体系/06A_目标分解与KDI指标.md', 'key' => '06a-kdi', 'title' => '目标分解与KDI指标', 'category' => '业绩管理', 'keywords' => '目标,KDI,指标'],
    ['file' => '06_业绩管理体系/06B_激励方案.md', 'key' => '06b-incentive', 'title' => '激励方案', 'category' => '业绩管理', 'keywords' => '激励,方案'],
    ['file' => '06_业绩管理体系/06C_关键节点营销.md', 'key' => '06c-marketing', 'title' => '关键节点营销', 'category' => '业绩管理', 'keywords' => '营销,节点'],

    // 其他
    ['file' => '00_成长基金管理办法.md', 'key' => 'growth-fund', 'title' => '成长基金管理办法', 'category' => '通用', 'keywords' => '基金,成长'],
    ['file' => '00A_全体系统一原则.md', 'key' => 'unified-principles', 'title' => '全体系统一原则', 'category' => '总纲', 'keywords' => '原则,统一,规范'],
    ['file' => '00_追光小牛连锁运营体系_总纲.md', 'key' => 'system-outline', 'title' => '追光小牛连锁运营体系总纲', 'category' => '总纲', 'keywords' => '体系,总纲,运营'],
    ['file' => '07_品牌一致性标准.md', 'key' => 'brand-consistency', 'title' => '品牌一致性标准', 'category' => '品牌', 'keywords' => '品牌,一致,标准'],
    ['file' => '08_体系推进计划.md', 'key' => 'implementation-plan', 'title' => '体系推进计划', 'category' => '总纲', 'keywords' => '推进,计划'],
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
