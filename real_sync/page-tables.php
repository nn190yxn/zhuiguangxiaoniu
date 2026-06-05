<?php
/*
Template Name: 表格中心
*/

add_filter('show_admin_bar', '__return_false');
add_filter(
    'pre_get_document_title',
    static function () {
        return '表格中心';
    }
);

$logo_url = esc_url(home_url('/玻璃贴-14.png'));
$tables_dir = trailingslashit(WP_CONTENT_DIR . '/uploads/tables');
$table_files = glob($tables_dir . '*.{xls,xlsx,csv}', GLOB_BRACE);

$category_map = array(
    '综合执行与推进' => array(
        '制度上线签收与抽测记录表',
        '门店周执行红黄绿看板',
        '风险会员升级台账',
        '关键节点漏执行升级表',
        '总经理周推进验收表',
        '阶段推进与冲刺复盘表',
    ),
    '门店运营、安全与合同' => array(
        '门店卫生与消毒执行记录表',
        '资产与耗材综合台账',
        '安全巡检与应急物资检查表',
        '课前准备检查表',
        '突发事件记录表',
        '合同检查清单',
        '退费申请表',
        '停卡申请表',
        '转卡申请表',
        '门店形象检查表',
        '开店检查表',
        '关店检查表',
    ),
    '销售与会员服务' => array(
        '体测计划通知与执行表',
        '新客接待与转化跟踪总表',
        '会员沟通与续费管理总表',
        '投诉处理与月度复盘表',
        '体测数据记录表',
        '体测报告解读记录表',
    ),
    '教学与教练专业' => array(
        '教练星级评定与异动记录表',
        'ACE评估与教学成长档案表',
        '升班与学员异动评估表',
        '教案模板',
        '随堂听课评分表',
    ),
    '店长与督导经营管理' => array(
        '班次会议记录表',
        '门店经营月度复盘总表',
        '店长工作流与帮带跟进表',
        '巡店检查与复查验收表',
        '店长开会能力自评表',
        '今日数据看板',
    ),
    '招聘、培训与人事' => array(
        '人员增补申请与招聘跟进表',
        '工作量周管理台账',
        '各岗位底薪速查表',
        '教练团课课时费速查表',
        '教练业绩提成阶梯速查表',
        '顾问提成阶梯速查表',
        '培训抽测与上岗验收表',
        '岗位说明书签署确认表',
        '培训签到表',
        '试用期考核表',
        '离职交接表',
        '离职面谈记录表',
    ),
    '业绩、营销与激励' => array(
        '成长基金综合管理台账',
        '业绩激励与奖惩核算总表',
    ),
    '品牌与线上运营' => array(
        '线上平台运营周报',
        '品牌物料管理表',
    ),
);

$name_to_category = array();
foreach ($category_map as $category_name => $names) {
    foreach ($names as $name) {
        $name_to_category[$name] = $category_name;
    }
}

$grouped_tables = array();
foreach (array_keys($category_map) as $category_name) {
    $grouped_tables[$category_name] = array();
}
$grouped_tables['未分类'] = array();

$formats = array();
if ($table_files) {
    sort($table_files, SORT_NATURAL);

    foreach ($table_files as $file_path) {
        $file_name = basename($file_path);
        $display_name = preg_replace('/^\d+_/', '', $file_name);
        $display_name = preg_replace('/\.[^.]+$/', '', $display_name);
        $extension = strtoupper(pathinfo($file_name, PATHINFO_EXTENSION));
        $formats[$extension] = true;
        $category_name = isset($name_to_category[$display_name]) ? $name_to_category[$display_name] : '未分类';

        $grouped_tables[$category_name][] = array(
            'name' => $display_name,
            'file_name' => $file_name,
            'format' => $extension,
            'size' => size_format(filesize($file_path)),
            'updated_at' => date_i18n('Y-m-d', filemtime($file_path)),
            'url' => content_url('uploads/tables/' . rawurlencode($file_name)),
        );
    }
}

$visible_groups = array();
$total_tables = 0;
foreach ($grouped_tables as $category_name => $items) {
    if (!$items) {
        continue;
    }

    $visible_groups[$category_name] = $items;
    $total_tables += count($items);
}

$module_links = array(
    array(
        'eyebrow' => '关联模块',
        'title' => '去制度中心确认边界',
        'desc' => '找到要执行的表格后，回到制度中心确认它对应的规则正文、适用场景和执行边界。',
        'url' => home_url('/制度标准/'),
    ),
    array(
        'eyebrow' => '怎么落地',
        'title' => '去知识库看案例与话术',
        'desc' => '如果只知道要填什么，还不知道怎么做、怎么解释，就继续去知识库补实际案例和常见处理方式。',
        'url' => home_url('/知识库/'),
    ),
    array(
        'eyebrow' => '形成闭环',
        'title' => '去员工学习中心完成培训',
        'desc' => '涉及培训、抽测、上岗验收的表格，最后统一回到学习中心完成阅读、验收和复盘闭环。',
        'url' => home_url('/新员工学习/'),
    ),
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
  <head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <?php wp_head(); ?>
    <style>
      :root {
        --brand-orange: #ff6b35;
        --brand-orange-deep: #e85a28;
        --brand-ink: #1f1a17;
        --brand-muted: #6b625c;
        --brand-line: rgba(31, 26, 23, 0.1);
        --panel-shadow: rgba(0, 0, 0, 0.06) 0px 14px 36px, rgba(0, 0, 0, 0.03) 0px 4px 12px;
      }
      * { box-sizing: border-box; }
      html { scroll-behavior: smooth; margin-top: 0 !important; }
      body {
        margin: 0;
        font-family: Inter, -apple-system, BlinkMacSystemFont, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
        color: var(--brand-ink);
        background: linear-gradient(180deg, #fffaf6 0%, #fff 32%, #fdf9f6 100%);
      }
      #wpadminbar { display: none !important; }
      a { color: inherit; text-decoration: none; }
      .shell { width: min(calc(100% - 32px), 1180px); margin: 0 auto; }
      .site-header { position: sticky; top: 0; z-index: 1000; background: rgba(255, 251, 248, 0.86); backdrop-filter: blur(18px); border-bottom: 1px solid rgba(31, 26, 23, 0.06); }
      .topbar { min-height: 76px; display: flex; align-items: center; justify-content: space-between; gap: 24px; }
      .brand { display: inline-flex; align-items: center; gap: 12px; font-weight: 700; }
      .brand img { width: 42px; height: 42px; object-fit: contain; }
      .nav { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
      .nav a { padding: 9px 12px; border-radius: 10px; font-size: 14px; color: var(--brand-muted); }
      .nav a:hover, .nav a.current { background: rgba(255, 107, 53, 0.1); color: var(--brand-orange); }
      .staff-link { background: rgba(255, 107, 53, 0.1); color: var(--brand-orange) !important; font-weight: 600; }
      .hero { padding: 34px 0 18px; }
      .hero-card, .section-card, .table-card, .jump-link, .overview-card {
        border: 1px solid rgba(31, 26, 23, 0.08); background: rgba(255, 255, 255, 0.94); border-radius: 22px; box-shadow: var(--panel-shadow);
      }
      .hero-card {
        padding: 28px; display: grid; grid-template-columns: 1.12fr 0.88fr; gap: 20px;
        background: radial-gradient(circle at top right, rgba(255, 107, 53, 0.16), transparent 26%), linear-gradient(160deg, #fff4ec 0%, #fffdfa 58%, #ffffff 100%);
      }
      .eyebrow { display: inline-block; font-size: 12px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: #9e9289; }
      h1, h2, h3 { margin: 10px 0 0; letter-spacing: -0.04em; }
      h1 { font-size: clamp(32px, 4.8vw, 48px); line-height: 1.06; }
      h2 { font-size: clamp(24px, 3vw, 30px); line-height: 1.14; }
      h3 { font-size: 18px; line-height: 1.3; }
      p { color: var(--brand-muted); line-height: 1.85; }
      .hero-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-self: end; }
      .stat-card { padding: 18px; border-radius: 18px; background: rgba(255, 255, 255, 0.84); border: 1px solid rgba(31, 26, 23, 0.06); }
      .stat-card strong { display: block; margin-top: 8px; font-size: 26px; }
      .section { padding: 14px 0 72px; }
      .section-title { margin-bottom: 14px; }
      .overview-strip { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-bottom: 18px; }
      .overview-card { padding: 20px; }
      .jump-links, .table-grid { display: grid; gap: 16px; }
      .jump-links { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 18px; }
      .jump-link { padding: 18px; scroll-margin-top: 96px; }
      .search-box { margin: 18px 0 22px; }
      .search-box input { width: 100%; border: 1px solid rgba(0, 0, 0, 0.08); border-radius: 14px; padding: 14px 16px; font-size: 14px; }
      .section-card { padding: 24px; margin-bottom: 18px; }
      .table-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .table-card { padding: 20px; background: #fffaf7; }
      .table-top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
      .format-badge { display: inline-flex; min-height: 28px; padding: 0 12px; border-radius: 9999px; align-items: center; background: rgba(255, 107, 53, 0.1); color: var(--brand-orange); font-size: 12px; font-weight: 700; }
      .meta-row { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; color: #9e9289; font-size: 13px; }
      .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px; }
      .action-link { display: inline-flex; align-items: center; min-height: 40px; padding: 0 14px; border-radius: 12px; font-size: 14px; font-weight: 700; }
      .action-primary { background: var(--brand-orange); color: #fff; }
      .action-secondary { background: rgba(255, 107, 53, 0.1); color: var(--brand-orange); }
      .site-footer { padding: 28px 0 40px; border-top: 1px solid rgba(31, 26, 23, 0.08); }
      .site-footer p { margin: 0; text-align: center; color: var(--brand-muted); }
      [hidden] { display: none !important; }
      @media (max-width: 980px) { .hero-card, .overview-strip, .jump-links, .table-grid { grid-template-columns: 1fr; } }
      @media (max-width: 760px) { .topbar { align-items: flex-start; flex-direction: column; padding: 12px 0; } .nav { justify-content: flex-start; } .table-top { flex-direction: column; } }
    </style>
  </head>
  <body <?php body_class(); ?>>
    <?php wp_body_open(); ?>
    <header class="site-header">
      <div class="shell topbar">
        <a class="brand" href="<?php echo esc_url(home_url('/')); ?>">
          <img src="<?php echo $logo_url; ?>" alt="追光小牛 logo" />
          <span>追光小牛</span>
        </a>
        <nav class="nav">
          <a href="<?php echo esc_url(home_url('/internal.html')); ?>">员工首页</a>
          <a class="current" href="<?php echo esc_url(home_url('/表格中心/')); ?>">表格中心</a>
          <a href="<?php echo esc_url(home_url('/制度标准/')); ?>">制度中心</a>
          <a href="<?php echo esc_url(home_url('/知识库/')); ?>">知识库</a>
          <a href="<?php echo esc_url(home_url('/新员工学习/')); ?>">员工学习中心</a>
          <a href="<?php echo esc_url(home_url('/fitness-assessment.html')); ?>">智能运动规划</a>
          <a href="<?php echo esc_url(home_url('/smart-lessons.html')); ?>">智能教案</a>
          <a class="staff-link" href="<?php echo esc_url(home_url('/')); ?>">返回官网</a>
        </nav>
      </div>
    </header>

    <main>
      <section class="hero">
        <div class="shell hero-card">
          <div>
          <span class="eyebrow">员工内网 / 表格中心</span>
          <h1>按分类查表格</h1>
          <p>直接搜索、打开或下载。</p>
          </div>
          <div class="hero-stats">
            <article class="stat-card">
              <span class="eyebrow">真实表格</span>
              <strong><?php echo esc_html((string) $total_tables); ?></strong>
              <p>当前文件数</p>
            </article>
            <article class="stat-card">
              <span class="eyebrow">分类数量</span>
              <strong><?php echo esc_html((string) count($visible_groups)); ?></strong>
              <p>当前分类数</p>
            </article>
            <article class="stat-card">
              <span class="eyebrow">文件格式</span>
              <strong><?php echo esc_html(implode(' / ', array_keys($formats))); ?></strong>
              <p>文件格式</p>
            </article>
            <article class="stat-card">
              <span class="eyebrow">使用方式</span>
              <strong>双动作</strong>
              <p>打开 / 下载</p>
            </article>
          </div>
        </div>
      </section>

      <section class="section">
        <div class="shell">
          <div class="section-title">
            <span class="eyebrow">快速定位</span>
            <h2>按分类直接进入</h2>
          </div>
          <div class="jump-links">
            <?php foreach ($visible_groups as $category_name => $items) : ?>
              <a class="jump-link" href="#<?php echo esc_attr(sanitize_title($category_name)); ?>">
                <span class="eyebrow">分类入口</span>
                <h3><?php echo esc_html($category_name); ?></h3>
                <p><?php echo esc_html((string) count($items)); ?> 个文件</p>
              </a>
            <?php endforeach; ?>
          </div>

          <div class="section-title">
            <span class="eyebrow">搜索与列表</span>
            <h2>也可以直接用搜索和完整分类清单</h2>
          </div>

          <div class="search-box">
            <input id="tableSearch" type="text" placeholder="搜索表格名称，例如：巡店、续费、培训、招聘、教案" />
          </div>

          <?php foreach ($visible_groups as $category_name => $items) : ?>
            <section id="<?php echo esc_attr(sanitize_title($category_name)); ?>" class="section-card" data-group>
              <div class="section-title">
                <span class="eyebrow">表格分类</span>
                <h2><?php echo esc_html($category_name); ?></h2>
                <p><?php echo esc_html((string) count($items)); ?> 个文件</p>
              </div>
              <div class="table-grid">
                <?php foreach ($items as $item) : ?>
                  <article class="table-card" data-item data-name="<?php echo esc_attr($item['name']); ?>">
                    <div class="table-top">
                      <div>
                        <span class="eyebrow">表格文件</span>
                        <h3><?php echo esc_html($item['name']); ?></h3>
                      </div>
                      <span class="format-badge"><?php echo esc_html($item['format']); ?></span>
                    </div>
                    <div class="meta-row">
                      <span>更新时间：<?php echo esc_html($item['updated_at']); ?></span>
                      <span>文件大小：<?php echo esc_html($item['size']); ?></span>
                    </div>
                    <div class="actions">
                      <a class="action-link action-primary" href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener noreferrer">打开表格</a>
                      <a class="action-link action-secondary" href="<?php echo esc_url($item['url']); ?>" download="<?php echo esc_attr($item['file_name']); ?>">下载表格</a>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
      </section>
    </main>

    <footer class="site-footer">
      <div class="shell">
        <p>追光小牛表格中心</p>
      </div>
    </footer>

    <script>
      const tableSearch = document.getElementById('tableSearch');
      const tableGroups = Array.from(document.querySelectorAll('[data-group]'));

      tableSearch.addEventListener('input', () => {
        const keyword = tableSearch.value.trim().toLowerCase();
        tableGroups.forEach((group) => {
          const items = Array.from(group.querySelectorAll('[data-item]'));
          let visibleCount = 0;
          items.forEach((item) => {
            const matched = !keyword || item.dataset.name.toLowerCase().includes(keyword);
            item.hidden = !matched;
            if (matched) visibleCount += 1;
          });
          group.hidden = visibleCount === 0;
        });
      });
    </script>
    <?php wp_footer(); ?>
  </body>
</html>
