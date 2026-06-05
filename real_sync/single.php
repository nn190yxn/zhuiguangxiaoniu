<?php
$categories = get_the_category();
$primary_category = !empty($categories) ? $categories[0] : null;
$category_name = $primary_category ? $primary_category->name : '文章';
$category_slug = $primary_category ? $primary_category->slug : '';
$logo_url = esc_url(home_url('/玻璃贴-14.png'));
$content_center_label = in_array($category_name, array('制度标准', '知识库', '新闻公告', '培训资料库', '素材中心', '新员工学习'), true) ? $category_name : '内容中心';

$page_links = array(
    '知识库' => home_url('/知识库/'),
    '制度标准' => home_url('/制度标准/'),
    '新闻公告' => home_url('/新闻公告/'),
    '培训资料库' => home_url('/培训资料库/'),
    '素材中心' => home_url('/素材中心/'),
    '新员工学习' => home_url('/新员工学习/'),
);

$center_key = $category_name;
if ($primary_category && $primary_category->parent) {
    $parent_category = get_category($primary_category->parent);
    if ($parent_category) {
        $center_key = $parent_category->name;
    }
}

$center_titles = array(
    '知识库' => '知识库',
    '制度标准' => '制度中心',
    '新闻公告' => '新闻公告',
    '培训资料库' => '培训资料库',
    '素材中心' => '素材中心',
    '新员工学习' => '员工学习中心',
);

$back_label = isset($center_titles[$center_key]) ? $center_titles[$center_key] : $content_center_label;
$is_knowledge_article = $center_key === '知识库';
$is_policy_article = $center_key === '制度标准';
$is_compact_article = $is_knowledge_article || $is_policy_article;

$module_links_map = array(
    '制度标准' => array(
        array('eyebrow' => '执行动作', 'title' => '去表格中心找执行文件', 'desc' => '看完制度正文后，继续去表格中心找到签收、记录、抽测和复盘所需的实际文件。', 'url' => home_url('/表格中心/')),
        array('eyebrow' => '理解场景', 'title' => '去知识库补案例说明', 'desc' => '如果制度只说明了边界和原则，也可以去知识库补一线案例、经验解释和常见话术。', 'url' => home_url('/知识库/')),
        array('eyebrow' => '培训闭环', 'title' => '去员工学习中心完成学习', 'desc' => '涉及培训、抽测和上岗验收的内容，最终回员工学习中心完成阅读和验收闭环。', 'url' => home_url('/新员工学习/')),
    ),
    '知识库' => array(
        array('eyebrow' => '正式规则', 'title' => '去制度中心看标准正文', 'desc' => '如果需要确认正式规则、边界和要求，继续回制度中心查看原始制度正文。', 'url' => home_url('/制度标准/')),
        array('eyebrow' => '执行文件', 'title' => '去表格中心找对应表单', 'desc' => '当内容已经进入执行、签收、记录或复盘阶段，可以直接去表格中心拿对应文件。', 'url' => home_url('/表格中心/')),
        array('eyebrow' => '培训任务', 'title' => '去员工学习中心进入学习链路', 'desc' => '如果这篇内容需要培训、抽测或阶段学习，就继续进入员工学习中心完成学习闭环。', 'url' => home_url('/新员工学习/')),
    ),
    '新员工学习' => array(
        array('eyebrow' => '正式规则', 'title' => '去制度中心看对应制度', 'desc' => '学习任务里遇到正式制度边界时，继续去制度中心阅读标准正文。', 'url' => home_url('/制度标准/')),
        array('eyebrow' => '验收文件', 'title' => '去表格中心拿签到和抽测表', 'desc' => '签到、抽测、上岗验收和复盘所需表格，统一从表格中心继续查找和下载。', 'url' => home_url('/表格中心/')),
        array('eyebrow' => '补充理解', 'title' => '去知识库继续看案例', 'desc' => '如果需要更多场景解释、FAQ 或经验说明，再横向进入知识库补齐理解。', 'url' => home_url('/知识库/')),
    ),
    '培训资料库' => array(
        array('eyebrow' => '正式规则', 'title' => '去制度中心确认要求', 'desc' => '培训资料负责承接材料，真正的规则边界和标准仍然以制度中心为准。', 'url' => home_url('/制度标准/')),
        array('eyebrow' => '验收文件', 'title' => '去表格中心找验收表单', 'desc' => '培训执行后的签到、抽测、验收和复盘，继续去表格中心拿真实文件。', 'url' => home_url('/表格中心/')),
        array('eyebrow' => '学习闭环', 'title' => '去员工学习中心串成任务链', 'desc' => '培训资料不单独成闭环，后续应回员工学习中心和阶段学习任务一起推进。', 'url' => home_url('/新员工学习/')),
    ),
    '新闻公告' => array(
        array('eyebrow' => '涉及制度', 'title' => '去制度中心看正式更新', 'desc' => '如果公告涉及规则变更或正式通知，继续到制度中心查看原始正文。', 'url' => home_url('/制度标准/')),
        array('eyebrow' => '涉及执行', 'title' => '去表格中心看配套文件', 'desc' => '如果公告要求补记录、补签收或补台账，继续去表格中心找对应文件。', 'url' => home_url('/表格中心/')),
        array('eyebrow' => '涉及培训', 'title' => '去员工学习中心跟进任务', 'desc' => '如果公告后还需要培训宣导或抽测，继续回员工学习中心形成任务闭环。', 'url' => home_url('/新员工学习/')),
    ),
    '素材中心' => array(
        array('eyebrow' => '品牌规则', 'title' => '去制度中心看品牌标准', 'desc' => '素材中心只承接物料，真正的品牌使用边界和规范仍然回制度中心确认。', 'url' => home_url('/制度标准/')),
        array('eyebrow' => '执行记录', 'title' => '去表格中心补物料台账', 'desc' => '如果涉及领取、盘点、使用或复盘，可以直接去表格中心查看物料相关表单。', 'url' => home_url('/表格中心/')),
        array('eyebrow' => '补充说明', 'title' => '去知识库看使用案例', 'desc' => '想知道素材在门店、活动或运营中的具体用法，可以继续去知识库看案例和经验。', 'url' => home_url('/知识库/')),
    ),
);

$module_links = isset($module_links_map[$center_key]) ? $module_links_map[$center_key] : array(
    array('eyebrow' => '返回列表', 'title' => '回到所属内容中心', 'desc' => '先回所属中心继续看同主题内容，避免只停留在单篇文章。', 'url' => $back_link),
    array('eyebrow' => '正式规则', 'title' => '去制度中心确认边界', 'desc' => '当文章涉及正式制度或执行边界时，继续去制度中心查看标准正文。', 'url' => home_url('/制度标准/')),
    array('eyebrow' => '执行文件', 'title' => '去表格中心找对应文件', 'desc' => '当文章需要落到执行和留痕时，可以直接去表格中心查找对应表格。', 'url' => home_url('/表格中心/')),
);

$back_link = home_url('/');
if ($primary_category) {
    if ($primary_category->parent) {
        $parent_category = get_category($primary_category->parent);
        if ($parent_category && isset($page_links[$parent_category->name])) {
            $back_link = $page_links[$parent_category->name];
        }
    } elseif (isset($page_links[$primary_category->name])) {
        $back_link = $page_links[$primary_category->name];
    }
}

add_filter(
    'show_admin_bar',
    '__return_false'
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
        --brand-soft: #fff8f3;
        --brand-line: rgba(31, 26, 23, 0.1);
        --panel-shadow: rgba(0, 0, 0, 0.06) 0px 14px 36px, rgba(0, 0, 0, 0.03) 0px 4px 12px;
      }

      * { box-sizing: border-box; }
      html { scroll-behavior: smooth; margin-top: 0 !important; }
      body {
        margin: 0;
        font-family: Inter, -apple-system, BlinkMacSystemFont, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
        color: var(--brand-ink);
        background: linear-gradient(180deg, #fffaf6 0%, #fff 30%, #fdfaf7 100%);
      }
      #wpadminbar { display: none !important; }
      a { color: var(--brand-orange); text-decoration: none; }
      a:hover { color: var(--brand-orange-deep); }

      .shell {
        width: min(calc(100% - 32px), 900px);
        margin: 0 auto;
      }

      .site-header {
        position: sticky;
        top: 0;
        z-index: 1000;
        background: rgba(255, 251, 248, 0.86);
        backdrop-filter: blur(18px);
        border-bottom: 1px solid rgba(31, 26, 23, 0.06);
      }

      .topbar {
        min-height: 72px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
      }

      .brand {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        font-weight: 700;
      }

      .brand img {
        width: 42px;
        height: 42px;
        object-fit: contain;
      }

      .nav {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
      }

      .nav a {
        padding: 9px 12px;
        border-radius: 10px;
        font-size: 14px;
        color: var(--brand-muted);
      }

      .nav a:hover,
      .nav a.current {
        background: rgba(255, 107, 53, 0.1);
        color: var(--brand-orange);
      }

      .staff-link {
        background: rgba(255, 107, 53, 0.1);
        color: var(--brand-orange) !important;
        font-weight: 600;
      }

      .article-wrap {
        padding: 34px 0 72px;
      }

      .article-header,
      .article-overview,
      .article-content,
      .post-nav a {
        border: 1px solid rgba(31, 26, 23, 0.08);
        background: rgba(255, 255, 255, 0.94);
        border-radius: 22px;
        box-shadow: var(--panel-shadow);
      }

      .article-header {
        padding: 26px;
      }

      .eyebrow {
        display: inline-block;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #9e9289;
      }

      .breadcrumb {
        margin-top: 10px;
        font-size: 14px;
        color: #9e9289;
      }

      .breadcrumb span { margin: 0 8px; }
      .article-title {
        margin: 14px 0 0;
        font-size: clamp(30px, 4vw, 42px);
        line-height: 1.12;
        letter-spacing: -0.05em;
      }

      .article-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 18px;
        color: var(--brand-muted);
        font-size: 14px;
      }

      .article-content {
        margin-top: 18px;
        padding: 28px;
        color: #342d29;
        line-height: 1.9;
      }

      .article-content h1,
      .article-content h2,
      .article-content h3,
      .article-content h4 {
        letter-spacing: -0.04em;
        line-height: 1.22;
        color: var(--brand-ink);
      }

      .article-content h1 { font-size: 30px; margin: 0 0 18px; }
      .article-content h2 { font-size: 24px; margin: 32px 0 14px; }
      .article-content h3 { font-size: 20px; margin: 26px 0 12px; }
      .article-content h4 { font-size: 17px; margin: 20px 0 10px; }
      .article-content p { margin: 0 0 16px; }
      .article-content ul,
      .article-content ol { margin: 0 0 18px; padding-left: 22px; }
      .article-content li { margin-bottom: 8px; }
      .article-content hr {
        border: 0;
        border-top: 1px solid var(--brand-line);
        margin: 28px 0;
      }
      .article-content blockquote {
        margin: 20px 0;
        padding: 14px 18px;
        border-left: 4px solid var(--brand-orange);
        background: var(--brand-soft);
        border-radius: 0 14px 14px 0;
      }
      .article-content table {
        width: 100%;
        border-collapse: collapse;
        margin: 18px 0 22px;
        overflow: hidden;
        border-radius: 14px;
      }
      .article-content th,
      .article-content td {
        border: 1px solid rgba(31, 26, 23, 0.08);
        padding: 12px 14px;
        text-align: left;
        vertical-align: top;
      }
      .article-content th {
        background: #f7f1ea;
        font-weight: 700;
      }

      .article-overview {
        margin-top: 18px;
        padding: 20px;
      }

      .overview-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
      }

      .overview-item {
        padding: 18px;
        border-radius: 18px;
        background: #faf7f3;
        border: 1px solid rgba(31, 26, 23, 0.06);
      }

      .overview-item h3 {
        font-size: 18px;
        margin-top: 8px;
      }

      .overview-item p {
        margin: 8px 0 0;
        color: var(--brand-muted);
        line-height: 1.8;
      }

      .post-nav {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        margin-top: 18px;
      }

      .post-nav a {
        padding: 20px;
        color: var(--brand-ink);
      }

      .post-nav small {
        display: block;
        color: #9e9289;
        margin-bottom: 8px;
      }

      .site-footer {
        padding: 24px 0 40px;
        border-top: 1px solid rgba(31, 26, 23, 0.08);
      }

      .site-footer p {
        margin: 0;
        color: var(--brand-muted);
        text-align: center;
      }

      @media (max-width: 760px) {
        .topbar,
        .post-nav,
        .overview-grid {
          grid-template-columns: 1fr;
        }

        .topbar {
          display: flex;
          align-items: flex-start;
          flex-direction: column;
          padding: 12px 0;
        }

        .nav { justify-content: flex-start; }
        .article-content { overflow-x: auto; }
      }
    </style>
  </head>
  <body <?php body_class(); ?>>
    <?php wp_body_open(); ?>
    <header class="site-header">
      <div class="shell topbar">
        <a class="brand" href="<?php echo esc_url(home_url('/internal.html')); ?>">
          <img src="<?php echo $logo_url; ?>" alt="追光小牛 logo" />
          <span>追光小牛</span>
        </a>
        <nav class="nav">
          <?php if ($is_compact_article) : ?>
            <a class="staff-link" href="<?php echo esc_url(home_url('/internal.html')); ?>">回到首页</a>
          <?php else : ?>
            <a href="<?php echo esc_url(home_url('/internal.html')); ?>">员工首页</a>
            <a href="<?php echo esc_url(home_url('/表格中心/')); ?>">表格中心</a>
            <a href="<?php echo esc_url(home_url('/制度标准/')); ?>">制度中心</a>
            <a href="<?php echo esc_url(home_url('/知识库/')); ?>">知识库</a>
            <a href="<?php echo esc_url(home_url('/新员工学习/')); ?>">员工学习中心</a>
            <a href="<?php echo esc_url(home_url('/fitness-assessment.html')); ?>">智能运动规划</a>
            <a href="<?php echo esc_url(home_url('/smart-lessons.html')); ?>">智能教案</a>
            <a class="staff-link" href="<?php echo esc_url(home_url('/')); ?>">返回官网</a>
          <?php endif; ?>
        </nav>
      </div>
    </header>

    <main class="article-wrap">
      <div class="shell">
        <section class="article-header">
          <span class="eyebrow"><?php echo esc_html($category_name); ?></span>
          <div class="breadcrumb">
            <a href="<?php echo esc_url(home_url('/')); ?>">首页</a>
            <span>/</span>
            <a href="<?php echo esc_url($back_link); ?>"><?php echo esc_html($category_name); ?></a>
          </div>
          <h1 class="article-title"><?php the_title(); ?></h1>
          <div class="article-meta">
            <span>发布时间：<?php echo esc_html(get_the_date('Y-m-d')); ?></span>
            <?php if (!$is_compact_article) : ?>
              <span>分类：<?php echo esc_html($category_name); ?></span>
            <?php endif; ?>
            <?php if ($category_slug && !$is_compact_article) : ?>
              <span>标识：<?php echo esc_html($category_slug); ?></span>
            <?php endif; ?>
          </div>
        </section>

        <?php if (!$is_compact_article) : ?>
          <section class="article-overview">
            <div class="overview-grid">
              <article class="overview-item">
                <span class="eyebrow">当前位置</span>
                <h3><?php echo esc_html($content_center_label); ?></h3>
                <p>这篇内容属于当前内容中心详情页，员工可以先阅读，再返回所属列表继续查找相关内容。</p>
              </article>
              <article class="overview-item">
                <span class="eyebrow">返回入口</span>
                <h3>回到对应列表</h3>
                <p><a href="<?php echo esc_url($back_link); ?>">返回 <?php echo esc_html($back_label); ?></a>，继续按分类、推荐或完整列表查看相关内容。</p>
              </article>
              <article class="overview-item">
                <span class="eyebrow">查看方式</span>
                <h3>当前内容和关联内容都可直接查看</h3>
                <p>员工可以直接阅读当前内容，也可以通过上一篇、下一篇或所属中心列表继续查看相关主题。</p>
              </article>
            </div>
          </section>

          <section class="article-overview">
            <div class="overview-grid">
              <?php foreach ($module_links as $item) : ?>
                <a class="overview-item" href="<?php echo esc_url($item['url']); ?>">
                  <span class="eyebrow"><?php echo esc_html($item['eyebrow']); ?></span>
                  <h3><?php echo esc_html($item['title']); ?></h3>
                  <p><?php echo esc_html($item['desc']); ?></p>
                </a>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

        <article class="article-content">
          <?php the_content(); ?>
        </article>

        <section class="post-nav">
          <?php $prev_post = get_previous_post(); ?>
          <?php if ($prev_post) : ?>
            <a href="<?php echo esc_url(get_permalink($prev_post->ID)); ?>">
              <small>上一篇</small>
              <strong><?php echo esc_html(get_the_title($prev_post->ID)); ?></strong>
            </a>
          <?php else : ?>
            <div></div>
          <?php endif; ?>

          <?php $next_post = get_next_post(); ?>
          <?php if ($next_post) : ?>
            <a href="<?php echo esc_url(get_permalink($next_post->ID)); ?>">
              <small>下一篇</small>
              <strong><?php echo esc_html(get_the_title($next_post->ID)); ?></strong>
            </a>
          <?php else : ?>
            <div></div>
          <?php endif; ?>
        </section>
      </div>
    </main>

    <footer class="site-footer">
      <div class="shell">
        <p>追光小牛内容中心 · 制度与知识内容已接入 WordPress</p>
      </div>
    </footer>
    <?php wp_footer(); ?>
  </body>
</html>
