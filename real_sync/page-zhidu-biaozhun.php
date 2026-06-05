<?php
/*
Template Name: 制度标准
*/

add_filter('show_admin_bar', '__return_false');

$logo_url = esc_url(home_url('/玻璃贴-14.png'));

// 静态制度文件映射（直接指向 doc-viewer）
$static_rules = array(
    '总纲与原则' => array(
        array('title' => '追光小牛连锁运营体系总纲', 'doc' => 'v4-00', 'desc' => '体系总纲与核心原则'),
        array('title' => '全体系统一原则', 'doc' => 'v4-00a', 'desc' => '全系统一执行原则'),
        array('title' => '成长基金管理办法', 'doc' => 'v4-00b', 'desc' => '成长基金管理规则'),
    ),
    '门店运营标准' => array(
        array('title' => '门店运营标准体系总览', 'doc' => 'v4-01', 'desc' => '门店运营标准总纲'),
        array('title' => '开关店SOP', 'doc' => 'v4-01a', 'desc' => '每日开关店标准流程'),
        array('title' => '课前课中课后流程', 'doc' => 'v4-01b', 'desc' => '课堂全流程执行标准'),
        array('title' => '卫生与安全标准', 'doc' => 'v4-01c', 'desc' => '门店卫生与安全管理'),
        array('title' => '设备与物料管理', 'doc' => 'v4-01d', 'desc' => '设备物料管理规范'),
        array('title' => '突发事件应急处理', 'doc' => 'v4-01e', 'desc' => '突发事件处理预案'),
        array('title' => '合同管理统一规范', 'doc' => 'v4-01f', 'desc' => '合同签署与管理规范'),
        array('title' => '体测工作流程与标准', 'doc' => 'v4-01g', 'desc' => '体测执行标准流程'),
    ),
    '人员管理' => array(
        array('title' => '人员管理体系总览', 'doc' => 'v4-02', 'desc' => '人员管理总纲'),
        array('title' => '岗位说明书', 'doc' => 'v4-02a', 'desc' => '各岗位职责与要求'),
        array('title' => '招聘流程与标准', 'doc' => 'v4-02b', 'desc' => '招聘执行标准'),
        array('title' => '新员工入职培训', 'doc' => 'v4-02c', 'desc' => '入职培训标准'),
        array('title' => '教练星级晋升体系', 'doc' => 'v4-02d', 'desc' => '教练晋升路径'),
        array('title' => '薪酬结构', 'doc' => 'v4-02e', 'desc' => '薪酬体系说明'),
        array('title' => '离职管理', 'doc' => 'v4-02f', 'desc' => '离职流程规范'),
        array('title' => '工作量管理标准', 'doc' => 'v4-02g', 'desc' => '工作量管理规则'),
        array('title' => '教练培训与认证体系', 'doc' => 'v4-02h', 'desc' => '教练培训认证标准'),
        array('title' => '教练专业技能能力模型', 'doc' => 'v4-02i', 'desc' => '教练能力模型'),
        array('title' => '课堂角色授权与跟岗标准', 'doc' => 'v4-02j', 'desc' => '课堂角色与跟岗规范'),
    ),
    '店长管理机制' => array(
        array('title' => '店长管理机制总览', 'doc' => 'v4-03', 'desc' => '店长管理总纲'),
        array('title' => '店长会议管理体系', 'doc' => 'v4-03a', 'desc' => '会议管理标准'),
        array('title' => '店长数据管理体系', 'doc' => 'v4-03b', 'desc' => '数据管理规范'),
        array('title' => '店长日周月工作流', 'doc' => 'v4-03c', 'desc' => '日常工作节奏'),
        array('title' => '店长巡店检查体系', 'doc' => 'v4-03d', 'desc' => '巡店检查标准'),
        array('title' => '店长帮带与帮扶体系', 'doc' => 'v4-03e', 'desc' => '帮带帮扶机制'),
        array('title' => '店长自我成长工具', 'doc' => 'v4-03f', 'desc' => '自我成长工具包'),
        array('title' => '督导考核标准', 'doc' => 'v4-03g', 'desc' => '督导考核规范'),
        array('title' => '店长经营闭环总则', 'doc' => 'v4-03h', 'desc' => '经营闭环总纲'),
        array('title' => '店长巡课与教学质量管理标准', 'doc' => 'v4-03x', 'desc' => '巡课与质量管理'),
    ),
    '服务标准' => array(
        array('title' => '服务标准体系总览', 'doc' => 'v4-04', 'desc' => '服务标准总纲'),
        array('title' => '首次到店接待标准', 'doc' => 'v4-04a', 'desc' => '首次接待8步法'),
        array('title' => '家长沟通话术标准', 'doc' => 'v4-04b', 'desc' => '沟通话术规范'),
        array('title' => '续费触达与跟进', 'doc' => 'v4-04c', 'desc' => '续费跟进流程'),
        array('title' => '投诉处理流程', 'doc' => 'v4-04d', 'desc' => '投诉处理标准'),
        array('title' => '会员首月服务跟进标准', 'doc' => 'v4-04e', 'desc' => '首月服务跟进'),
        array('title' => '会员服务与续费主链路总则', 'doc' => 'v4-04f', 'desc' => '服务续费总纲'),
    ),
    '教学标准' => array(
        array('title' => '教学标准体系总览', 'doc' => 'v4-05', 'desc' => '教学标准总纲'),
        array('title' => 'ACE落地执行标准', 'doc' => 'v4-05a', 'desc' => 'ACE体系执行'),
        array('title' => '各课程教学SOP', 'doc' => 'v4-05b', 'desc' => '课程教学标准'),
        array('title' => '学员升班考核标准', 'doc' => 'v4-05c', 'desc' => '升班考核规范'),
        array('title' => '教练与助教上课执行标准', 'doc' => 'v4-05d', 'desc' => '课堂执行标准'),
        array('title' => '课堂安全保护与异常处理标准', 'doc' => 'v4-05e', 'desc' => '课堂安全标准'),
        array('title' => '课后记录与家长反馈标准', 'doc' => 'v4-05f', 'desc' => '课后反馈规范'),
    ),
    '业绩管理' => array(
        array('title' => '业绩管理体系总览', 'doc' => 'v4-06', 'desc' => '业绩管理总纲'),
        array('title' => '目标分解与KDI指标', 'doc' => 'v4-06a', 'desc' => '目标与指标管理'),
        array('title' => '激励方案', 'doc' => 'v4-06b', 'desc' => '激励制度'),
        array('title' => '关键节点营销', 'doc' => 'v4-06c', 'desc' => '营销活动节点'),
    ),
    '品牌标准' => array(
        array('title' => '品牌一致性标准', 'doc' => 'v4-07', 'desc' => '品牌标准规范'),
    ),
);

// 角色映射
$role_map = array(
    '店长' => array('总纲与原则', '门店运营标准', '店长管理机制', '业绩管理', '品牌标准'),
    '教练' => array('总纲与原则', '教学标准', '服务标准', '人员管理'),
    '顾问' => array('总纲与原则', '服务标准', '门店运营标准', '业绩管理'),
    '督导' => array('总纲与原则', '店长管理机制', '业绩管理', '品牌标准', '门店运营标准'),
);

// 工作流映射
$workflow_map = array(
    '门店运营链' => array('门店运营标准', '品牌标准'),
    '会员服务链' => array('服务标准', '业绩管理'),
    '教学上岗链' => array('教学标准', '人员管理'),
);

// 常用制度
$featured_rules = array(
    array('title' => '追光小牛连锁运营体系总纲', 'doc' => 'v4-00', 'tag' => '总纲'),
    array('title' => '门店运营标准体系', 'doc' => 'v4-01', 'tag' => '运营'),
    array('title' => '服务标准体系', 'doc' => 'v4-04', 'tag' => '服务'),
    array('title' => '教学标准体系', 'doc' => 'v4-05', 'tag' => '教学'),
    array('title' => '人员管理体系', 'doc' => 'v4-02', 'tag' => '人事'),
    array('title' => '店长管理机制', 'doc' => 'v4-03', 'tag' => '管理'),
);

$total_rules = 0;
foreach ($static_rules as $group) {
    $total_rules += count($group);
}

$doc_viewer_base = home_url('/doc-viewer.html');
?\u003e
<!doctype html>
<html \u003c?php language_attributes(); ?\u003e\u003e
  \u003chead\u003e
    \u003cmeta charset="\u003c?php bloginfo('charset'); ?\u003e" /\u003e
    \u003cmeta name="viewport" content="width=device-width, initial-scale=1.0" /\u003e
    \u003c?php wp_head(); ?\u003e
    \u003cstyle\u003e
      :root { --brand-orange: #ff6b35; --brand-orange-deep: #e85a28; --brand-ink: #1f1a17; --brand-muted: #6b625c; --panel-shadow: rgba(0,0,0,0.06) 0px 14px 36px, rgba(0,0,0,0.03) 0px 4px 12px; }
      * { box-sizing: border-box; }
      html { scroll-behavior: smooth; margin-top: 0 !important; }
      body { margin: 0; font-family: Inter, -apple-system, BlinkMacSystemFont, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif; color: var(--brand-ink); background: linear-gradient(180deg, #fffaf6 0%, #fff 30%, #fdfaf7 100%); }
      #wpadminbar { display: none !important; }
      a { color: inherit; text-decoration: none; }
      .shell { width: min(calc(100% - 32px), 1180px); margin: 0 auto; }
      .site-header { position: sticky; top: 0; z-index: 1000; background: rgba(255,251,248,0.86); backdrop-filter: blur(18px); border-bottom: 1px solid rgba(31,26,23,0.06); }
      .topbar { min-height: 76px; display: flex; align-items: center; justify-content: space-between; gap: 24px; }
      .brand { display: inline-flex; align-items: center; gap: 12px; font-weight: 700; }
      .brand img { width: 42px; height: 42px; object-fit: contain; }
      .nav { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
      .nav a { padding: 9px 12px; border-radius: 10px; font-size: 14px; color: var(--brand-muted); }
      .nav a:hover, .nav a.current { background: rgba(255,107,53,0.1); color: var(--brand-orange); }
      .staff-link { background: rgba(255,107,53,0.1); color: var(--brand-orange) !important; font-weight: 600; }
      .hero { padding: 34px 0 18px; }
      .hero-card { padding: 28px; display: grid; grid-template-columns: 1.12fr 0.88fr; gap: 20px; background: radial-gradient(circle at top right, rgba(255,107,53,0.16), transparent 26%), linear-gradient(160deg, #fff4ec 0%, #fffdfa 58%, #ffffff 100%); border: 1px solid rgba(31,26,23,0.08); border-radius: 22px; box-shadow: var(--panel-shadow); }
      .eyebrow { display: inline-block; font-size: 12px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: #9e9289; }
      h1, h2, h3 { margin: 10px 0 0; letter-spacing: -0.04em; }
      h1 { font-size: clamp(32px, 4.8vw, 48px); line-height: 1.06; }
      h2 { font-size: clamp(24px, 3vw, 30px); line-height: 1.14; }
      h3 { font-size: 18px; line-height: 1.3; }
      p { color: var(--brand-muted); line-height: 1.85; }
      .hero-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-self: end; }
      .stat-card { padding: 18px; border-radius: 18px; background: rgba(255,255,255,0.84); border: 1px solid rgba(31,26,23,0.06); }
      .stat-card strong { display: block; margin-top: 8px; font-size: 26px; }
      .section { padding: 14px 0 72px; }
      .section-title { margin-bottom: 14px; }
      .entry-grid, .post-grid, .overview-strip { display: grid; gap: 16px; }
      .overview-strip { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 18px; }
      .entry-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 18px; }
      .entry-card, .overview-card, .post-link-card, .rule-card { border: 1px solid rgba(31,26,23,0.08); background: rgba(255,255,255,0.94); border-radius: 22px; box-shadow: var(--panel-shadow); }
      .entry-card { padding: 22px; transition: transform 0.18s ease, box-shadow 0.18s ease; }
      .entry-card:hover, .post-link-card:hover, .rule-card:hover { transform: translateY(-2px); box-shadow: rgba(0,0,0,0.08) 0px 18px 40px, rgba(0,0,0,0.03) 0px 4px 12px; }
      .overview-card { padding: 20px; }
      .post-link-card { padding: 20px; transition: transform 0.18s ease, box-shadow 0.18s ease; display: block; }
      .post-link-card small { display: block; margin-top: 6px; color: #9e9289; }
      .featured-strip { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
      .search-box { margin: 22px 0 16px; }
      .search-box input { width: 100%; border: 1px solid rgba(0,0,0,0.08); border-radius: 14px; padding: 14px 16px; font-size: 14px; }
      .groups-stack { display: grid; gap: 14px; }
      .group-card { overflow: hidden; border: 1px solid rgba(31,26,23,0.08); background: rgba(255,255,255,0.94); border-radius: 22px; box-shadow: var(--panel-shadow); }
      .group-header { width: 100%; border: 0; background: transparent; padding: 20px 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; text-align: left; cursor: pointer; }
      .group-left { display: flex; align-items: center; gap: 12px; }
      .group-count { min-width: 30px; height: 30px; border-radius: 9999px; display: inline-flex; align-items: center; justify-content: center; background: rgba(255,107,53,0.1); color: var(--brand-orange); font-size: 13px; font-weight: 700; }
      .group-toggle { color: #a39e98; font-size: 14px; transition: transform 0.2s ease; }
      .group-card.collapsed .group-toggle { transform: rotate(-90deg); }
      .group-body { padding: 0 18px 18px; }
      .group-card.collapsed .group-body { display: none; }
      .post-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .rule-card { padding: 18px; transition: transform 0.18s ease, box-shadow 0.18s ease; display: block; }
      .rule-card .tag { display: inline-block; padding: 4px 10px; border-radius: 8px; background: rgba(255,107,53,0.1); color: var(--brand-orange); font-size: 12px; font-weight: 600; margin-bottom: 8px; }
      .rule-card h3 { font-size: 16px; margin: 0 0 6px; }
      .rule-card p { font-size: 13px; margin: 0; color: var(--brand-muted); }
      .open-link { display: inline-flex; margin-top: 12px; color: var(--brand-orange); font-size: 13px; font-weight: 700; }
      .site-footer { padding: 28px 0 40px; border-top: 1px solid rgba(31,26,23,0.08); }
      .site-footer p { margin: 0; text-align: center; color: var(--brand-muted); }
      [hidden] { display: none !important; }
      @media (max-width: 980px) { .hero-card, .overview-strip, .entry-grid, .featured-strip, .post-grid { grid-template-columns: 1fr; } }
      @media (max-width: 760px) { .topbar { align-items: flex-start; flex-direction: column; padding: 12px 0; } .nav { justify-content: flex-start; } }
    \u003c/style\u003e
  \u003c/head\u003e
  \u003cbody \u003c?php body_class(); ?\u003e\u003e
    \u003c?php wp_body_open(); ?\u003e
    \u003cheader class="site-header"\u003e
      \u003cdiv class="shell topbar"\u003e
        \u003ca class="brand" href="\u003c?php echo esc_url(home_url('/internal.html')); ?\u003e"\u003e
          \u003cimg src="\u003c?php echo $logo_url; ?\u003e" alt="追光小牛 logo" /\u003e
          \u003cspan\u003e追光小牛\u003c/span\u003e
        \u003c/a\u003e
        \u003cnav class="nav"\u003e
          \u003ca href="\u003c?php echo esc_url(home_url('/internal.html')); ?\u003e"\u003e员工首页\u003c/a\u003e
          \u003ca href="\u003c?php echo esc_url(home_url('/表格中心/')); ?\u003e"\u003e表格中心\u003c/a\u003e
          \u003ca class="current" href="\u003c?php echo esc_url(get_permalink()); ?\u003e"\u003e制度中心\u003c/a\u003e
          \u003ca href="\u003c?php echo esc_url(home_url('/知识库/')); ?\u003e"\u003e知识库\u003c/a\u003e
          \u003ca href="\u003c?php echo esc_url(home_url('/新员工学习/')); ?\u003e"\u003e学习中心\u003c/a\u003e
          \u003ca href="\u003c?php echo esc_url(home_url('/fitness-assessment.html')); ?\u003e"\u003e运动规划\u003c/a\u003e
          \u003ca href="\u003c?php echo esc_url(home_url('/smart-lessons.html')); ?\u003e"\u003e智能教案\u003c/a\u003e
          \u003ca class="staff-link" href="\u003c?php echo esc_url(home_url('/')); ?\u003e"\u003e返回官网\u003c/a\u003e
        \u003c/nav\u003e
      \u003c/div\u003e
    \u003c/header\u003e

    \u003cmain\u003e
      \u003csection class="hero"\u003e
        \u003cdiv class="shell hero-card"\u003e
          \u003cdiv\u003e
            \u003cspan class="eyebrow"\u003e员工内网 / 制度中心\u003c/span\u003e
            \u003ch1\u003e制度中心\u003c/h1\u003e
            \u003cp\u003e按角色或工作流查制度，点击进入正文阅读。\u003c/p\u003e
          \u003c/div\u003e
          \u003cdiv class="hero-stats"\u003e
            \u003carticle class="stat-card"\u003e
              \u003cspan class="eyebrow"\u003e制度分类\u003c/span\u003e
              \u003cstrong\u003e\u003c?php echo count($static_rules); ?\u003e\u003c/strong\u003e
              \u003cp\u003e制度模块数\u003c/p\u003e
            \u003c/article\u003e
            \u003carticle class="stat-card"\u003e
              \u003cspan class="eyebrow"\u003e制度文件\u003c/span\u003e
              \u003cstrong\u003e\u003c?php echo $total_rules; ?\u003e\u003c/strong\u003e
              \u003cp\u003e可查阅文件数\u003c/p\u003e
            \u003c/article\u003e
          \u003c/div\u003e
        \u003c/div\u003e
      \u003c/section\u003e

      \u003csection class="section"\u003e
        \u003cdiv class="shell"\u003e
          \u003c!-- 角色入口 --\u003e
          \u003cdiv class="section-title"\u003e
            \u003cspan class="eyebrow"\u003e按角色进入\u003c/span\u003e
            \u003ch2\u003e选择您的角色\u003c/h2\u003e
          \u003c/div\u003e
          \u003cdiv class="entry-grid"\u003e
            \u003c?php foreach ($role_map as $role_name => $role_groups) : ?\u003e
              \u003ca class="entry-card" href="#role-\u003c?php echo esc_attr(str_replace(array(' ','/'), '-', $role_name)); ?\u003e"\u003e
                \u003cspan class="eyebrow"\u003e角色入口\u003c/span\u003e
                \u003ch3\u003e\u003c?php echo esc_html($role_name); ?\u003e制度清单\u003c/h3\u003e
                \u003cp\u003e查看\u003c?php echo esc_html(implode('、', $role_groups)); ?\u003e相关制度。\u003c/p\u003e
              \u003c/a\u003e
            \u003c?php endforeach; ?\u003e
          \u003c/div\u003e

          \u003c!-- 工作流入口 --\u003e
          \u003cdiv class="section-title"\u003e
            \u003cspan class="eyebrow"\u003e按工作流进入\u003c/span\u003e
            \u003ch2\u003e选择工作链路\u003c/h2\u003e
          \u003c/div\u003e
          \u003cdiv class="entry-grid"\u003e
            \u003c?php foreach ($workflow_map as $flow_name => $flow_groups) : ?\u003e
              \u003ca class="entry-card" href="#flow-\u003c?php echo esc_attr(str_replace(array(' ','/'), '-', $flow_name)); ?\u003e"\u003e
                \u003cspan class="eyebrow"\u003e工作流入口\u003c/span\u003e
                \u003ch3\u003e\u003c?php echo esc_html($flow_name); ?\u003e\u003c/h3\u003e
                \u003cp\u003e\u003c?php echo esc_html(implode('、', $flow_groups)); ?\u003e相关制度。\u003c/p\u003e
              \u003c/a\u003e
            \u003c?php endforeach; ?\u003e
          \u003c/div\u003e

          \u003c!-- 角色制度列表 --\u003e
          \u003cdiv class="section-title"\u003e
            \u003cspan class="eyebrow"\u003e角色制度列表\u003c/span\u003e
            \u003ch2\u003e按角色查看制度\u003c/h2\u003e
          \u003c/div\u003e
          \u003c?php foreach ($role_map as $role_name => $role_groups) : ?\u003e
            \u003cdiv id="role-\u003c?php echo esc_attr(str_replace(array(' ','/'), '-', $role_name)); ?\u003e" class="group-card" style="margin-bottom:16px;"\u003e
              \u003cbutton class="group-header" type="button" onclick="this.parentElement.classList.toggle('collapsed')"\u003e
                \u003cdiv class="group-left"\u003e
                  \u003cspan class="group-count"\u003e\u003c?php
                    $count = 0;
                    foreach ($role_groups as $g) { if (isset($static_rules[$g])) $count += count($static_rules[$g]); }
                    echo $count;
                  ?\u003e\u003c/span\u003e
                  \u003cdiv\u003e
                    \u003cspan class="eyebrow"\u003e角色制度\u003c/span\u003e
                    \u003ch2\u003e\u003c?php echo esc_html($role_name); ?\u003e制度清单\u003c/h2\u003e
                  \u003c/div\u003e
                \u003c/div\u003e
                \u003cspan class="group-toggle"\u003e\u003c/span\u003e
              \u003c/button\u003e
              \u003cdiv class="group-body"\u003e
                \u003cdiv class="post-grid"\u003e
                  \u003c?php foreach ($role_groups as $group_name) : ?\u003e
                    \u003c?php if (isset($static_rules[$group_name])) : ?\u003e
                      \u003c?php foreach ($static_rules[$group_name] as $rule) : ?\u003e
                        \u003ca class="rule-card" href="\u003c?php echo esc_url($doc_viewer_base . '?doc=' . $rule['doc']); ?\u003e" data-item data-name="\u003c?php echo esc_attr($rule['title']); ?\u003e"\u003e
                          \u003cspan class="tag"\u003e\u003c?php echo esc_html($group_name); ?\u003e\u003c/span\u003e
                          \u003ch3\u003e\u003c?php echo esc_html($rule['title']); ?\u003e\u003c/h3\u003e
                          \u003cp\u003e\u003c?php echo esc_html($rule['desc']); ?\u003e\u003c/p\u003e
                          \u003cspan class="open-link"\u003e进入阅读 →\u003c/span\u003e
                        \u003c/a\u003e
                      \u003c?php endforeach; ?\u003e
                    \u003c?php endif; ?\u003e
                  \u003c?php endforeach; ?\u003e
                \u003c/div\u003e
              \u003c/div\u003e
            \u003c/div\u003e
          \u003c?php endforeach; ?\u003e

          \u003c!-- 工作流制度列表 --\u003e
          \u003cdiv class="section-title"\u003e
            \u003cspan class="eyebrow"\u003e工作流制度列表\u003c/span\u003e
            \u003ch2\u003e按工作流查看制度\u003c/h2\u003e
          \u003c/div\u003e
          \u003c?php foreach ($workflow_map as $flow_name => $flow_groups) : ?\u003e
            \u003cdiv id="flow-\u003c?php echo esc_attr(str_replace(array(' ','/'), '-', $flow_name)); ?\u003e" class="group-card" style="margin-bottom:16px;"\u003e
              \u003cbutton class="group-header" type="button" onclick="this.parentElement.classList.toggle('collapsed')"\u003e
                \u003cdiv class="group-left"\u003e
                  \u003cspan class="group-count"\u003e\u003c?php
                    $count = 0;
                    foreach ($flow_groups as $g) { if (isset($static_rules[$g])) $count += count($static_rules[$g]); }
                    echo $count;
                  ?\u003e\u003c/span\u003e
                  \u003cdiv\u003e
                    \u003cspan class="eyebrow"\u003e工作流制度\u003c/span\u003e
                    \u003ch2\u003e\u003c?php echo esc_html($flow_name); ?\u003e\u003c/h2\u003e
                  \u003c/div\u003e
                \u003c/div\u003e
                \u003cspan class="group-toggle"\u003e\u003c/span\u003e
              \u003c/button\u003e
              \u003cdiv class="group-body"\u003e
                \u003cdiv class="post-grid"\u003e
                  \u003c?php foreach ($flow_groups as $group_name) : ?\u003e
                    \u003c?php if (isset($static_rules[$group_name])) : ?\u003e
                      \u3c?php foreach ($static_rules[$group_name] as $rule) : ?\u003e
                        \u003ca class="rule-card" href="\u003c?php echo esc_url($doc_viewer_base . '?doc=' . $rule['doc']); ?\u003e" data-item data-name="\u003c?php echo esc_attr($rule['title']); ?\u003e"\u003e
                          \u003cspan class="tag"\u003e\u003c?php echo esc_html($group_name); ?\u003e\u003c/span\u003e
                          \u003ch3\u003e\u003c?php echo esc_html($rule['title']); ?\u003e\u003c/h3\u003e
                          \u003cp\u003e\u003c?php echo esc_html($rule['desc']); ?\u003e\u003c/p\u003e
                          \u003cspan class="open-link"\u003e进入阅读 →\u003c/span\u003e
                        \u003c/a\u003e
                      \u003c?php endforeach; ?\u003e
                    \u003c?php endif; ?\u003e
                  \u003c?php endforeach; ?\u003e
                \u003c/div\u003e
              \u003c/div\u003e
            \u003c/div\u003e
          \u003c?php endforeach; ?\u003e

          \u003c!-- 常用制度 --\u003e
          \u003cdiv class="section-title"\u003e
            \u003cspan class="eyebrow"\u003e常用制度\u003c/span\u003e
            \u003ch2\u003e高频查阅制度\u003c/h2\u003e
          \u003c/div\u003e
          \u003cdiv class="featured-strip"\u003e
            \u003c?php foreach ($featured_rules as $rule) : ?\u003e
              \u003ca class="post-link-card" href="\u003c?php echo esc_url($doc_viewer_base . '?doc=' . $rule['doc']); ?\u003e"\u003e
                \u003cspan class="eyebrow"\u003e\u003c?php echo esc_html($rule['tag']); ?\u003e\u003c/span\u003e
                \u003ch3\u003e\u003c?php echo esc_html($rule['title']); ?\u003e\u003c/h3\u003e
                \u003csmall\u003e进入阅读 →\u003c/small\u003e
              \u003c/a\u003e
            \u003c?php endforeach; ?\u003e
          \u003c/div\u003e

          \u003c!-- 全部制度搜索 --\u003e
          \u003cdiv class="section-title"\u003e
            \u003cspan class="eyebrow"\u003e全部制度\u003c/span\u003e
            \u003ch2\u003e搜索与浏览全部制度\u003c/h2\u003e
          \u003c/div\u003e
          \u003cdiv class="search-box"\u003e
            \u003cinput id="ruleSearch" type="text" placeholder="搜索制度名称，例如：续费、开店、招聘、教学、巡店" /\u003e
          \u003c/div\u003e
          \u003cdiv class="groups-stack"\u003e
            \u003c?php foreach ($static_rules as $group_name => $rules) : ?\u003e
              \u003cdiv class="group-card" data-group\u003e
                \u003cbutton class="group-header" type="button" onclick="this.parentElement.classList.toggle('collapsed')"\u003e
                  \u003cdiv class="group-left"\u003e
                    \u003cspan class="group-count"\u003e\u003c?php echo count($rules); ?\u003e\u003c/span\u003e
                    \u003cdiv\u003e
                      \u003cspan class="eyebrow"\u003e制度分类\u003c/span\u003e
                      \u003ch2\u003e\u003c?php echo esc_html($group_name); ?\u003e\u003c/h2\u003e
                    \u003c/div\u003e
                  \u003c/div\u003e
                  \u003cspan class="group-toggle"\u003e▾\u003c/span\u003e
                \u003c/button\u003e
                \u003cdiv class="group-body"\u003e
                  \u003cdiv class="post-grid"\u003e
                    \u003c?php foreach ($rules as $rule) : ?\u003e
                      \u003ca class="rule-card" href="\u003c?php echo esc_url($doc_viewer_base . '?doc=' . $rule['doc']); ?\u003e" data-item data-name="\u003c?php echo esc_attr($rule['title']); ?\u003e"\u003e
                        \u003cspan class="tag"\u003e\u003c?php echo esc_html($group_name); ?\u003e\u003c/span\u003e
                        \u003ch3\u003e\u003c?php echo esc_html($rule['title']); ?\u003e\u003c/h3\u003e
                        \u003cp\u003e\u003c?php echo esc_html($rule['desc']); ?\u003e\u003c/p\u003e
                        \u003cspan class="open-link"\u003e进入阅读 →\u003c/span\u003e
                      \u003c/a\u003e
                    \u003c?php endforeach; ?\u003e
                  \u003c/div\u003e
                \u003c/div\u003e
              \u003c/div\u003e
            \u003c?php endforeach; ?\u003e
          \u003c/div\u003e
        \u003c/div\u003e
      \u003c/section\u003e
    \u003c/main\u003e

    \u003cfooter class="site-footer"\u003e
      \u003cdiv class="shell"\u003e
        \u003cp\u003e追光小牛制度中心 · V4.3 完整制度已同步\u003c/p\u003e
      \u003c/div\u003e
    \u003c/footer\u003e

    \u003cscript\u003e
      document.getElementById('ruleSearch').addEventListener('input', function() {
        var keyword = this.value.trim().toLowerCase();
        document.querySelectorAll('[data-item]').forEach(function(item) {
          var name = item.dataset.name.toLowerCase();
          item.hidden = keyword && !name.includes(keyword);
        });
        document.querySelectorAll('[data-group]').forEach(function(group) {
          var visible = group.querySelectorAll('[data-item]:not([hidden])').length;
          group.hidden = visible === 0;
        });
      });
    \u003c/script\u003e
    \u003c?php wp_footer(); ?\u003e
  \u003c/body\u003e
\u003c/html\u003e
