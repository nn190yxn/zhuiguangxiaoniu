<?php
/**
 * Template Name: 分类列表页
 */

add_filter('show_admin_bar', '__return_false');

$logo_url = esc_url(home_url('/玻璃贴-14.png'));
$page_slug = get_post_field('post_name', get_the_ID());
$page_title = get_the_title();
$is_learning_center = $page_title === '新员工学习';
$is_knowledge_center = $page_title === '知识库';

$doc_viewer_base = home_url('/doc-viewer.html');

// 知识库专题 - 左侧导航 + 右侧四联卡结构
$knowledge_topics = array(
    array(
        'id' => 'k01', 'title' => 'ACE教学理论', 'subtitle' => 'A动作 · C认知 · E参与',
        'color' => '#ff6b35', 'icon' => 'A',
        'cards' => 'training-cards-09A-ACE.html',
        'points' => array('A01 评估核心原则', 'A02 ACE三维定义', 'A03 判断顺序E→C→A', 'A04 课堂最小闭环', 'A05 常见课堂信号', 'A06 家长反馈结构', 'A07 解释边界与误区'),
    ),
    array(
        'id' => 'k02', 'title' => '儿童发展', 'subtitle' => '发展阶段 · 敏感期 · 里程碑',
        'color' => '#2f80ed', 'icon' => '儿',
        'cards' => 'training-cards-09B-child-dev.html',
        'points' => array('B01 2-4岁发展', 'B02 4-6岁发展', 'B03 6-9岁发展', 'B04 9岁以上', 'B05 四个发展维度', 'B06 敏感期理解', 'B07 教练判断顺序', 'B08 家长沟通与误区'),
    ),
    array(
        'id' => 'k03', 'title' => '感统与敏感期', 'subtitle' => '前庭觉 · 本体觉 · 触觉',
        'color' => '#9b59b6', 'icon' => '感',
        'cards' => 'training-cards-09C-sensory.html',
        'points' => array('C01 前庭觉', 'C02 本体觉', 'C03 触觉', 'C04 视觉与听觉', 'C05 教学调整原则', 'C06 常见课堂场景', 'C07 家长沟通与边界'),
    ),
    array(
        'id' => 'k04', 'title' => '七大身体素质', 'subtitle' => '力量 · 速度 · 灵敏 · 协调 · 平衡 · 柔韧 · 耐力',
        'color' => '#27ae60', 'icon' => '七',
        'cards' => 'training-cards-intermediate.html',
        'points' => array('M03 力量', 'M04 速度', 'M05 灵敏', 'M06 协调', 'M07 平衡', 'M08 柔韧', 'M09 耐力'),
    ),
    array(
        'id' => 'k05', 'title' => '体测与评估', 'subtitle' => '体测方法 · 评估标准 · 分龄解读',
        'color' => '#f39c12', 'icon' => '测',
        'cards' => 'training-cards-09F-assessment.html',
        'points' => array('F01 评估核心原则', 'F02 三类评估来源', 'F03 评估五问框架', 'F04 首次体测', 'F05 阶段复评', 'F06 课堂观察评估', 'F07 家长沟通模板'),
    ),
    array(
        'id' => 'k06', 'title' => '课程专业技能', 'subtitle' => '快乐体操 · 体能训练 · 篮球体能 · 跑酷',
        'color' => '#1abc9c', 'icon' => '课',
        'cards' => 'training-cards-09E-course-skills.html',
        'points' => array('E01 感统体操', 'E02 篮球体能', 'E03 跳绳', 'E04 中考体训', 'E05 搏击防身', 'E06 体态矫正', 'E07 跑酷', 'E08 课程判断框架'),
    ),
    array(
        'id' => 'k07', 'title' => '教学实践与反馈', 'subtitle' => '课堂结构 · 纠错方法 · 反馈技巧',
        'color' => '#3498db', 'icon' => '教',
        'cards' => 'training-cards-09G-teaching.html',
        'points' => array('G01 教学核心原则', 'G02 课堂结构设计', 'G03 指令表达', 'G04 示范与纠错', 'G05 氛围营造', 'G06 现场判断', 'G07 常见误区'),
    ),
    array(
        'id' => 'k08', 'title' => '家长沟通', 'subtitle' => '沟通原则 · 话术 · 边界 · 异议',
        'color' => '#e67e22', 'icon' => '沟',
        'cards' => 'training-cards-intermediate.html',
        'points' => array('M18 低龄家长沟通', 'M19 高龄家长沟通', 'M20 异议处理"孩子还小"', 'M21 异议处理"先回家练练"', 'M22 异议处理"再看看"', 'M23 异议处理"能保结果吗"'),
    ),
    array(
        'id' => 'k09', 'title' => '安全与异常', 'subtitle' => '安全保护 · 异常处理 · 急救',
        'color' => '#e74c3c', 'icon' => '安',
        'cards' => 'training-cards-09H-safety.html',
        'points' => array('H01 安全基本原则', 'H02 安全判断', 'H03 常见风险场景', 'H04 异常处理顺序', 'H05 课堂预防', 'H06 急救边界', 'H07 家长沟通'),
    ),
);

// 学习中心数据
$learning_paths = array(
    '新员工' => array(
        array('title' => '公司介绍与品牌文化', 'type' => '必修', 'time' => '2h', 'doc' => 'v4-00'),
        array('title' => 'ACE教学体系入门', 'type' => '必修', 'time' => '3h', 'doc' => 'k-09a'),
        array('title' => '七大身体素质基础', 'type' => '必修', 'time' => '4h', 'doc' => 'k-09d'),
        array('title' => '开关店SOP', 'type' => '必修', 'time' => '1h', 'doc' => 'v4-01a'),
        array('title' => '首次接待8步法', 'type' => '必修', 'time' => '2h', 'doc' => 'v4-04a'),
    ),
    '顾问' => array(
        array('title' => '接待流程与话术', 'type' => '核心', 'time' => '4h', 'doc' => 'v4-04a'),
        array('title' => '体测解读与课程推荐', 'type' => '核心', 'time' => '3h', 'doc' => 'k-09f'),
        array('title' => '家长沟通与异议处理', 'type' => '核心', 'time' => '4h', 'doc' => 'v4-04b'),
        array('title' => '续费跟进与转介绍', 'type' => '核心', 'time' => '3h', 'doc' => 'v4-04c'),
    ),
    '教练' => array(
        array('title' => 'ACE评估与课堂应用', 'type' => '核心', 'time' => '4h', 'doc' => 'v4-05a'),
        array('title' => '各课程教学SOP', 'type' => '核心', 'time' => '6h', 'doc' => 'v4-05b'),
        array('title' => '课堂安全与异常处理', 'type' => '核心', 'time' => '3h', 'doc' => 'v4-05e'),
        array('title' => '课后反馈与家长沟通', 'type' => '核心', 'time' => '3h', 'doc' => 'v4-05f'),
    ),
);

$scenarios = array(
    array('title' => '首次接待', 'icon' => '👋', 'doc' => 'v4-04a'),
    array('title' => '体测解读', 'icon' => '📊', 'doc' => 'k-09f'),
    array('title' => '家长沟通', 'icon' => '💬', 'doc' => 'k-09h'),
    array('title' => '异议处理', 'icon' => '❓', 'doc' => 'v4-04b'),
    array('title' => '续费跟进', 'icon' => '🔄', 'doc' => 'v4-04c'),
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
  <head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <?php wp_head(); ?>
    <style>
      :root { --brand-orange: #ff6b35; --brand-ink: #1f1a17; --brand-muted: #6b625c; }
      * { box-sizing: border-box; margin: 0; padding: 0; }
      html { scroll-behavior: smooth; margin-top: 0 !important; }
      body { font-family: Inter, -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif; color: var(--brand-ink); background: #f5f3f0; min-height: 100vh; }
      #wpadminbar { display: none !important; }
      a { color: inherit; text-decoration: none; }
      .shell { width: min(calc(100% - 32px), 1200px); margin: 0 auto; }

      /* Header */
      .site-header { position: sticky; top: 0; z-index: 1000; background: rgba(255,255,255,0.95); backdrop-filter: blur(18px); border-bottom: 1px solid rgba(0,0,0,0.06); }
      .topbar { min-height: 56px; display: flex; align-items: center; justify-content: space-between; gap: 24px; }
      .brand { display: inline-flex; align-items: center; gap: 10px; font-weight: 700; font-size: 15px; }
      .brand img { width: 32px; height: 32px; object-fit: contain; }
      .nav { display: flex; gap: 4px; }
      .nav a { padding: 8px 14px; border-radius: 10px; font-size: 13px; color: var(--brand-muted); font-weight: 500; }
      .nav a:hover, .nav a.current { background: rgba(255,107,53,0.1); color: var(--brand-orange); }
      .staff-link { background: rgba(255,107,53,0.1); color: var(--brand-orange) !important; font-weight: 600; }

      /* Main Layout */
      .main-layout { display: grid; grid-template-columns: 200px 1fr; gap: 20px; padding: 20px 0; }

      /* Sidebar */
      .sidebar { background: white; border-radius: 16px; padding: 20px; border: 1px solid rgba(0,0,0,0.06); height: fit-content; position: sticky; top: 76px; }
      .sidebar-title { font-size: 12px; font-weight: 700; color: #9e9289; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 12px; }
      .sidebar-nav { display: flex; flex-direction: column; gap: 4px; }
      .sidebar-nav a { padding: 10px 14px; border-radius: 10px; font-size: 14px; font-weight: 500; color: var(--brand-muted); display: flex; align-items: center; justify-content: space-between; transition: all 0.2s; }
      .sidebar-nav a:hover { background: rgba(0,0,0,0.03); }
      .sidebar-nav a.active { background: var(--brand-orange); color: white; }

      /* Content */
      .content-area { background: white; border-radius: 16px; padding: 24px; border: 1px solid rgba(0,0,0,0.06); }
      .content-header { margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid rgba(0,0,0,0.06); }
      .content-header h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
      .content-header .meta { font-size: 13px; color: var(--brand-muted); }

      /* Knowledge Layout - Left nav + Right iframe */
      .knowledge-layout { display: grid; grid-template-columns: 260px 1fr; gap: 20px; }

      /* Topic Nav */
      .topic-nav { background: white; border-radius: 16px; padding: 16px; border: 1px solid rgba(0,0,0,0.06); height: fit-content; position: sticky; top: 76px; max-height: calc(100vh - 96px); overflow-y: auto; }
      .topic-nav-title { font-size: 12px; font-weight: 700; color: #9e9289; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 12px; }
      .topic-group { margin-bottom: 4px; }
      .topic-header { padding: 10px 12px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: space-between; transition: all 0.2s; color: var(--brand-ink); }
      .topic-header:hover { background: rgba(0,0,0,0.03); }
      .topic-header.active { background: var(--brand-orange); color: white; }
      .topic-header .arrow { font-size: 11px; transition: transform 0.2s; }
      .topic-header.expanded .arrow { transform: rotate(90deg); }
      .topic-points { display: none; padding: 4px 0 4px 12px; }
      .topic-points.show { display: block; }
      .topic-point { padding: 7px 12px; border-radius: 8px; font-size: 13px; color: var(--brand-muted); cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s; border-left: 3px solid transparent; }
      .topic-point:hover { background: rgba(0,0,0,0.03); color: var(--brand-ink); }
      .topic-point.active { background: rgba(255,107,53,0.08); color: var(--brand-orange); border-left-color: var(--brand-orange); font-weight: 600; }
      .topic-point .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: 0.4; flex-shrink: 0; }

      /* Cards iframe */
      .cards-viewer { background: white; border-radius: 16px; border: 1px solid rgba(0,0,0,0.06); overflow: hidden; min-height: calc(100vh - 116px); }
      .cards-viewer iframe { width: 100%; height: calc(100vh - 116px); border: none; display: block; }
      .cards-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; height: calc(100vh - 116px); color: var(--brand-muted); }
      .cards-placeholder .ph-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.3; }
      .cards-placeholder .ph-text { font-size: 15px; font-weight: 500; }
      .cards-placeholder .ph-sub { font-size: 13px; margin-top: 6px; opacity: 0.7; }

      /* Learning tabs */
      .tabs { display: flex; gap: 8px; margin-bottom: 20px; }
      .tab { padding: 10px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; background: #f0f0f0; color: var(--brand-muted); }
      .tab.active { background: var(--brand-orange); color: white; }

      /* Task grid */
      .task-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
      .task-card { background: #fafafa; border: 1px solid rgba(0,0,0,0.06); border-radius: 10px; padding: 14px; transition: all 0.2s; display: flex; align-items: center; gap: 12px; }
      .task-card:hover { background: white; box-shadow: rgba(0,0,0,0.06) 0px 8px 20px; }
      .task-card .icon { width: 36px; height: 36px; border-radius: 8px; background: rgba(255,107,53,0.1); color: var(--brand-orange); display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
      .task-card .info { flex: 1; }
      .task-card h4 { margin: 0 0 2px; font-size: 13px; font-weight: 600; }
      .task-card .meta { font-size: 11px; color: var(--brand-muted); }
      .task-card .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; margin-left: 6px; }
      .tag-core { background: rgba(47,128,237,0.1); color: #2f80ed; }
      .tag-card { background: rgba(39,174,96,0.1); color: #27ae60; }

      /* Scenario grid */
      .scenario-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-top: 20px; }
      .scenario-card { background: #fafafa; border: 1px solid rgba(0,0,0,0.06); border-radius: 10px; padding: 16px; text-align: center; transition: all 0.2s; display: block; }
      .scenario-card:hover { background: white; box-shadow: rgba(0,0,0,0.06) 0px 8px 20px; }
      .scenario-card .icon { font-size: 24px; margin-bottom: 6px; }
      .scenario-card h4 { margin: 0; font-size: 13px; font-weight: 600; }

      .hidden { display: none !important; }

      @media (max-width: 900px) {
        .main-layout { grid-template-columns: 1fr; }
        .sidebar { display: flex; gap: 8px; overflow-x: auto; padding: 12px; position: static; }
        .sidebar-title { display: none; }
        .sidebar-nav { flex-direction: row; }
        .sidebar-nav a { white-space: nowrap; }
        .knowledge-layout { grid-template-columns: 1fr; }
        .topic-nav { position: static; max-height: none; }
        .cards-viewer { min-height: 500px; }
        .cards-viewer iframe { height: 500px; }
        .scenario-grid { grid-template-columns: repeat(3, 1fr); }
      }
      @media (max-width: 600px) {
        .task-grid { grid-template-columns: 1fr; }
      }
    </style>
  </head>
  <body <?php body_class(); ?>>
    <?php wp_body_open(); ?>

    <header class="site-header">
      <div class="shell topbar">
        <a class="brand" href="<?php echo esc_url(home_url('/internal.html')); ?>">
          <img src="<?php echo $logo_url; ?>" alt="追光小牛 logo" />
          <span><?php echo $is_knowledge_center ? '知识库' : '学习中心'; ?></span>
        </a>
        <nav class="nav">
          <a href="<?php echo esc_url(home_url('/internal.html')); ?>">工作台</a>
          <a href="<?php echo esc_url(home_url('/表格中心/')); ?>">表格</a>
          <a href="<?php echo esc_url(home_url('/制度标准/')); ?>">制度</a>
          <a <?php echo $is_knowledge_center ? 'class="current"' : ''; ?> href="<?php echo esc_url(home_url('/知识库/')); ?>">知识</a>
          <a <?php echo $is_learning_center ? 'class="current"' : ''; ?> href="<?php echo esc_url(home_url('/新员工学习/')); ?>">学习</a>
          <a class="staff-link" href="<?php echo esc_url(home_url('/')); ?>">官网</a>
        </nav>
      </div>
    </header>

    <div class="shell main-layout">
      <?php if ($is_knowledge_center) : ?>
        <!-- 知识库：左侧专题→知识点导航 + 右侧四联卡 -->
        <div class="knowledge-layout" style="grid-column: 1 / -1;">
          <aside class="topic-nav">
            <div class="topic-nav-title">知识专题</div>
            <?php foreach ($knowledge_topics as $idx => $topic) :
              $cards_url = esc_url(home_url('/training-cards/workspace/' . $topic['cards']));
            ?>
              <div class="topic-group">
                <div class="topic-header <?php echo $idx === 0 ? 'active expanded' : ''; ?>" onclick="toggleTopic(this, '<?php echo esc_attr($topic['id']); ?>')" data-cards="<?php echo $cards_url; ?>">
                  <span><?php echo esc_html($topic['title']); ?></span>
                  <span class="arrow">▶</span>
                </div>
                <div class="topic-points <?php echo $idx === 0 ? 'show' : ''; ?>" id="points-<?php echo esc_attr($topic['id']); ?>">
                  <?php foreach ($topic['points'] as $pidx => $point) :
                    $point_id = strtolower(substr($point, 0, 3));
                  ?>
                    <div class="topic-point <?php echo $idx === 0 && $pidx === 0 ? 'active' : ''; ?>" onclick="scrollToPoint('<?php echo esc_attr($point_id); ?>', this)">
                      <span class="dot"></span>
                      <span><?php echo esc_html($point); ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </aside>

          <div class="cards-viewer" id="cards-viewer">
            <div class="cards-placeholder" id="cards-placeholder">
              <div class="ph-icon">📚</div>
              <div class="ph-text">选择左侧知识点查看四联卡</div>
              <div class="ph-sub">点击专题展开知识点列表</div>
            </div>
            <iframe id="cards-iframe" src="" style="display:none;"></iframe>
          </div>
        </div>

      <?php else : ?>
        <!-- 学习中心：左侧岗位导航 -->
        <aside class="sidebar">
          <div class="sidebar-title">学习路径</div>
          <nav class="sidebar-nav">
            <?php $first = true; foreach ($learning_paths as $role => $tasks) : ?>
              <a href="#" class="<?php echo $first ? 'active' : ''; ?>" onclick="switchRole('<?php echo esc_attr($role); ?>', this); return false;">
                <span><?php echo esc_html($role); ?></span>
              </a>
            <?php $first = false; endforeach; ?>
          </nav>
        </aside>

        <main class="content-area">
          <div class="content-header">
            <h1 id="current-role">新员工学习路径</h1>
            <div class="meta">按岗位组织 · 完成训练</div>
          </div>

          <?php foreach ($learning_paths as $role => $tasks) : ?>
            <div id="role-<?php echo esc_attr($role); ?>" class="role-content <?php echo $role === '新员工' ? '' : 'hidden'; ?>">
              <div class="task-grid">
                <?php foreach ($tasks as $task) : ?>
                  <?php
                    $url = !empty($task['link']) ? home_url($task['link']) : $doc_viewer_base . '?doc=' . $task['doc'];
                    $tag_class = $task['type'] === '卡片' ? 'tag-card' : 'tag-core';
                  ?>
                  <a class="task-card" href="<?php echo esc_url($url); ?>">
                    <div class="icon"><?php echo $task['type'] === '卡片' ? '📇' : '📖'; ?></div>
                    <div class="info">
                      <h4><?php echo esc_html($task['title']); ?></h4>
                      <div class="meta">
                        <?php echo esc_html($task['time']); ?>
                        <span class="tag <?php echo $tag_class; ?>"><?php echo esc_html($task['type']); ?></span>
                      </div>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <!-- 场景化学习 -->
          <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(0,0,0,0.06);">
            <div class="content-header" style="margin-bottom: 12px; padding-bottom: 0; border: none;">
              <h1 style="font-size: 16px;">场景训练</h1>
            </div>
            <div class="scenario-grid">
              <?php foreach ($scenarios as $s) : ?>
                <a class="scenario-card" href="<?php echo esc_url($doc_viewer_base . '?doc=' . $s['doc']); ?>">
                  <div class="icon"><?php echo esc_html($s['icon']); ?></div>
                  <h4><?php echo esc_html($s['title']); ?></h4>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </main>
      <?php endif; ?>
    </div>

    <script>
      function switchRole(role, el) {
        document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove('active'));
        el.classList.add('active');
        document.querySelectorAll('.role-content').forEach(c => c.classList.add('hidden'));
        document.getElementById('role-' + role).classList.remove('hidden');
        document.getElementById('current-role').textContent = role + '学习路径';
      }

      // 知识库：专题展开/收起
      function toggleTopic(header, topicId) {
        const points = document.getElementById('points-' + topicId);
        const isExpanded = header.classList.contains('expanded');
        const cardsUrl = header.dataset.cards;

        // 收起其他专题
        document.querySelectorAll('.topic-header').forEach(h => {
          if (h !== header) {
            h.classList.remove('expanded', 'active');
          }
        });
        document.querySelectorAll('.topic-points').forEach(p => {
          if (p !== points) p.classList.remove('show');
        });

        // 切换当前专题
        if (!isExpanded) {
          header.classList.add('expanded', 'active');
          points.classList.add('show');
          // 加载对应四联卡
          loadCards(cardsUrl);
        }
      }

      // 加载四联卡到iframe
      function loadCards(url) {
        const iframe = document.getElementById('cards-iframe');
        const placeholder = document.getElementById('cards-placeholder');
        if (url) {
          iframe.src = url;
          iframe.style.display = 'block';
          placeholder.style.display = 'none';
        }
      }

      // 滚动到指定知识点
      function scrollToPoint(pointId, el) {
        // 高亮当前知识点
        document.querySelectorAll('.topic-point').forEach(p => p.classList.remove('active'));
        el.classList.add('active');

        // 在iframe中滚动到对应卡片
        const iframe = document.getElementById('cards-iframe');
        if (iframe && iframe.contentWindow) {
          try {
            const target = iframe.contentWindow.document.getElementById(pointId);
            if (target) {
              target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          } catch(e) {
            // 跨域安全限制，忽略
          }
        }
      }

      // 页面加载时自动展开第一个专题
      document.addEventListener('DOMContentLoaded', function() {
        const firstHeader = document.querySelector('.topic-header');
        if (firstHeader) {
          const cardsUrl = firstHeader.dataset.cards;
          if (cardsUrl) loadCards(cardsUrl);
        }
      });
    </script>
    <?php wp_footer(); ?>
  </body>
</html>
