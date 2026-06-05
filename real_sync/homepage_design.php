<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 首页设计脚本 - Apple简洁风
 * 在子主题中创建自定义首页模板
 */

define('WP_USE_THEMES', false);
$wp_config_path = dirname(__FILE__) . '/wp-config.php';
if (!file_exists($wp_config_path)) { die('错误：未找到wp-config.php'); }
require_once($wp_config_path);
require_once(ABSPATH . 'wp-settings.php');
require_once(ABSPATH . 'wp-admin/includes/post.php');

$child_theme_dir = ABSPATH . 'wp-content/themes/astra-child/';

echo "=== 动合空间 - 首页设计 ===\n\n";

// ========== Step 1: 创建首页模板 ==========
echo "Step 1: 创建首页模板...\n";

$template_content = <<< 'TEMPLATE'
<?php
/**
 * Template Name: 企业首页
 * 首页自定义模板 - Apple简洁风
 */
get_header();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ===== 首页全局样式 ===== */
.home-page { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif; color: #1d1d1f; overflow-x: hidden; }
.home-page * { box-sizing: border-box; margin: 0; padding: 0; }

/* ===== Hero Banner ===== */
.hero-section {
    position: relative;
    min-height: 520px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    background: linear-gradient(135deg, #0a1628 0%, #1a3a5c 40%, #2d6a9f 70%, #4a9fd5 100%);
    overflow: hidden;
}
.hero-section::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle at 30% 50%, rgba(255,255,255,0.05) 0%, transparent 50%),
                radial-gradient(circle at 70% 50%, rgba(255,255,255,0.03) 0%, transparent 40%);
    animation: heroFloat 20s ease-in-out infinite;
}
@keyframes heroFloat {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    33% { transform: translate(30px, -30px) rotate(1deg); }
    66% { transform: translate(-20px, 20px) rotate(-1deg); }
}
.hero-content { position: relative; z-index: 2; max-width: 800px; padding: 60px 24px; }
.hero-badge {
    display: inline-block;
    padding: 6px 20px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 100px;
    color: rgba(255,255,255,0.9);
    font-size: 13px;
    letter-spacing: 2px;
    margin-bottom: 28px;
    backdrop-filter: blur(10px);
}
.hero-title {
    font-size: 52px;
    font-weight: 700;
    color: #fff;
    line-height: 1.15;
    margin-bottom: 20px;
    letter-spacing: -1px;
}
.hero-subtitle {
    font-size: 20px;
    color: rgba(255,255,255,0.75);
    line-height: 1.6;
    font-weight: 300;
    max-width: 600px;
    margin: 0 auto 36px;
}
.hero-actions { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 32px;
    background: #fff;
    color: #1a3a5c;
    border: none;
    border-radius: 980px;
    font-size: 16px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    cursor: pointer;
}
.btn-primary:hover { background: #f0f0f0; transform: scale(1.02); box-shadow: 0 8px 30px rgba(0,0,0,0.15); }
.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 32px;
    background: rgba(255,255,255,0.1);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.3);
    border-radius: 980px;
    font-size: 16px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    cursor: pointer;
}
.btn-secondary:hover { background: rgba(255,255,255,0.2); transform: scale(1.02); }

/* ===== Section通用 ===== */
.section { padding: 80px 24px; max-width: 1200px; margin: 0 auto; }
.section-alt { background: #f5f5f7; }
.section-alt .section { padding-top: 80px; padding-bottom: 80px; }
.section-header { text-align: center; margin-bottom: 56px; }
.section-label {
    display: inline-block;
    font-size: 13px;
    font-weight: 600;
    color: #1a5276;
    letter-spacing: 3px;
    text-transform: uppercase;
    margin-bottom: 12px;
}
.section-title { font-size: 40px; font-weight: 700; color: #1d1d1f; line-height: 1.2; margin-bottom: 16px; }
.section-desc { font-size: 18px; color: #86868b; line-height: 1.6; max-width: 600px; margin: 0 auto; }

/* ===== 快捷入口 ===== */
.quick-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    justify-items: center;
}
.quick-card {
    background: #fff;
    border-radius: 20px;
    padding: 36px 28px;
    text-decoration: none;
    transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    border: 1px solid rgba(0,0,0,0.04);
    position: relative;
    overflow: hidden;
}
.quick-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    border-radius: 20px 20px 0 0;
    transition: height 0.4s ease;
}
.quick-card:nth-child(1)::before { background: linear-gradient(90deg, #007aff, #5ac8fa); }
.quick-card:nth-child(2)::before { background: linear-gradient(90deg, #34c759, #30d158); }
.quick-card:nth-child(3)::before { background: linear-gradient(90deg, #ff9500, #ffcc00); }
.quick-card:nth-child(4)::before { background: linear-gradient(90deg, #af52de, #da8fff); }
.quick-card:nth-child(5)::before { background: linear-gradient(90deg, #ff2d55, #ff6482); }
.quick-card:nth-child(6)::before { background: linear-gradient(90deg, #5856d6, #7c7aff); }
.quick-card:hover { transform: translateY(-6px); box-shadow: 0 20px 60px rgba(0,0,0,0.08); }
.quick-card:hover::before { height: 6px; }
.card-icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    margin-bottom: 20px;
}
.quick-card:nth-child(1) .card-icon { background: rgba(0,122,255,0.1); }
.quick-card:nth-child(2) .card-icon { background: rgba(52,199,89,0.1); }
.quick-card:nth-child(3) .card-icon { background: rgba(255,149,0,0.1); }
.quick-card:nth-child(4) .card-icon { background: rgba(175,82,222,0.1); }
.quick-card:nth-child(5) .card-icon { background: rgba(255,45,85,0.1); }
.quick-card:nth-child(6) .card-icon { background: rgba(88,86,214,0.1); }
.card-title { font-size: 20px; font-weight: 600; color: #1d1d1f; margin-bottom: 8px; }
.card-desc { font-size: 14px; color: #86868b; line-height: 1.5; }

/* ===== 内容列表 ===== */
.content-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
.content-card {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s ease;
    border: 1px solid rgba(0,0,0,0.04);
    text-decoration: none;
    display: block;
}
.content-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.08); }
.content-card-img {
    width: 100%;
    height: 180px;
    object-fit: cover;
    background: linear-gradient(135deg, #f5f5f7, #e8e8ed);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 48px;
    color: #c7c7cc;
}
.content-card-body { padding: 24px; }
.content-card-tag {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 500;
    margin-bottom: 12px;
}
.tag-blue { background: rgba(0,122,255,0.1); color: #007aff; }
.tag-green { background: rgba(52,199,89,0.1); color: #34c759; }
.tag-orange { background: rgba(255,149,0,0.1); color: #ff9500; }
.tag-purple { background: rgba(175,82,222,0.1); color: #af52de; }
.content-card-title { font-size: 17px; font-weight: 600; color: #1d1d1f; margin-bottom: 8px; line-height: 1.4; }
.content-card-excerpt { font-size: 14px; color: #86868b; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.content-card-meta { display: flex; align-items: center; gap: 12px; margin-top: 16px; font-size: 13px; color: #aeaeb2; }

/* ===== 统计数据 ===== */
.stats-section {
    background: linear-gradient(135deg, #0a1628, #1a3a5c);
    padding: 60px 24px;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 32px;
    max-width: 1000px;
    margin: 0 auto;
    text-align: center;
}
.stat-item {}
.stat-number { font-size: 42px; font-weight: 700; color: #fff; margin-bottom: 8px; }
.stat-label { font-size: 15px; color: rgba(255,255,255,0.6); font-weight: 400; }

/* ===== CTA ===== */
.cta-section {
    text-align: center;
    padding: 80px 24px;
}
.cta-title { font-size: 36px; font-weight: 700; color: #1d1d1f; margin-bottom: 16px; }
.cta-desc { font-size: 18px; color: #86868b; margin-bottom: 32px; }

/* ===== 响应式 ===== */
@media (max-width: 768px) {
    .hero-title { font-size: 32px; }
    .hero-subtitle { font-size: 16px; }
    .quick-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .content-grid { grid-template-columns: 1fr; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 24px; }
    .section-title { font-size: 28px; }
    .section { padding: 48px 16px; }
    .hero-section { min-height: 400px; }
}
</style>

<div class="home-page">

    <!-- Hero Banner -->
    <section class="hero-section">
        <div class="hero-content">
            <div class="hero-badge">贵州动合空间体育发展有限公司</div>
            <h1 class="hero-title">智慧管理平台</h1>
            <p class="hero-subtitle">制度标准 · 知识共享 · 培训成长 · 素材赋能<br>一站式企业内部协作平台</p>
            <div class="hero-actions">
                <a href="/制度标准/" class="btn-primary">📚 浏览制度</a>
                <a href="/新员工学习/" class="btn-secondary">🎓 新人指南</a>
            </div>
        </div>
    </section>

    <!-- 快捷入口 -->
    <div class="section-alt">
        <div class="section">
            <div class="section-header">
                <div class="section-label">Quick Access</div>
                <h2 class="section-title">快捷入口</h2>
                <p class="section-desc">高效直达，快速找到你需要的内容</p>
            </div>
            <div class="quick-grid">
                <a href="/制度标准/" class="quick-card">
                    <div class="card-icon">📋</div>
                    <div class="card-title">制度标准</div>
                    <div class="card-desc">公司规章制度、管理流程、操作规范</div>
                </a>
                <a href="/表格中心/" class="quick-card" style="background: linear-gradient(135deg, #FFD166, #06D6A0);">
                    <div class="card-icon">📊</div>
                    <div class="card-title">表格中心</div>
                    <div class="card-desc">全部管理表单，一键搜索下载</div>
                </a>
                <a href="/知识库/" class="quick-card">
                    <div class="card-icon">📖</div>
                    <div class="card-title">知识库</div>
                    <div class="card-desc">经验沉淀、案例分享、专业知识</div>
                </a>
                <a href="/新闻公告/" class="quick-card">
                    <div class="card-icon">📢</div>
                    <div class="card-title">新闻公告</div>
                    <div class="card-desc">公司动态、重要通知、活动预告</div>
                </a>
                <a href="/培训资料库/" class="quick-card">
                    <div class="card-icon">🎓</div>
                    <div class="card-title">培训资料库</div>
                    <div class="card-desc">培训课件、学习视频、考试题库</div>
                </a>
                <a href="/素材中心/" class="quick-card">
                    <div class="card-icon">🎨</div>
                    <div class="card-title">素材中心</div>
                    <div class="card-desc">营销素材、品牌图片、宣传模板</div>
                </a>
                <a href="/新员工学习/" class="quick-card">
                    <div class="card-icon">🚀</div>
                    <div class="card-title">新员工学习</div>
                    <div class="card-desc">入职指南、学习路径、成长计划</div>
                </a>
            </div>
        </div>
    </div>

    <!-- 最新公告 -->
    <div class="section">
        <div class="section-header">
            <div class="section-label">Latest News</div>
            <h2 class="section-title">最新公告</h2>
            <p class="section-desc">了解公司最新动态和重要通知</p>
        </div>
        <div class="content-grid">
TEMPLATE;

// 获取最新新闻公告文章
$news_posts = get_posts(array(
    'numberposts' => 3,
    'category_name' => '新闻公告',
    'post_status' => 'publish',
    'orderby' => 'date',
    'order' => 'DESC'
));

if (empty($news_posts)) {
    $template_content .= '
            <a href="#" class="content-card" style="opacity:0.6">
                <div class="content-card-img">📢</div>
                <div class="content-card-body">
                    <span class="content-card-tag tag-blue">新闻公告</span>
                    <div class="content-card-title">暂无公告内容</div>
                    <div class="content-card-excerpt">管理员正在准备内容，敬请期待...</div>
                    <div class="content-card-meta"><span>待发布</span></div>
                </div>
            </a>';
} else {
    foreach ($news_posts as $post) {
        $excerpt = has_excerpt($post->ID) ? get_the_excerpt($post->ID) : wp_trim_words($post->post_content, 40);
        $date = get_the_date('Y-m-d', $post->ID);
        $template_content .= '
            <a href="' . get_permalink($post->ID) . '" class="content-card">
                <div class="content-card-img">📢</div>
                <div class="content-card-body">
                    <span class="content-card-tag tag-blue">新闻公告</span>
                    <div class="content-card-title">' . esc_html($post->post_title) . '</div>
                    <div class="content-card-excerpt">' . esc_html($excerpt) . '</div>
                    <div class="content-card-meta"><span>' . $date . '</span></div>
                </div>
            </a>';
    }
}

$template_content .= <<< 'TEMPLATE'
        </div>
    </div>

    <!-- 热门制度 -->
    <div class="section-alt">
        <div class="section">
            <div class="section-header">
                <div class="section-label">Policies</div>
                <h2 class="section-title">热门制度</h2>
                <p class="section-desc">常用规章制度，快速查阅</p>
            </div>
            <div class="content-grid">
TEMPLATE;

// 获取最新制度标准文章
$policy_posts = get_posts(array(
    'numberposts' => 3,
    'category_name' => '制度标准',
    'post_status' => 'publish',
    'orderby' => 'date',
    'order' => 'DESC'
));

if (empty($policy_posts)) {
    $template_content .= '
            <a href="#" class="content-card" style="opacity:0.6">
                <div class="content-card-img">📋</div>
                <div class="content-card-body">
                    <span class="content-card-tag tag-green">制度标准</span>
                    <div class="content-card-title">暂无制度内容</div>
                    <div class="content-card-excerpt">管理员正在准备内容，敬请期待...</div>
                    <div class="content-card-meta"><span>待发布</span></div>
                </div>
            </a>';
} else {
    foreach ($policy_posts as $post) {
        $excerpt = has_excerpt($post->ID) ? get_the_excerpt($post->ID) : wp_trim_words($post->post_content, 40);
        $date = get_the_date('Y-m-d', $post->ID);
        $template_content .= '
            <a href="' . get_permalink($post->ID) . '" class="content-card">
                <div class="content-card-img">📋</div>
                <div class="content-card-body">
                    <span class="content-card-tag tag-green">制度标准</span>
                    <div class="content-card-title">' . esc_html($post->post_title) . '</div>
                    <div class="content-card-excerpt">' . esc_html($excerpt) . '</div>
                    <div class="content-card-meta"><span>' . $date . '</span></div>
                </div>
            </a>';
    }
}

$template_content .= <<< 'TEMPLATE'
        </div>
    </div>

    <!-- 统计数据 -->
    <section class="stats-section">
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number">6</div>
                <div class="stat-label">功能模块</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">AI</div>
                <div class="stat-label">智能赋能</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">∞</div>
                <div class="stat-label">知识沉淀</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">24/7</div>
                <div class="stat-label">随时访问</div>
            </div>
        </div>
    </section>

    <!-- 培训推荐 -->
    <div class="section">
        <div class="section-header">
            <div class="section-label">Training</div>
            <h2 class="section-title">培训推荐</h2>
            <p class="section-desc">精选培训课程，助力专业成长</p>
        </div>
        <div class="content-grid">
TEMPLATE;

// 获取最新培训文章
$training_posts = get_posts(array(
    'numberposts' => 3,
    'category_name' => '培训资料库',
    'post_status' => 'publish',
    'orderby' => 'date',
    'order' => 'DESC'
));

if (empty($training_posts)) {
    $template_content .= '
            <a href="#" class="content-card" style="opacity:0.6">
                <div class="content-card-img">🎓</div>
                <div class="content-card-body">
                    <span class="content-card-tag tag-purple">培训资料</span>
                    <div class="content-card-title">暂无培训内容</div>
                    <div class="content-card-excerpt">管理员正在准备内容，敬请期待...</div>
                    <div class="content-card-meta"><span>待发布</span></div>
                </div>
            </a>';
} else {
    foreach ($training_posts as $post) {
        $excerpt = has_excerpt($post->ID) ? get_the_excerpt($post->ID) : wp_trim_words($post->post_content, 40);
        $date = get_the_date('Y-m-d', $post->ID);
        $template_content .= '
            <a href="' . get_permalink($post->ID) . '" class="content-card">
                <div class="content-card-img">🎓</div>
                <div class="content-card-body">
                    <span class="content-card-tag tag-purple">培训资料</span>
                    <div class="content-card-title">' . esc_html($post->post_title) . '</div>
                    <div class="content-card-excerpt">' . esc_html($excerpt) . '</div>
                    <div class="content-card-meta"><span>' . $date . '</span></div>
                </div>
            </a>';
    }
}

$template_content .= <<< 'TEMPLATE'
        </div>
    </div>

    <!-- CTA -->
    <div class="section-alt">
        <div class="cta-section">
            <h2 class="cta-title">开始你的学习之旅</h2>
            <p class="cta-desc">新员工入职指南，助你快速融入团队</p>
            <a href="/新员工学习/" class="btn-primary">🚀 立即开始</a>
        </div>
    </div>

</div>

<?php get_footer(); ?>
TEMPLATE;

// 写入模板文件
$template_file = $child_theme_dir . 'page-home.php';
file_put_contents($template_file, $template_content);
echo "✅ 首页模板已创建: page-home.php\n\n";

// ========== Step 2: 设置首页使用该模板 ==========
echo "Step 2: 设置首页模板...\n";

$homepage = get_page_by_path('首页');
if ($homepage) {
    update_post_meta($homepage->ID, '_wp_page_template', 'page-home.php');
    echo "✅ 首页模板已设置为: 企业首页\n\n";
} else {
    echo "⚠️ 未找到'首页'页面\n\n";
}

// ========== Step 3: 设置静态首页 ==========
echo "Step 3: 配置静态首页...\n";

$blog_page = get_page_by_path('新闻公告');
if (!$blog_page) {
    $blog_page_id = wp_insert_post(array(
        'post_title' => '新闻公告',
        'post_name' => '新闻公告',
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_content' => ''
    ));
} else {
    $blog_page_id = $blog_page->ID;
}

update_option('show_on_front', 'page');
update_option('page_on_front', $homepage->ID);
update_option('page_for_posts', $blog_page_id);
echo "✅ 静态首页已配置\n";
echo "   - 首页: {$homepage->post_title} (ID: {$homepage->ID})\n";
echo "   - 文章列表: 新闻公告 (ID: {$blog_page_id})\n\n";

// ========== Step 4: 更新子主题 functions.php ==========
echo "Step 4: 优化导航栏样式...\n";

$functions_content = <<< 'FUNCTIONS'
<?php
/**
 * Astra Child Theme - 动合空间
 */

function astra_child_enqueue_styles() {
    wp_enqueue_style('astra-child-custom', get_stylesheet_directory_uri() . '/custom.css', array(), '1.1');
}
add_action('wp_enqueue_scripts', 'astra_child_enqueue_styles');

function astra_child_register_menus() {
    register_nav_menus(array(
        'primary' => '主导航菜单',
        'footer'  => '底部菜单',
    ));
}
add_action('init', 'astra_child_register_menus');

add_filter('astra_the_title_enabled', '__return_false');

function astra_child_custom_nav_args($args) {
    if ($args['theme_location'] === 'primary') {
        $args['container'] = 'nav';
        $args['container_class'] = 'main-nav';
        $args['menu_class'] = 'nav-menu';
        $args['items_wrap'] = '<ul class="%2$s">%3$s</ul>';
    }
    return $args;
}
add_filter('wp_nav_menu_args', 'astra_child_custom_nav_args');

add_filter('astra_breadcrumb_enabled', '__return_false');

function astra_child_remove_hello_world() {
    $hello = get_page_by_title('Hello World!', OBJECT, 'post');
    if ($hello) {
        wp_delete_post($hello->ID, true);
    }
}
add_action('init', 'astra_child_remove_hello_world');
FUNCTIONS;

file_put_contents($child_theme_dir . 'functions.php', $functions_content);
echo "✅ functions.php 已更新\n\n";

// ========== Step 5: 更新 custom.css ==========
echo "Step 5: 更新全局样式...\n";

$custom_css = <<< 'CSS'
/* ========================================
   动合空间 - 全局自定义样式
   Apple 简洁风设计系统
   ======================================== */

:root {
    --primary: #1a5276;
    --primary-light: #2980b9;
    --primary-dark: #0e2f44;
    --text-primary: #1d1d1f;
    --text-secondary: #86868b;
    --text-tertiary: #aeaeb2;
    --bg-primary: #fff;
    --bg-secondary: #f5f5f7;
    --bg-dark: #0a1628;
    --border-color: rgba(0,0,0,0.06);
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
    --shadow-md: 0 8px 30px rgba(0,0,0,0.08);
    --shadow-lg: 0 20px 60px rgba(0,0,0,0.12);
    --radius-sm: 8px;
    --radius-md: 16px;
    --radius-lg: 20px;
    --radius-xl: 980px;
    --transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif !important;
    color: var(--text-primary) !important;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

a { color: var(--primary-light); text-decoration: none; transition: var(--transition); }
a:hover { color: var(--primary); }

.site-header {
    background: rgba(255,255,255,0.72) !important;
    backdrop-filter: saturate(180%) blur(20px) !important;
    -webkit-backdrop-filter: saturate(180%) blur(20px) !important;
    border-bottom: 1px solid rgba(0,0,0,0.06) !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 1000 !important;
    transition: var(--transition) !important;
}

.site-header .main-nav {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    max-width: 1200px !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
    height: 52px !important;
}

.site-header .main-nav ul {
    display: flex !important;
    list-style: none !important;
    gap: 4px !important;
    margin: 0 !important;
    padding: 0 !important;
}

.site-header .main-nav ul li a {
    display: block !important;
    padding: 8px 18px !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    color: var(--text-primary) !important;
    border-radius: var(--radius-xl) !important;
    transition: var(--transition) !important;
    white-space: nowrap !important;
}

.site-header .main-nav ul li a:hover {
    background: rgba(0,0,0,0.04) !important;
    color: var(--primary) !important;
}

.site-header .main-nav ul li.current-menu-item a {
    background: var(--primary) !important;
    color: #fff !important;
}

.site-title a, .site-title {
    font-size: 16px !important;
    font-weight: 700 !important;
    color: var(--text-primary) !important;
    letter-spacing: -0.3px !important;
}

.site-footer {
    background: var(--bg-dark) !important;
    color: rgba(255,255,255,0.6) !important;
    padding: 32px 24px !important;
    text-align: center !important;
    font-size: 13px !important;
}

.site-footer a { color: rgba(255,255,255,0.8) !important; }
.site-footer a:hover { color: #fff !important; }

.entry-title { display: none !important; }
.page .entry-title, .single .entry-title { display: block !important; }

.site-content { padding-top: 0 !important; }

.post {
    background: var(--bg-primary) !important;
    border-radius: var(--radius-md) !important;
    padding: 28px !important;
    margin-bottom: 20px !important;
    border: 1px solid var(--border-color) !important;
    transition: var(--transition) !important;
}

.post:hover {
    box-shadow: var(--shadow-md) !important;
    transform: translateY(-2px) !important;
}

.post .entry-title a {
    font-size: 20px !important;
    font-weight: 600 !important;
    color: var(--text-primary) !important;
}

.post .entry-title a:hover { color: var(--primary) !important; }

.widget {
    background: var(--bg-primary) !important;
    border-radius: var(--radius-md) !important;
    padding: 24px !important;
    border: 1px solid var(--border-color) !important;
    margin-bottom: 20px !important;
}

.widget-title {
    font-size: 16px !important;
    font-weight: 600 !important;
    color: var(--text-primary) !important;
    padding-bottom: 12px !important;
    border-bottom: 1px solid var(--border-color) !important;
    margin-bottom: 16px !important;
}

.nav-links a, .nav-links span {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 40px !important;
    height: 40px !important;
    padding: 0 14px !important;
    border-radius: var(--radius-sm) !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    border: 1px solid var(--border-color) !important;
    transition: var(--transition) !important;
}

.nav-links a:hover {
    background: var(--primary) !important;
    color: #fff !important;
    border-color: var(--primary) !important;
}

.nav-links span.current {
    background: var(--primary) !important;
    color: #fff !important;
    border-color: var(--primary) !important;
}

::-webkit-scrollbar { width: 8px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #c7c7cc; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #aeaeb2; }

.home-page #wpadminbar { display: none !important; }
.home-page { margin-top: 0 !important; }

@media (max-width: 768px) {
    .site-header .main-nav {
        flex-wrap: wrap !important;
        height: auto !important;
        padding: 12px 16px !important;
        gap: 6px !important;
    }
    .site-header .main-nav ul {
        flex-wrap: wrap !important;
        justify-content: center !important;
    }
    .site-header .main-nav ul li a {
        padding: 6px 14px !important;
        font-size: 13px !important;
    }
}
CSS;

file_put_contents($child_theme_dir . 'custom.css', $custom_css);
echo "✅ custom.css 已更新\n\n";

echo "=== 🎉 首页设计完成！===\n";
echo "请刷新网站查看效果: http://122.51.223.46/\n";
echo "\n设计包含：\n";
echo "  ✅ Hero Banner - 渐变背景 + 公司标语\n";
echo "  ✅ 快捷入口 - 6大功能模块卡片\n";
echo "  ✅ 最新公告 - 动态文章列表\n";
echo "  ✅ 热门制度 - 制度标准列表\n";
echo "  ✅ 数据统计 - 深色背景统计区\n";
echo "  ✅ 培训推荐 - 培训资料列表\n";
echo "  ✅ CTA区域 - 新员工引导\n";
echo "  ✅ 毛玻璃导航栏\n";
echo "  ✅ 全局响应式适配\n";
