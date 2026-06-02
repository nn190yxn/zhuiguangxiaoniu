<?php
declare(strict_types=1);

require_once __DIR__ . '/api/config.php';

$currentUserId = getCurrentUserId();
if ($currentUserId <= 0) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => '请先登录'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$docs = [
    // V4 根目录制度文件
    'v4-00' => 'v4/00_追光小牛连锁运营体系_总纲.md',
    'v4-00a' => 'v4/00A_全体系统一原则.md',
    'v4-00b' => 'v4/00_成长基金管理办法.md',
    'v4-01' => 'v4/01_门店运营标准体系.md',
    'v4-02' => 'v4/02_人员管理体系.md',
    'v4-02h' => 'v4/02H_教练培训与认证体系.md',
    'v4-02i' => 'v4/02I_教练专业技能能力模型.md',
    'v4-02j' => 'v4/02J_课堂角色授权与跟岗标准.md',
    'v4-03' => 'v4/03_店长管理机制.md',
    'v4-03x' => 'v4/03X_店长巡课与教学质量管理标准.md',
    'v4-04' => 'v4/04_服务标准体系.md',
    'v4-05' => 'v4/05_教学标准体系.md',
    'v4-05d' => 'v4/05D_教练与助教上课执行标准.md',
    'v4-05e' => 'v4/05E_课堂安全保护与异常处理标准.md',
    'v4-05f' => 'v4/05F_课后记录与家长反馈标准.md',
    'v4-06' => 'v4/06_业绩管理体系.md',
    'v4-07' => 'v4/07_品牌一致性标准.md',
    'v4-08' => 'v4/08_体系推进计划.md',
    'v4-09' => 'v4/09_专业知识库.md',
    'v4-release' => 'v4/发布说明.md',
    'v4-42' => 'v4/V4.2_并入版更新清单.md',
    'v4-43' => 'v4/V4.3_知识库完善清单.md',
    'v4-forms' => 'v4/追光小牛体系_合并版表单总册.md',
    'v4-tools' => 'v4/追光小牛体系_工具表单完整清单.md',
    'v4-control' => 'v4/追光小牛体系_执行控制表单包.md',
    'v4-summary' => 'v4/追光小牛_各项工作标准分类汇总.md',

    // V4 子目录详细制度 - 01 门店运营
    'v4-01a' => 'v4/01_门店运营标准体系/01A_开关店SOP.md',
    'v4-01b' => 'v4/01_门店运营标准体系/01B_课前课中课后流程.md',
    'v4-01c' => 'v4/01_门店运营标准体系/01C_卫生与安全标准.md',
    'v4-01d' => 'v4/01_门店运营标准体系/01D_设备与物料管理.md',
    'v4-01e' => 'v4/01_门店运营标准体系/01E_突发事件应急处理.md',
    'v4-01f' => 'v4/01_门店运营标准体系/01F_合同管理统一规范.md',
    'v4-01g' => 'v4/01_门店运营标准体系/01G_体测工作流程与标准.md',

    // V4 子目录 - 02 人员管理
    'v4-02a' => 'v4/02_人员管理体系/02A_岗位说明书.md',
    'v4-02b' => 'v4/02_人员管理体系/02B_招聘流程与标准.md',
    'v4-02c' => 'v4/02_人员管理体系/02C_新员工入职培训.md',
    'v4-02d' => 'v4/02_人员管理体系/02D_教练星级晋升体系.md',
    'v4-02e' => 'v4/02_人员管理体系/02E_薪酬结构.md',
    'v4-02f' => 'v4/02_人员管理体系/02F_离职管理.md',
    'v4-02g' => 'v4/02_人员管理体系/02G_工作量管理标准.md',

    // V4 子目录 - 03 店长管理
    'v4-03a' => 'v4/03_店长管理机制/03A_店长会议管理体系.md',
    'v4-03b' => 'v4/03_店长管理机制/03B_店长数据管理体系.md',
    'v4-03c' => 'v4/03_店长管理机制/03C_店长日周月工作流.md',
    'v4-03d' => 'v4/03_店长管理机制/03D_店长巡店检查体系.md',
    'v4-03e' => 'v4/03_店长管理机制/03E_店长帮带与帮扶体系.md',
    'v4-03f' => 'v4/03_店长管理机制/03F_店长自我成长工具.md',
    'v4-03g' => 'v4/03_店长管理机制/03G_督导考核标准.md',
    'v4-03h' => 'v4/03_店长管理机制/03H_店长经营闭环总则.md',

    // V4 子目录 - 04 服务标准
    'v4-04a' => 'v4/04_服务标准体系/04A_首次到店接待标准.md',
    'v4-04b' => 'v4/04_服务标准体系/04B_家长沟通话术标准.md',
    'v4-04c' => 'v4/04_服务标准体系/04C_续费触达与跟进.md',
    'v4-04d' => 'v4/04_服务标准体系/04D_投诉处理流程.md',
    'v4-04e' => 'v4/04_服务标准体系/04E_会员首月服务跟进标准.md',
    'v4-04f' => 'v4/04_服务标准体系/04F_会员服务与续费主链路总则.md',

    // V4 子目录 - 05 教学标准
    'v4-05a' => 'v4/05_教学标准体系/05A_ACE落地执行标准.md',
    'v4-05b' => 'v4/05_教学标准体系/05B_各课程教学SOP.md',
    'v4-05c' => 'v4/05_教学标准体系/05C_学员升班考核标准.md',

    // V4 子目录 - 06 业绩管理
    'v4-06a' => 'v4/06_业绩管理体系/06A_目标分解与KDI指标.md',
    'v4-06b' => 'v4/06_业绩管理体系/06B_激励方案.md',
    'v4-06c' => 'v4/06_业绩管理体系/06C_关键节点营销.md',

    // 知识库 09A-09H
    'k-09a' => 'knowledge/09A_ACE教学理论.md',
    'k-09b' => 'knowledge/09B_儿童发展基础理论.md',
    'k-09c' => 'knowledge/09C_感觉统合理论.md',
    'k-09d' => 'knowledge/09D_七大身体素质.md',
    'k-09e' => 'knowledge/09E_各课程专业技能.md',
    'k-09f' => 'knowledge/09F_体测与评估.md',
    'k-09g' => 'knowledge/09G_教学实践技能.md',
    'k-09h' => 'knowledge/09H_安全与急救.md',
];

$docId = $_GET['doc'] ?? '';

if (!is_string($docId) || !isset($docs[$docId])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Document not found.";
    exit;
}

$baseDirs = [
    __DIR__ . '/_private_docs',
    __DIR__ . '/docs',
];

$resolvedPath = null;
foreach ($baseDirs as $baseDir) {
    $candidate = rtrim($baseDir, '/') . '/' . $docs[$docId];
    if (is_file($candidate) && is_readable($candidate)) {
        $resolvedPath = $candidate;
        break;
    }
}

if ($resolvedPath === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Document source missing.";
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: private, max-age=300');
header('X-Robots-Tag: noindex, nofollow');

$content = file_get_contents($resolvedPath);
if ($content === false) {
    http_response_code(500);
    echo "Document read failed.";
    exit;
}

echo $content;
