<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 修复首页：导航菜单 + 字体 + 隐藏管理栏
 */

define('WP_USE_THEMES', false);
$wp_config_path = dirname(__FILE__) . '/wp-config.php';
if (!file_exists($wp_config_path)) { die('错误：未找到wp-config.php'); }
require_once($wp_config_path);
require_once(ABSPATH . 'wp-settings.php');

$child_theme_dir = ABSPATH . 'wp-content/themes/astra-child/';

echo "=== 修复首页导航和字体 ===\n\n";

// ========== Step 1: 查找正确的菜单 ==========
echo "Step 1: 查找导航菜单...\n";

$menus = get_terms('nav_menu', array('hide_empty' => false));
$menu_name = '';
$menu_items = null;

if (!empty($menus) && !is_wp_error($menus)) {
    foreach ($menus as $menu) {
        echo "  找到菜单: {$menu->name} (ID: {$menu->term_id})\n";
        $items = wp_get_nav_menu_items($menu->term_id);
        if ($items && count($items) >= 5) {
            $menu_name = $menu->slug;
            $menu_items = $items;
            echo "  ✅ 使用菜单: {$menu->name} (包含 " . count($items) . " 个项目)\n";
        }
    }
}

if (!$menu_items) {
    echo "  ⚠️ 没有找到合适的菜单，将使用固定导航\n";
}

echo "\n";

// ========== Step 2: 更新 functions.php ==========
echo "Step 2: 更新 functions.php...\n";

$functions_content = <<< 'FUNCTIONS'
<?php
/**
 * Astra Child Theme - 动合空间 v4.0
 */

function astra_child_enqueue_styles() {
    wp_enqueue_style('astra-child-custom', get_stylesheet_directory_uri() . '/custom.css', array(), '4.0');
}
add_action('wp_enqueue_scripts', 'astra_child_enqueue_styles');

function astra_child_register_menus() {
    register_nav_menus(array(
        'primary' => '主导航菜单',
        'footer'  => '底部菜单',
    ));
}
add_action('init', 'astra_child_register_menus');

// 隐藏非管理员的前端管理栏
function astra_child_hide_admin_bar() {
    if (!current_user_can('manage_options')) {
        show_admin_bar(false);
    }
}
add_action('after_setup_theme', 'astra_child_hide_admin_bar');

// 禁用标题和面包屑
add_filter('astra_the_title_enabled', '__return_false');
add_filter('astra_breadcrumb_enabled', '__return_false');
add_filter('astra_single_post_banner_enabled', '__return_false');

// 强制无侧边栏
add_filter('astra_page_layout', function($l) { return 'no-sidebar'; });
add_filter('astra_single_post_layout', function($l) { return 'no-sidebar'; });
add_filter('astra_blog_layout', function($l) { return 'no-sidebar'; });
add_filter('astra_archive_layout', function($l) { return 'no-sidebar'; });
add_filter('astra_get_content_layout', function($c) { return 'plain-container'; });

// 移除 Hello World
function astra_child_remove_hello_world() {
    $hello = get_page_by_title('Hello World!', OBJECT, 'post');
    if ($hello) wp_delete_post($hello->ID, true);
}
add_action('init', 'astra_child_remove_hello_world');
FUNCTIONS;

file_put_contents($child_theme_dir . 'functions.php', $functions_content);
echo "✅ functions.php 已更新\n\n";

// ========== Step 3: 重建首页模板（修复导航+字体）==========
echo "Step 3: 重建首页模板...\n";

// 构建导航HTML
$nav_html = '';
if ($menu_items) {
    foreach ($menu_items as $item) {
        $title = esc_html($item->title);
        $url = $item->url;
        $is_home = ($title === '首页') ? ' active' : '';
        $nav_html .= '            <li class="' . $is_home . '"><a href="' . $url . '">' . $title . '</a></li>' . "\n";
    }
} else {
    // 固定导航
    $nav_items = array(
        array('title' => '首页', 'url' => '/'),
        array('title' => '制度标准', 'url' => '/制度标准/'),
        array('title' => '知识库', 'url' => '/知识库/'),
        array('title' => '新闻公告', 'url' => '/新闻公告/'),
        array('title' => '培训资料库', 'url' => '/培训资料库/'),
        array('title' => '素材中心', 'url' => '/素材中心/'),
        array('title' => '新员工学习', 'url' => '/新员工学习/'),
    );
    foreach ($nav_items as $item) {
        $is_home = ($item['title'] === '首页') ? ' active' : '';
        $nav_html .= '            <li class="' . $is_home . '"><a href="' . $item['url'] . '">' . $item['title'] . '</a></li>' . "\n";
    }
}

// 获取文章数据
$news_posts = get_posts(array('numberposts' => 3, 'category_name' => '新闻公告', 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'));
$policy_posts = get_posts(array('numberposts' => 3, 'category_name' => '制度标准', 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'));
$training_posts = get_posts(array('numberposts' => 3, 'category_name' => '培训资料库', 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'));

// 构建文章卡片HTML
function build_cards($posts, $emoji, $tag_class, $tag_text) {
    if (empty($posts)) {
        return '        <a href="#" class="ccard" style="opacity:0.5"><div class="ccard-img">' . $emoji . '</div><div class="ccard-body"><span class="ccard-tag ' . $tag_class . '">' . $tag_text . '</span><div class="ccard-title">暂无内容</div><div class="ccard-excerpt">管理员正在准备内容，敬请期待...</div><div class="ccard-meta">待发布</div></div></a>' . "\n";
    }
    $html = '';
    foreach ($posts as $post) {
        $excerpt = wp_trim_words($post->post_content, 40);
        $date = get_the_date('Y-m-d', $post->ID);
        $html .= '        <a href="' . get_permalink($post->ID) . '" class="ccard"><div class="ccard-img">' . $emoji . '</div><div class="ccard-body"><span class="ccard-tag ' . $tag_class . '">' . $tag_text . '</span><div class="ccard-title">' . esc_html($post->post_title) . '</div><div class="ccard-excerpt">' . esc_html($excerpt) . '</div><div class="ccard-meta">' . $date . '</div></div></a>' . "\n";
    }
    return $html;
}

$news_cards = build_cards($news_posts, '📢', 'tag-blue', '新闻公告');
$policy_cards = build_cards($policy_posts, '📋', 'tag-green', '制度标准');
$training_cards = build_cards($training_posts, '🎓', 'tag-purple', '培训资料');

$template_content = <<< TPL
<?php
/**
 * Template Name: 企业首页v4
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@300;400;500;600;700;800;900&display=swap');

* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: 'Noto Sans SC', -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
    color: #1d1d1f;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
    line-height: 1.6;
}
a { color: #FF6B35; text-decoration: none; transition: all 0.3s ease; }
a:hover { color: #E55A25; }

/* 隐藏WP管理栏 */
#wpadminbar { display: none !important; }
html { margin-top: 0 !important; }

/* 导航栏 */
.site-nav {
    position: sticky; top: 0; z-index: 1000;
    background: rgba(255,255,255,0.85);
    backdrop-filter: saturate(180%) blur(20px);
    -webkit-backdrop-filter: saturate(180%) blur(20px);
    border-bottom: 1px solid rgba(0,0,0,0.06);
    padding: 0 24px;
    font-family: 'Noto Sans SC', sans-serif;
}
.nav-inner {
    max-width: 1200px; margin: 0 auto;
    display: flex; align-items: center; justify-content: space-between;
    height: 56px;
}
.nav-brand { font-size: 16px; font-weight: 800; color: #1d1d1f; letter-spacing: -0.3px; }
.nav-menu { display: flex; list-style: none; gap: 4px; }
.nav-menu li a {
    display: block; padding: 8px 16px;
    font-size: 14px; font-weight: 600; color: #1d1d1f;
    border-radius: 980px; transition: all 0.3s ease;
    font-family: 'Noto Sans SC', sans-serif;
}
.nav-menu li a:hover { background: rgba(255,107,53,0.08); color: #FF6B35; }
.nav-menu li.active a { background: #FF6B35; color: #fff; }

/* Hero */
.hero {
    width: 100%; min-height: 520px;
    display: flex; align-items: center; justify-content: center; text-align: center;
    background: linear-gradient(135deg, #FF6B35 0%, #FF8C42 25%, #FFD166 50%, #06D6A0 75%, #118AB2 100%);
    position: relative; overflow: hidden;
    font-family: 'Noto Sans SC', sans-serif;
}
.hero::before {
    content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
    background: radial-gradient(circle at 20% 30%, rgba(255,255,255,0.2) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(255,255,255,0.15) 0%, transparent 35%);
    animation: heroFloat 15s ease-in-out infinite;
}
@keyframes heroFloat {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33% { transform: translate(20px, -20px) scale(1.02); }
    66% { transform: translate(-15px, 15px) scale(0.98); }
}
.hero-decor { position: absolute; font-size: 80px; opacity: 0.15; animation: floatAnim 8s ease-in-out infinite; z-index: 1; }
.hero-decor:nth-child(1) { top: 10%; left: 8%; }
.hero-decor:nth-child(2) { top: 15%; right: 10%; font-size: 60px; animation-delay: 2s; }
.hero-decor:nth-child(3) { bottom: 20%; left: 15%; font-size: 70px; animation-delay: 4s; }
.hero-decor:nth-child(4) { bottom: 15%; right: 12%; font-size: 50px; animation-delay: 1s; }
@keyframes floatAnim {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    50% { transform: translateY(-20px) rotate(10deg); }
}
.hero-inner { position: relative; z-index: 2; max-width: 800px; padding: 60px 24px; }
.hero-badge {
    display: inline-block; padding: 8px 24px;
    background: rgba(255,255,255,0.25); border: 2px solid rgba(255,255,255,0.4);
    border-radius: 100px; color: #fff; font-size: 14px; font-weight: 600;
    letter-spacing: 2px; margin-bottom: 28px;
    font-family: 'Noto Sans SC', sans-serif;
}
.hero h1 {
    font-size: 56px; font-weight: 900; color: #fff; line-height: 1.15;
    margin-bottom: 20px; letter-spacing: -1px;
    text-shadow: 0 2px 20px rgba(0,0,0,0.1);
    font-family: 'Noto Sans SC', sans-serif;
}
.hero p {
    font-size: 20px; color: rgba(255,255,255,0.92); line-height: 1.7;
    font-weight: 400; max-width: 600px; margin: 0 auto 36px;
    font-family: 'Noto Sans SC', sans-serif;
}
.hero-btns { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
.btn-w {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 16px 40px; background: #fff; color: #FF6B35;
    border: none; border-radius: 980px; font-size: 17px; font-weight: 700;
    text-decoration: none; transition: all 0.3s ease;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    font-family: 'Noto Sans SC', sans-serif;
}
.btn-w:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,0,0,0.2); color: #E55A25; }
.btn-g {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 16px 40px; background: rgba(255,255,255,0.2); color: #fff;
    border: 2px solid rgba(255,255,255,0.5); border-radius: 980px;
    font-size: 17px; font-weight: 600; text-decoration: none; transition: all 0.3s ease;
    font-family: 'Noto Sans SC', sans-serif;
}
.btn-g:hover { background: rgba(255,255,255,0.35); transform: translateY(-2px); color: #fff; }

/* Section */
.section { padding: 80px 24px; max-width: 1200px; margin: 0 auto; }
.bg-warm { background: #FFF8F0; }
.bg-green { background: #F0FFF4; }
.section-hd { text-align: center; margin-bottom: 48px; }
.section-label {
    display: inline-block; font-size: 13px; font-weight: 700;
    color: #FF6B35; letter-spacing: 3px; text-transform: uppercase;
    margin-bottom: 10px; font-family: 'Noto Sans SC', sans-serif;
}
.section-title {
    font-size: 36px; font-weight: 800; color: #1d1d1f;
    line-height: 1.2; margin-bottom: 14px;
    font-family: 'Noto Sans SC', sans-serif;
}
.section-desc {
    font-size: 17px; color: #86868b; line-height: 1.6;
    max-width: 560px; margin: 0 auto;
    font-family: 'Noto Sans SC', sans-serif;
}

/* 快捷入口 */
.quick-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.qcard {
    background: #fff; border-radius: 24px; padding: 32px 24px;
    text-decoration: none; transition: all 0.4s ease;
    border: 2px solid transparent; position: relative; overflow: hidden; color: #1d1d1f;
    font-family: 'Noto Sans SC', sans-serif;
}
.qcard::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px; border-radius: 24px 24px 0 0; }
.qcard:nth-child(1)::before { background: linear-gradient(90deg, #FF6B35, #FF8C42); }
.qcard:nth-child(2)::before { background: linear-gradient(90deg, #06D6A0, #0AD6A8); }
.qcard:nth-child(3)::before { background: linear-gradient(90deg, #118AB2, #48CAE4); }
.qcard:nth-child(4)::before { background: linear-gradient(90deg, #EF476F, #FF6B8A); }
.qcard:nth-child(5)::before { background: linear-gradient(90deg, #FFD166, #FFE066); }
.qcard:nth-child(6)::before { background: linear-gradient(90deg, #7B2FF7, #9D4EDD); }
.qcard:nth-child(1) { border-color: rgba(255,107,53,0.1); }
.qcard:nth-child(2) { border-color: rgba(6,214,160,0.1); }
.qcard:nth-child(3) { border-color: rgba(17,138,178,0.1); }
.qcard:nth-child(4) { border-color: rgba(239,71,111,0.1); }
.qcard:nth-child(5) { border-color: rgba(255,209,102,0.15); }
.qcard:nth-child(6) { border-color: rgba(123,47,247,0.1); }
.qcard:hover { transform: translateY(-8px); box-shadow: 0 20px 50px rgba(0,0,0,0.1); }
.qcard-icon {
    width: 56px; height: 56px; border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; margin-bottom: 18px;
}
.qcard:nth-child(1) .qcard-icon { background: linear-gradient(135deg, #FFF0E8, #FFE0CC); }
.qcard:nth-child(2) .qcard-icon { background: linear-gradient(135deg, #E6FFF5, #CCFFED); }
.qcard:nth-child(3) .qcard-icon { background: linear-gradient(135deg, #E3F6FD, #CCE9F8); }
.qcard:nth-child(4) .qcard-icon { background: linear-gradient(135deg, #FFE8ED, #FFD0D9); }
.qcard:nth-child(5) .qcard-icon { background: linear-gradient(135deg, #FFF8E1, #FFF0C2); }
.qcard:nth-child(6) .qcard-icon { background: linear-gradient(135deg, #F3E8FF, #E8D5FF); }
.qcard-title { font-size: 19px; font-weight: 700; margin-bottom: 8px; }
.qcard-desc { font-size: 14px; color: #86868b; line-height: 1.6; }

/* 内容卡片 */
.card-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
.ccard {
    background: #fff; border-radius: 20px; overflow: hidden;
    transition: all 0.3s ease; border: 2px solid rgba(0,0,0,0.04);
    text-decoration: none; display: block; color: #1d1d1f;
    font-family: 'Noto Sans SC', sans-serif;
}
.ccard:hover { transform: translateY(-6px); box-shadow: 0 16px 48px rgba(0,0,0,0.1); }
.ccard-img {
    width: 100%; height: 160px;
    display: flex; align-items: center; justify-content: center; font-size: 48px;
}
.ccard:nth-child(1) .ccard-img { background: linear-gradient(135deg, #FFF0E8, #FFD4B8); }
.ccard:nth-child(2) .ccard-img { background: linear-gradient(135deg, #E3F6FD, #B8E4F7); }
.ccard:nth-child(3) .ccard-img { background: linear-gradient(135deg, #E6FFF5, #B8F5DE); }
.ccard-body { padding: 22px; }
.ccard-tag {
    display: inline-block; padding: 4px 14px; border-radius: 100px;
    font-size: 12px; font-weight: 600; margin-bottom: 10px;
}
.tag-blue { background: #E3F6FD; color: #118AB2; }
.tag-green { background: #E6FFF5; color: #06D6A0; }
.tag-purple { background: #F3E8FF; color: #7B2FF7; }
.ccard-title { font-size: 17px; font-weight: 700; margin-bottom: 8px; line-height: 1.5; }
.ccard-excerpt { font-size: 14px; color: #86868b; line-height: 1.7; }
.ccard-meta { margin-top: 14px; font-size: 13px; color: #aeaeb2; }

/* 统计 */
.stats {
    background: linear-gradient(135deg, #FF6B35 0%, #EF476F 30%, #7B2FF7 60%, #118AB2 100%);
    padding: 56px 24px; text-align: center;
}
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 32px; max-width: 1000px; margin: 0 auto; }
.stat-num { font-size: 40px; font-weight: 900; color: #fff; margin-bottom: 6px; font-family: 'Noto Sans SC', sans-serif; }
.stat-label { font-size: 15px; color: rgba(255,255,255,0.85); font-weight: 500; font-family: 'Noto Sans SC', sans-serif; }

/* CTA */
.cta { text-align: center; padding: 72px 24px; }
.cta h2 { font-size: 34px; font-weight: 800; margin-bottom: 14px; font-family: 'Noto Sans SC', sans-serif; }
.cta p { font-size: 17px; color: #86868b; margin-bottom: 28px; font-family: 'Noto Sans SC', sans-serif; }

/* 页脚 */
.site-footer {
    background: #1a1a2e; color: rgba(255,255,255,0.6);
    padding: 32px 24px; text-align: center; font-size: 13px;
    font-family: 'Noto Sans SC', sans-serif;
}
.site-footer a { color: rgba(255,255,255,0.8); }
.site-footer a:hover { color: #fff; }

/* 响应式 */
@media (max-width: 768px) {
    .hero h1 { font-size: 36px; }
    .hero p { font-size: 16px; }
    .hero { min-height: 400px; }
    .hero-decor { font-size: 50px !important; }
    .quick-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .card-grid { grid-template-columns: 1fr; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 24px; }
    .section-title { font-size: 26px; }
    .section { padding: 48px 16px; }
    .nav-menu { flex-wrap: wrap; gap: 4px; }
    .nav-menu li a { padding: 6px 12px; font-size: 13px; }
    .hero-btns { flex-direction: column; align-items: center; }
}
</style>
</head>
<body>

<nav class="site-nav">
    <div class="nav-inner">
        <div class="nav-brand">贵州动合空间体育发展</div>
        <ul class="nav-menu">
{$nav_html}        </ul>
    </div>
</nav>

<section class="hero">
    <div class="hero-decor">⚽</div>
    <div class="hero-decor">🏀</div>
    <div class="hero-decor">🏃</div>
    <div class="hero-decor">🏅</div>
    <div class="hero-inner">
        <div class="hero-badge">🏆 贵州动合空间体育发展有限公司</div>
        <h1>智慧管理平台</h1>
        <p>制度标准 · 知识共享 · 培训成长 · 素材赋能<br>一站式企业内部协作平台</p>
        <div class="hero-btns">
            <a href="/制度标准/" class="btn-w">📚 浏览制度</a>
            <a href="/新员工学习/" class="btn-g">🎓 新人指南</a>
        </div>
    </div>
</section>

<div class="bg-warm">
    <div class="section">
        <div class="section-hd">
            <div class="section-label">Quick Access</div>
            <h2 class="section-title">快捷入口</h2>
            <p class="section-desc">高效直达，快速找到你需要的内容</p>
        </div>
        <div class="quick-grid">
            <a href="/制度标准/" class="qcard"><div class="qcard-icon">📋</div><div class="qcard-title">制度标准</div><div class="qcard-desc">公司规章制度、管理流程、操作规范</div></a>
            <a href="/知识库/" class="qcard"><div class="qcard-icon">📖</div><div class="qcard-title">知识库</div><div class="qcard-desc">经验沉淀、案例分享、专业知识</div></a>
            <a href="/新闻公告/" class="qcard"><div class="qcard-icon">📢</div><div class="qcard-title">新闻公告</div><div class="qcard-desc">公司动态、重要通知、活动预告</div></a>
            <a href="/培训资料库/" class="qcard"><div class="qcard-icon">🎓</div><div class="qcard-title">培训资料库</div><div class="qcard-desc">培训课件、学习视频、考试题库</div></a>
            <a href="/素材中心/" class="qcard"><div class="qcard-icon">🎨</div><div class="qcard-title">素材中心</div><div class="qcard-desc">营销素材、品牌图片、宣传模板</div></a>
            <a href="/新员工学习/" class="qcard"><div class="qcard-icon">🚀</div><div class="qcard-title">新员工学习</div><div class="qcard-desc">入职指南、学习路径、成长计划</div></a>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-hd">
        <div class="section-label">Latest News</div>
        <h2 class="section-title">最新公告</h2>
        <p class="section-desc">了解公司最新动态和重要通知</p>
    </div>
    <div class="card-grid">
{$news_cards}    </div>
</div>

<div class="bg-green">
    <div class="section">
        <div class="section-hd">
            <div class="section-label">Policies</div>
            <h2 class="section-title">热门制度</h2>
            <p class="section-desc">常用规章制度，快速查阅</p>
        </div>
        <div class="card-grid">
{$policy_cards}    </div>
</div>

<section class="stats">
    <div class="stats-grid">
        <div><div class="stat-num">6</div><div class="stat-label">功能模块</div></div>
        <div><div class="stat-num">AI</div><div class="stat-label">智能赋能</div></div>
        <div><div class="stat-num">∞</div><div class="stat-label">知识沉淀</div></div>
        <div><div class="stat-num">24/7</div><div class="stat-label">随时访问</div></div>
    </div>
</section>

<div class="section">
    <div class="section-hd">
        <div class="section-label">Training</div>
        <h2 class="section-title">培训推荐</h2>
        <p class="section-desc">精选培训课程，助力专业成长</p>
    </div>
    <div class="card-grid">
{$training_cards}    </div>
</div>

<div class="bg-warm">
    <div class="cta">
        <h2>🚀 开始你的学习之旅</h2>
        <p>新员工入职指南，助你快速融入团队</p>
        <a href="/新员工学习/" class="btn-w">立即开始 →</a>
    </div>
</div>

<footer class="site-footer">
    <p>© 2025 贵州动合空间体育发展有限公司 · 智慧管理平台</p>
</footer>

<?php wp_footer(); ?>
</body>
</html>
TPL;

file_put_contents($child_theme_dir . 'page-home.php', $template_content);
echo "✅ 首页模板已更新（v4.0 修复导航+字体）\n\n";

// ========== Step 4: 设置模板 ==========
$homepage = get_page_by_path('首页');
if ($homepage) {
    update_post_meta($homepage->ID, '_wp_page_template', 'page-home.php');
    echo "✅ 首页模板已设置\n";
}

// 清缓存
if (function_exists('opcache_reset')) opcache_reset();
echo "✅ OPcache 已重置\n";

echo "\n=== 🎉 修复完成！===\n";
echo "修复内容：\n";
echo "  ✅ 导航菜单 → 使用正确的菜单项\n";
echo "  ✅ 字体统一 → 引入 Noto Sans SC 中文字体\n";
echo "  ✅ 隐藏WP管理栏 → 前端不再显示\n";
echo "  ✅ 所有元素统一 font-family\n";
echo "\n请用无痕模式刷新: http://122.51.223.46/\n";
