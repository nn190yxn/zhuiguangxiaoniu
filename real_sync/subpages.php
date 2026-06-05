<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 子页面模板生成脚本
 * 创建：分类列表页模板 + 文章详情页模板
 */

define('WP_USE_THEMES', false);
$wp_config_path = dirname(__FILE__) . '/wp-config.php';
if (!file_exists($wp_config_path)) { die('错误：未找到wp-config.php'); }
require_once($wp_config_path);
require_once(ABSPATH . 'wp-settings.php');

$child_theme_dir = ABSPATH . 'wp-content/themes/astra-child/';

echo "=== 子页面模板生成 ===\n\n";

// ========== 页面配置 ==========
$page_configs = array(
    '制度标准' => array(
        'gradient' => 'linear-gradient(135deg, #FF6B35, #FFD166)',
        'emoji' => '📋',
        'desc' => '公司规章制度、管理流程、操作规范',
    ),
    '知识库' => array(
        'gradient' => 'linear-gradient(135deg, #06D6A0, #48CAE4)',
        'emoji' => '📖',
        'desc' => '经验沉淀、案例分享、专业知识',
    ),
    '新闻公告' => array(
        'gradient' => 'linear-gradient(135deg, #118AB2, #7B2FF7)',
        'emoji' => '📢',
        'desc' => '公司动态、重要通知、活动预告',
    ),
    '培训资料库' => array(
        'gradient' => 'linear-gradient(135deg, #EF476F, #FF8C42)',
        'emoji' => '🎓',
        'desc' => '培训课件、学习视频、考试题库',
    ),
    '素材中心' => array(
        'gradient' => 'linear-gradient(135deg, #FFD166, #06D6A0)',
        'emoji' => '🎨',
        'desc' => '营销素材、品牌图片、宣传模板',
    ),
    '新员工学习' => array(
        'gradient' => 'linear-gradient(135deg, #7B2FF7, #118AB2)',
        'emoji' => '🚀',
        'desc' => '入职指南、学习路径、成长计划',
    ),
);

// 将配置转为可嵌入的PHP数组代码
$page_configs_php = 'array(';
foreach ($page_configs as $slug => $cfg) {
    $page_configs_php .= "'" . $slug . "' => array('gradient' => '" . $cfg['gradient'] . "', 'emoji' => '" . $cfg['emoji'] . "', 'desc' => '" . $cfg['desc'] . "'),";
}
$page_configs_php .= ')';

// ========== 导航HTML ==========
$nav_items = array(
    array('title' => '首页', 'slug' => '首页'),
    array('title' => '制度标准', 'slug' => '制度标准'),
    array('title' => '知识库', 'slug' => '知识库'),
    array('title' => '新闻公告', 'slug' => '新闻公告'),
    array('title' => '培训资料库', 'slug' => '培训资料库'),
    array('title' => '素材中心', 'slug' => '素材中心'),
    array('title' => '新员工学习', 'slug' => '新员工学习'),
    array('title' => '表格中心', 'slug' => '表格中心', 'sep' => true),
);

$nav_html = '';
foreach ($nav_items as $item) {
    if (!empty($item['sep'])) {
        $nav_html .= '            <li style="border-left:2px solid rgba(0,0,0,0.08);margin-left:8px;padding-left:16px;"><a href="/' . $item['slug'] . '/" style="color:#FF6B35;font-weight:700;">📊 ' . $item['title'] . '</a></li>' . "\n";
    } else {
        $nav_html .= '            <li data-slug="' . $item['slug'] . '"><a href="/' . $item['slug'] . '/">' . $item['title'] . '</a></li>' . "\n";
    }
}

// ========== 通用CSS（列表页和详情页共用） ==========
$common_css = <<< 'CSS'
@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@300;400;500;600;700;800;900&display=swap');
* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; margin-top: 0 !important; }
body {
    font-family: 'Noto Sans SC', -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
    color: #1d1d1f; -webkit-font-smoothing: antialiased; overflow-x: hidden; line-height: 1.6;
}
a { color: #FF6B35; text-decoration: none; transition: all 0.3s ease; }
a:hover { color: #E55A25; }
#wpadminbar { display: none !important; }

/* 导航栏 */
.site-nav {
    position: sticky; top: 0; z-index: 1000;
    background: rgba(255,255,255,0.85);
    backdrop-filter: saturate(180%) blur(20px);
    -webkit-backdrop-filter: saturate(180%) blur(20px);
    border-bottom: 1px solid rgba(0,0,0,0.06);
    padding: 0 24px;
}
.nav-inner {
    max-width: 1200px; margin: 0 auto;
    display: flex; align-items: center; justify-content: space-between; height: 56px;
}
.nav-brand { font-size: 16px; font-weight: 800; color: #1d1d1f; }
.nav-menu { display: flex; list-style: none; gap: 4px; }
.nav-menu li a {
    display: block; padding: 8px 16px; font-size: 14px; font-weight: 600;
    color: #1d1d1f; border-radius: 980px; transition: all 0.3s ease;
}
.nav-menu li a:hover { background: rgba(255,107,53,0.08); color: #FF6B35; }
.nav-menu li.active a { background: #FF6B35; color: #fff; }

/* 面包屑 */
.breadcrumb {
    max-width: 1200px; margin: 0 auto; padding: 16px 24px;
    font-size: 14px; color: #86868b;
}
.breadcrumb a { color: #86868b; }
.breadcrumb a:hover { color: #FF6B35; }
.breadcrumb span { margin: 0 8px; color: #aeaeb2; }

/* 页脚 */
.site-footer {
    background: #1a1a2e; color: rgba(255,255,255,0.6);
    padding: 32px 24px; text-align: center; font-size: 13px; margin-top: 60px;
}
.site-footer a { color: rgba(255,255,255,0.8); }
.site-footer a:hover { color: #fff; }

/* 分页 */
.pagination {
    max-width: 1200px; margin: 40px auto; padding: 0 24px;
    display: flex; justify-content: center; gap: 8px;
}
.pagination a, .pagination span {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 40px; height: 40px; padding: 0 14px;
    border-radius: 8px; font-size: 14px; font-weight: 600;
    border: 2px solid rgba(0,0,0,0.06); transition: all 0.3s ease;
}
.pagination a:hover { background: #FF6B35; color: #fff; border-color: #FF6B35; }
.pagination .current { background: #FF6B35; color: #fff; border-color: #FF6B35; }

/* 响应式 */
@media (max-width: 768px) {
    .nav-menu { flex-wrap: wrap; gap: 4px; }
    .nav-menu li a { padding: 6px 12px; font-size: 13px; }
    .card-grid { grid-template-columns: 1fr !important; }
    .banner-inner { flex-direction: column !important; text-align: center; }
    .banner-stats { justify-content: center !important; }
}
CSS;

// ========== Step 1: 创建分类列表页模板 ==========
echo "Step 1: 创建分类列表页模板...\n";

$list_css = <<< 'LISTCSS'

/* Banner */
.page-banner {
    width: 100%; padding: 60px 24px; position: relative; overflow: hidden;
}
.banner-inner {
    max-width: 1200px; margin: 0 auto;
    display: flex; align-items: center; justify-content: space-between;
    position: relative; z-index: 1;
}
.banner-text { color: #fff; }
.banner-text h1 { font-size: 40px; font-weight: 800; margin-bottom: 12px; text-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.banner-text p { font-size: 17px; opacity: 0.9; font-weight: 400; }
.banner-stats {
    display: flex; gap: 32px; color: #fff;
}
.banner-stat { text-align: center; }
.banner-stat-num { font-size: 36px; font-weight: 800; }
.banner-stat-label { font-size: 14px; opacity: 0.8; }

/* 文章卡片网格 */
.card-grid {
    max-width: 1200px; margin: 0 auto; padding: 40px 24px;
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px;
}
.article-card {
    background: #fff; border-radius: 20px; overflow: hidden;
    border: 2px solid rgba(0,0,0,0.04); transition: all 0.3s ease;
    text-decoration: none; display: block; color: #1d1d1f;
}
.article-card:hover { transform: translateY(-6px); box-shadow: 0 16px 48px rgba(0,0,0,0.1); }
.card-img {
    width: 100%; height: 160px;
    display: flex; align-items: center; justify-content: center; font-size: 48px;
}
.article-card:nth-child(3n+1) .card-img { background: linear-gradient(135deg, #FFF0E8, #FFD4B8); }
.article-card:nth-child(3n+2) .card-img { background: linear-gradient(135deg, #E3F6FD, #B8E4F7); }
.article-card:nth-child(3n+3) .card-img { background: linear-gradient(135deg, #E6FFF5, #B8F5DE); }
.card-body { padding: 22px; }
.card-tag {
    display: inline-block; padding: 4px 14px; border-radius: 100px;
    font-size: 12px; font-weight: 600; margin-bottom: 10px;
}
.tag-orange { background: #FFF0E8; color: #FF6B35; }
.tag-blue { background: #E3F6FD; color: #118AB2; }
.tag-green { background: #E6FFF5; color: #06D6A0; }
.tag-pink { background: #FFE8ED; color: #EF476F; }
.tag-purple { background: #F3E8FF; color: #7B2FF7; }
.tag-yellow { background: #FFF8E1; color: #E5A100; }
.card-title { font-size: 17px; font-weight: 700; margin-bottom: 8px; line-height: 1.5; }
.card-excerpt { font-size: 14px; color: #86868b; line-height: 1.7; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.card-meta { margin-top: 14px; font-size: 13px; color: #aeaeb2; display: flex; gap: 16px; }

/* 空状态 */
.empty-state {
    max-width: 1200px; margin: 0 auto; padding: 80px 24px; text-align: center;
}
.empty-icon { font-size: 64px; margin-bottom: 20px; }
.empty-title { font-size: 20px; font-weight: 700; color: #1d1d1f; margin-bottom: 8px; }
.empty-desc { font-size: 15px; color: #86868b; }
LISTCSS;

$category_template = <<< CATPHP
<?php
/**
 * Template Name: 分类列表页
 */
\$page_configs = {$page_configs_php};
\$current_slug = get_post_field('post_name', get_the_ID());
\$config = isset(\$page_configs[\$current_slug]) ? \$page_configs[\$current_slug] : array('gradient' => 'linear-gradient(135deg, #FF6B35, #FFD166)', 'emoji' => '📄', 'desc' => '');
\$page_title = get_the_title();

// 获取当前分类
\$category = get_category_by_slug(\$current_slug);
if (!\$category) {
    // 尝试用页面名查找分类
    \$cat = get_term_by('name', \$page_title, 'category');
    if (\$cat) \$category = \$cat;
}
\$cat_id = \$category ? \$category->term_id : 0;

// 分页
\$paged = get_query_var('paged') ? get_query_var('paged') : 1;
\$args = array(
    'post_type' => 'post',
    'posts_per_page' => 9,
    'paged' => \$paged,
    'post_status' => 'publish',
);
if (\$cat_id) {
    \$args['cat'] = \$cat_id;
}
\$query = new WP_Query(\$args);
\$total_posts = \$query->found_posts;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(\$page_title); ?> - <?php bloginfo('name'); ?></title>
<?php wp_head(); ?>
<style>
{$common_css}
{$list_css}
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

<section class="page-banner" style="background: <?php echo \$config['gradient']; ?>;">
    <div class="banner-inner">
        <div class="banner-text">
            <h1><?php echo \$config['emoji'] . ' ' . esc_html(\$page_title); ?></h1>
            <p><?php echo esc_html(\$config['desc']); ?></p>
        </div>
        <div class="banner-stats">
            <div class="banner-stat">
                <div class="banner-stat-num"><?php echo \$total_posts; ?></div>
                <div class="banner-stat-label">篇文章</div>
            </div>
            <div class="banner-stat">
                <div class="banner-stat-num"><?php echo \$category ? \$category->count : 0; ?></div>
                <div class="banner-stat-label">篇已发布</div>
            </div>
        </div>
    </div>
</section>

<div class="breadcrumb">
    <a href="/">首页</a> <span>›</span> <strong><?php echo esc_html(\$page_title); ?></strong>
</div>

<?php if (\$query->have_posts()) : ?>
<div class="card-grid">
<?php while (\$query->have_posts()) : \$query->the_post(); ?>
    <a href="<?php the_permalink(); ?>" class="article-card">
        <div class="card-img"><?php echo \$config['emoji']; ?></div>
        <div class="card-body">
            <span class="card-tag tag-orange"><?php echo esc_html(\$page_title); ?></span>
            <div class="card-title"><?php the_title(); ?></div>
            <div class="card-excerpt"><?php echo wp_trim_words(get_the_content(), 50); ?></div>
            <div class="card-meta">
                <span><?php echo get_the_date('Y-m-d'); ?></span>
                <span><?php echo get_the_author(); ?></span>
            </div>
        </div>
    </a>
<?php endwhile; wp_reset_postdata(); ?>
</div>

<?php
// 分页
\$total_pages = \$query->max_num_pages;
if (\$total_pages > 1) {
    echo '<div class="pagination">';
    echo paginate_links(array(
        'total' => \$total_pages,
        'current' => \$paged,
        'prev_text' => '‹',
        'next_text' => '›',
        'type' => 'list',
    ));
    echo '</div>';
}
?>

<?php else : ?>
<div class="empty-state">
    <div class="empty-icon">📝</div>
    <div class="empty-title">暂无内容</div>
    <div class="empty-desc">管理员正在准备内容，敬请期待...</div>
</div>
<?php endif; ?>

<footer class="site-footer">
    <p>© 2025 贵州动合空间体育发展有限公司</p>
</footer>

<script>
// 高亮当前导航
document.querySelectorAll('.nav-menu li').forEach(function(li) {
    if (li.dataset.slug === '<?php echo esc_js(\$current_slug); ?>') {
        li.classList.add('active');
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
CATPHP;

file_put_contents($child_theme_dir . 'page-category.php', $category_template);
echo "✅ page-category.php 已创建\n\n";

// ========== Step 2: 创建文章详情页模板 ==========
echo "Step 2: 创建文章详情页模板...\n";

$detail_css = <<< 'DETAILCSS'

/* 文章详情 */
.article-header {
    max-width: 800px; margin: 0 auto; padding: 40px 24px 0;
}
.article-breadcrumb {
    font-size: 14px; color: #86868b; margin-bottom: 24px;
}
.article-breadcrumb a { color: #86868b; }
.article-breadcrumb a:hover { color: #FF6B35; }
.article-breadcrumb span { margin: 0 8px; color: #aeaeb2; }
.article-title {
    font-size: 32px; font-weight: 800; line-height: 1.3;
    color: #1d1d1f; margin-bottom: 20px;
}
.article-meta {
    display: flex; gap: 20px; font-size: 14px; color: #86868b;
    padding-bottom: 24px; border-bottom: 2px solid rgba(0,0,0,0.06);
    flex-wrap: wrap;
}
.article-meta-item { display: flex; align-items: center; gap: 6px; }

/* 文章内容 */
.article-content {
    max-width: 800px; margin: 0 auto; padding: 40px 24px;
    font-size: 16px; line-height: 1.8; color: #333;
}
.article-content h2 { font-size: 24px; font-weight: 700; margin: 40px 0 16px; color: #1d1d1f; }
.article-content h3 { font-size: 20px; font-weight: 700; margin: 32px 0 12px; color: #1d1d1f; }
.article-content p { margin-bottom: 20px; }
.article-content img { max-width: 100%; height: auto; border-radius: 12px; margin: 20px 0; }
.article-content ul, .article-content ol { padding-left: 24px; margin-bottom: 20px; }
.article-content li { margin-bottom: 8px; }
.article-content blockquote {
    border-left: 4px solid #FF6B35; padding: 16px 20px;
    background: #FFF8F0; border-radius: 0 12px 12px 0; margin: 20px 0;
    color: #555;
}
.article-content a { color: #FF6B35; text-decoration: underline; }
.article-content table { width: 100%; border-collapse: collapse; margin: 20px 0; }
.article-content th, .article-content td { padding: 12px 16px; border: 1px solid rgba(0,0,0,0.08); text-align: left; }
.article-content th { background: #f5f5f7; font-weight: 600; }

/* 上下篇导航 */
.post-nav {
    max-width: 800px; margin: 0 auto; padding: 0 24px 40px;
    display: flex; justify-content: space-between; gap: 20px;
}
.post-nav a {
    flex: 1; padding: 20px; border-radius: 16px;
    border: 2px solid rgba(0,0,0,0.06); transition: all 0.3s ease;
    color: #1d1d1f;
}
.post-nav a:hover { border-color: #FF6B35; box-shadow: 0 4px 20px rgba(255,107,53,0.1); color: #FF6B35; }
.post-nav-label { font-size: 13px; color: #86868b; margin-bottom: 6px; }
.post-nav-title { font-size: 15px; font-weight: 600; }
.post-nav-next { text-align: right; }
DETAILCSS;

$single_template = <<< SINGLEPHP
<?php
// 获取文章分类
\$categories = get_the_category();
\$cat_name = !empty(\$categories) ? \$categories[0]->name : '';
\$cat_slug = !empty(\$categories) ? \$categories[0]->slug : '';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php wp_title('|', true, 'right'); ?><?php bloginfo('name'); ?></title>
<?php wp_head(); ?>
<style>
{$common_css}
{$detail_css}
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

<article>
    <div class="article-header">
        <div class="article-breadcrumb">
            <a href="/">首页</a> <span>›</span>
            <a href="/<?php echo esc_attr(\$cat_slug); ?>/"><?php echo esc_html(\$cat_name); ?></a>
            <span>›</span> <strong><?php the_title(); ?></strong>
        </div>
        <h1 class="article-title"><?php the_title(); ?></h1>
        <div class="article-meta">
            <div class="article-meta-item">📅 <?php echo get_the_date('Y-m-d'); ?></div>
            <div class="article-meta-item">✍️ <?php echo get_the_author(); ?></div>
            <div class="article-meta-item">📁 <?php echo esc_html(\$cat_name); ?></div>
        </div>
    </div>
    <div class="article-content">
        <?php the_content(); ?>
    </div>
</article>

<div class="post-nav">
    <?php
    \$prev_post = get_previous_post();
    \$next_post = get_next_post();
    if (\$prev_post) :
    ?>
    <a href="<?php echo get_permalink(\$prev_post->ID); ?>">
        <div class="post-nav-label">← 上一篇</div>
        <div class="post-nav-title"><?php echo esc_html(\$prev_post->post_title); ?></div>
    </a>
    <?php else : ?>
    <div></div>
    <?php endif; ?>

    <?php if (\$next_post) : ?>
    <a href="<?php echo get_permalink(\$next_post->ID); ?>" class="post-nav-next">
        <div class="post-nav-label">下一篇 →</div>
        <div class="post-nav-title"><?php echo esc_html(\$next_post->post_title); ?></div>
    </a>
    <?php endif; ?>
</div>

<footer class="site-footer">
    <p>© 2025 贵州动合空间体育发展有限公司</p>
</footer>

<script>
// 高亮当前分类导航
document.querySelectorAll('.nav-menu li').forEach(function(li) {
    if (li.dataset.slug === '<?php echo esc_js(\$cat_slug); ?>') {
        li.classList.add('active');
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
SINGLEPHP;

file_put_contents($child_theme_dir . 'single.php', $single_template);
echo "✅ single.php 已创建\n\n";

// ========== Step 3: 分配模板到6个页面 ==========
echo "Step 3: 分配模板到页面...\n";

$assign_count = 0;
foreach ($page_configs as $slug => $config) {
    // 跳过"制度标准"，它使用独立的分组展示模板 page-zhidu-biaozhun.php
    if ($slug === '制度标准') {
        echo "  ⏭️ {$slug} → 跳过（使用独立分组模板）\n";
        continue;
    }
    $page = get_page_by_path($slug);
    if ($page) {
        update_post_meta($page->ID, '_wp_page_template', 'page-category.php');
        echo "  ✅ {$slug} → 分类列表页模板\n";
        $assign_count++;
    } else {
        echo "  ⚠️ 未找到页面: {$slug}\n";
    }
}
echo "\n共分配 {$assign_count} 个页面\n\n";

// ========== Step 4: 清除缓存 ==========
echo "Step 4: 清除缓存...\n";
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ OPcache 已重置\n";
}

echo "\n=== 🎉 子页面模板生成完成！===\n";
echo "已创建：\n";
echo "  ✅ page-category.php - 分类列表页模板（6个页面共用）\n";
echo "  ✅ single.php - 文章详情页模板\n";
echo "\n请用无痕模式访问任意子页面查看效果，例如：\n";
echo "  http://122.51.223.46/制度标准/\n";
echo "  http://122.51.223.46/新闻公告/\n";
