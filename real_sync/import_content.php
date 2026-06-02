<?php
/**
 * WordPress 批量导入 + 表格中心创建脚本
 *
 * 功能：
 *   Step 1: 加载 WordPress（不加载 PhpSpreadsheet）
 *   Step 2: Markdown → HTML 转换器（纯PHP）
 *   Step 3: 创建分类结构
 *   Step 4: 批量导入49个MD文件为WordPress文章
 *   Step 5: 扫描Excel文件（用户已预上传）
 *   Step 6: 创建表格中心页面模板 + WordPress页面
 *   Step 7: 清除缓存
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// 立即输出每一行
@ini_set('implicit_flush', 1);
@ini_set('output_buffering', 'off');

// ============================================================
// Step 1: 加载 WordPress
// ============================================================
echo "=== Step 1: 加载 WordPress ===\n";

// 开启所有错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

define('WP_USE_THEMES', false);
$wp_config_path = dirname(__FILE__) . '/wp-config.php';
if (!file_exists($wp_config_path)) {
    die("错误：找不到 wp-config.php，路径：{$wp_config_path}\n");
}
require_once($wp_config_path);
require_once(ABSPATH . 'wp-settings.php');

echo "WordPress 加载成功\n\n";

// ============================================================
// 全局配置
// ============================================================
$source_dir  = ABSPATH . '体系文件_最终版/';
$upload_dir  = ABSPATH . 'wp-content/uploads/tables/';
$child_theme = ABSPATH . 'wp-content/themes/astra-child/';

if (!is_dir($source_dir)) {
    die("错误：源文件目录不存在：{$source_dir}\n");
}

// ============================================================
// Step 2: Markdown → HTML 转换器（纯PHP实现）
// ============================================================
echo "=== Step 2: Markdown → HTML 转换器就绪 ===\n\n";

/**
 * 将 Markdown 文本转换为 HTML
 * 支持：h1~h6、表格、引用块、有序/无序列表、加粗、行内代码、链接、水平线、段落
 */
function md_to_html($md_text) {
    $lines = explode("\n", $md_text);
    $html_lines = array();
    $in_table = false;
    $table_rows = array();
    $in_ul = false;
    $in_ol = false;
    $in_blockquote = false;

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];

        // 检测表格行
        if (preg_match('/^\|(.+)\|$/', $line) && trim($line) !== '|') {
            if (!$in_table) {
                $in_table = true;
                $table_rows = array();
            }
            // 跳过分隔行（|---|---|）
            if (preg_match('/^\|[\s\-:|]+\|$/', $line)) {
                continue;
            }
            $cells = array_map('trim', explode('|', $line));
            // 去掉首尾空元素
            array_shift($cells);
            array_pop($cells);
            $table_rows[] = $cells;
            continue;
        } else {
            // 不在表格行，如果之前在表格中则输出表格
            if ($in_table) {
                $in_table = false;
                $html_lines[] = build_table_html($table_rows);
                $table_rows = array();
            }
        }

        // 关闭列表状态
        $trimmed = trim($line);
        if ($in_ul && !preg_match('/^[\-\*]\s/', $line) && $trimmed !== '') {
            $html_lines[] = '</ul>';
            $in_ul = false;
        }
        if ($in_ol && !preg_match('/^\d+\.\s/', $line) && $trimmed !== '') {
            $html_lines[] = '</ol>';
            $in_ol = false;
        }
        if ($in_blockquote && !preg_match('/^>\s?/', $line) && $trimmed !== '') {
            $html_lines[] = '</blockquote>';
            $in_blockquote = false;
        }

        // 空行
        if ($trimmed === '') {
            continue;
        }

        // 引用块
        if (preg_match('/^>\s?(.+)$/', $line, $m)) {
            if (!$in_blockquote) {
                $html_lines[] = '<blockquote>';
                $in_blockquote = true;
            }
            $html_lines[] = '<p>' . inline_format($m[1]) . '</p>';
            continue;
        }

        // 标题 h1~h6
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
            $level = strlen($m[1]);
            $html_lines[] = '<h' . $level . '>' . inline_format($m[2]) . '</h' . $level . '>';
            continue;
        }

        // 水平线
        if (preg_match('/^(\-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
            $html_lines[] = '<hr>';
            continue;
        }

        // 无序列表
        if (preg_match('/^[\-\*]\s+(.+)$/', $line, $m)) {
            if (!$in_ul) {
                $html_lines[] = '<ul>';
                $in_ul = true;
            }
            $html_lines[] = '<li>' . inline_format($m[1]) . '</li>';
            continue;
        }

        // 有序列表
        if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
            if (!$in_ol) {
                $html_lines[] = '<ol>';
                $in_ol = true;
            }
            $html_lines[] = '<li>' . inline_format($m[1]) . '</li>';
            continue;
        }

        // 段落
        $html_lines[] = '<p>' . inline_format($trimmed) . '</p>';
    }

    // 关闭未关闭的标签
    if ($in_table) {
        $html_lines[] = build_table_html($table_rows);
    }
    if ($in_ul) {
        $html_lines[] = '</ul>';
    }
    if ($in_ol) {
        $html_lines[] = '</ol>';
    }
    if ($in_blockquote) {
        $html_lines[] = '</blockquote>';
    }

    return implode("\n", $html_lines);
}

/**
 * 构建 HTML 表格
 */
function build_table_html($rows) {
    if (empty($rows)) {
        return '';
    }
    $L = array();
    $L[] = '<table class="md-table">';
    $L[] = '<thead><tr>';
    foreach ($rows[0] as $cell) {
        $L[] = '<th>' . inline_format($cell) . '</th>';
    }
    $L[] = '</tr></thead>';
    $L[] = '<tbody>';
    for ($r = 1; $r < count($rows); $r++) {
        $row_class = ($r % 2 === 0) ? ' class="even"' : ' class="odd"';
        $L[] = '<tr' . $row_class . '>';
        foreach ($rows[$r] as $cell) {
            $L[] = '<td>' . inline_format($cell) . '</td>';
        }
        $L[] = '</tr>';
    }
    $L[] = '</tbody>';
    $L[] = '</table>';
    return implode("\n", $L);
}

/**
 * 行内格式化：加粗、行内代码、链接
 */
function inline_format($text) {
    // 行内代码
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    // 加粗
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    // 链接
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);
    return $text;
}

// ============================================================
// Step 3: 创建分类结构
// ============================================================
echo "=== Step 3: 创建分类结构 ===\n";

$category_structure = array(
    '制度标准' => array(
        '总纲与原则',
        '门店运营标准',
        '人员管理',
        '店长管理机制',
        '服务标准',
        '教学标准',
        '业绩管理',
        '品牌标准',
    ),
    '知识库' => array(),
);

$category_ids = array();

foreach ($category_structure as $parent_name => $children) {
    // 创建或获取父分类
    $parent_term = term_exists($parent_name, 'category');
    if (!$parent_term) {
        $result = wp_insert_term($parent_name, 'category', array('slug' => sanitize_title($parent_name)));
        if (is_wp_error($result)) {
            echo "  警告：创建分类 '{$parent_name}' 失败：" . $result->get_error_message() . "\n";
            continue;
        }
        $parent_id = $result['term_id'];
        echo "  创建父分类：{$parent_name} (ID: {$parent_id})\n";
    } else {
        $parent_id = is_array($parent_term) ? $parent_term['term_id'] : $parent_term;
        echo "  父分类已存在：{$parent_name} (ID: {$parent_id})\n";
    }
    $category_ids[$parent_name] = $parent_id;

    // 创建子分类
    foreach ($children as $child_name) {
        $child_term = term_exists($child_name, 'category');
        if (!$child_term) {
            $result = wp_insert_term($child_name, 'category', array(
                'slug'   => sanitize_title($child_name),
                'parent' => (int) $parent_id,
            ));
            if (is_wp_error($result)) {
                echo "    警告：创建子分类 '{$child_name}' 失败：" . $result->get_error_message() . "\n";
                continue;
            }
            $child_id = $result['term_id'];
            echo "    创建子分类：{$child_name} (ID: {$child_id})\n";
        } else {
            $child_id = is_array($child_term) ? $child_term['term_id'] : $child_term;
            echo "    子分类已存在：{$child_name} (ID: {$child_id})\n";
        }
        $category_ids[$child_name] = $child_id;
    }
}

echo "分类结构创建完成\n\n";

// ============================================================
// Step 4: 批量导入49个MD文件为WordPress文章
// ============================================================
echo "=== Step 4: 批量导入MD文件 ===\n";

/**
 * 文件前缀 → 分类映射
 */
function get_category_by_filename($filename) {
    $basename = basename($filename);
    $name = preg_replace('/\.md$/', '', $basename);

    // 知识库文件
    if (preg_match('/^(08_|发布说明|追光小牛体系_工具表单|追光小牛体系_合并版表单|追光小牛体系_执行控制)/', $name)) {
        return '知识库';
    }

    // 总纲与原则
    if (preg_match('/^(00A_|00_追光小牛连锁运营体系_总纲|00_成长基金)/', $name)) {
        return '总纲与原则';
    }

    // 门店运营标准
    if (preg_match('/^01[A-G]?_/', $name) || $name === '01_门店运营标准体系') {
        return '门店运营标准';
    }

    // 人员管理
    if (preg_match('/^02[A-Z]?_/', $name) || $name === '02_人员管理体系') {
        return '人员管理';
    }

    // 店长管理机制
    if (preg_match('/^03[A-Z]?_/', $name) || $name === '03_店长管理机制') {
        return '店长管理机制';
    }

    // 服务标准
    if (preg_match('/^04[A-Z]?_/', $name) || $name === '04_服务标准体系') {
        return '服务标准';
    }

    // 教学标准
    if (preg_match('/^05[A-Z]?_/', $name) || $name === '05_教学标准体系') {
        return '教学标准';
    }

    // 业绩管理
    if (preg_match('/^06[A-Z]?_/', $name) || $name === '06_业绩管理体系') {
        return '业绩管理';
    }

    // 品牌标准
    if (preg_match('/^07_/', $name) || $name === '07_品牌一致性标准') {
        return '品牌标准';
    }

    // 默认归入知识库
    return '知识库';
}

/**
 * 从文件名提取文章标题
 * 去掉编号前缀和.md后缀
 */
function get_title_from_filename($filename) {
    $basename = basename($filename);
    $name = preg_replace('/\.md$/', '', $basename);
    // 去掉编号前缀，如 00A_, 01_, 01A_, 02C_ 等
    $name = preg_replace('/^[0-9]+[A-Z]?_/', '', $name);
    return $name;
}

/**
 * 递归扫描目录获取所有MD文件
 */
function scan_md_files($dir) {
    $files = array();
    if (!is_dir($dir)) {
        return $files;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . $item;
        if (is_dir($path)) {
            $files = array_merge($files, scan_md_files($path . '/'));
        } elseif (preg_match('/\.md$/', $item)) {
            $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

$md_files = scan_md_files($source_dir);
echo "找到 " . count($md_files) . " 个MD文件\n";

$import_count = 0;
$update_count = 0;

foreach ($md_files as $filepath) {
    $title = get_title_from_filename($filepath);
    $category_name = get_category_by_filename($filepath);
    $cat_id = isset($category_ids[$category_name]) ? $category_ids[$category_name] : $category_ids['知识库'];

    $content = file_get_contents($filepath);
    if ($content === false) {
        echo "  警告：无法读取文件 {$filepath}\n";
        continue;
    }

    $html_content = md_to_html($content);

    // 检查同名文章是否存在
    $existing = get_posts(array(
        'post_type'      => 'post',
        'title'          => $title,
        'posts_per_page' => 1,
        'post_status'    => 'any',
    ));

    if (!empty($existing)) {
        // 更新已有文章
        $post_id = $existing[0]->ID;
        wp_update_post(array(
            'ID'           => $post_id,
            'post_content' => $html_content,
        ));
        wp_set_post_categories($post_id, array((int) $cat_id));
        echo "  更新：{$title} (ID: {$post_id}) -> {$category_name}\n";
        $update_count++;
    } else {
        // 创建新文章
        $post_id = wp_insert_post(array(
            'post_title'    => $title,
            'post_content'  => $html_content,
            'post_status'   => 'publish',
            'post_type'     => 'post',
            'post_category' => array((int) $cat_id),
        ));
        if (is_wp_error($post_id)) {
            echo "  错误：创建文章 '{$title}' 失败：" . $post_id->get_error_message() . "\n";
            continue;
        }
        echo "  创建：{$title} (ID: {$post_id}) -> {$category_name}\n";
        $import_count++;
    }
}

echo "导入完成：新建 {$import_count} 篇，更新 {$update_count} 篇\n\n";

// ============================================================
// Step 5: 扫描Excel文件（用户已预上传到 uploads/tables/）
// ============================================================
echo "=== Step 5: 扫描Excel文件 ===\n";

$table_file_map = array();

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
    echo "  目录不存在，已创建：{$upload_dir}\n";
    echo "  请将Excel文件上传到该目录后重新运行本脚本\n";
} else {
    $xls_items = scandir($upload_dir);
    $found_count = 0;
    foreach ($xls_items as $item) {
        if (preg_match('/\.xlsx$/', $item)) {
            // 文件名格式：01_表格名称.xlsx
            $name = preg_replace('/^\d+_/', '', $item);
            $name = preg_replace('/\.xlsx$/', '', $name);
            $table_file_map[$name] = '/wp-content/uploads/tables/' . $item;
            echo "  找到：{$item} -> {$name}\n";
            $found_count++;
        }
    }
    echo "  共找到 {$found_count} 个Excel文件\n";
}

echo "\n";

// ============================================================
// Step 6: 创建表格中心页面模板 + WordPress页面
// ============================================================
echo "=== Step 6: 创建表格中心页面模板 ===\n";

// 表格分类映射（硬编码）
$table_categories = array(
    '综合执行与推进' => array(
        '文件更新记录', '制度上线签收与抽测记录表', '关键节点漏执行升级表',
        '整改销项复查表', '督导复查验收表', '总经理周推进验收表',
        '门店周执行红黄绿看板', '风险会员升级台账', '阶段推进与冲刺复盘表',
    ),
    '门店运营、安全与合同' => array(
        '开店检查表', '关店检查表', '课前准备检查表', '日清洁记录表',
        '周大扫除记录表', '安全排查记录表', '安全巡检与应急物资检查表',
        '场地安全检查清单', '门店卫生与消毒执行记录表', '设备报修登记表',
        '月度盘点表', '资产与耗材综合台账', '突发事件记录表',
        '合同检查清单', '停卡申请表', '转卡申请表', '退费申请表', '退费登记表',
    ),
    '销售与会员服务' => array(
        '新客接待与转化跟踪总表', '成交交接记录表', '体测数据记录表',
        '体测报告解读记录表', '体测计划通知与执行表', '会员沟通与续费管理总表',
        '投诉处理与月度复盘表', '课后反馈模板', '课后家长反馈表',
    ),
    '教学与教练专业' => array(
        'ACE评估与教学成长档案表', '升班与学员异动评估表', '教案模板',
        '教练星级评定与异动记录表', '星级评定表', '考核评分表', '随堂听课评分表',
    ),
    '店长与督导经营管理' => array(
        '今日数据看板', '周报模板', '班次会议记录表', '巡店检查与复查验收表',
        '店长工作流与帮带跟进表', '店长开会能力自评表', '门店经营月度复盘总表',
        '督导月度考核表',
    ),
    '招聘、培训与人事' => array(
        '人员增补申请与招聘跟进表', '招聘需求表', '面试评分表',
        '培训签到表', '培训抽测与上岗验收表', '岗位说明书签署确认表',
        '试用期考核表', '工作量周管理台账', '离职交接表', '离职面谈记录表',
        '薪资明细表',
    ),
    '业绩、营销与激励' => array(
        '月度目标分解表', '周进度追踪表', '月度KDI统计表',
        '营销活动方案模板', '活动每日业绩追踪表', '活动效果复盘表',
        '业绩激励与奖惩核算总表', '成长基金综合管理台账',
    ),
    '品牌与线上运营' => array(
        '门店形象检查表', '线上平台运营周报', '品牌物料管理表',
    ),
);

// 用 $L[] 数组拼接 + implode() 生成模板文件（不使用heredoc）
$L = array();
$L[] = '<?php';
$L[] = '/*';
$L[] = 'Template Name: 表格中心';
$L[] = '*/';
$L[] = '';
$L[] = '// 隐藏WP管理栏';
$L[] = 'add_filter("show_admin_bar", "__return_false");';
$L[] = '';
$L[] = '// 表格分类映射';
$L[] = '$tc = array();';

foreach ($table_categories as $cat_name => $tables) {
    $tables_json = json_encode($tables, JSON_UNESCAPED_UNICODE);
    $L[] = '$tc["' . $cat_name . '"] = ' . $tables_json . ';';
}

$L[] = '';
$L[] = '// 表格文件映射（Excel文件路径）';
$L[] = '$tfm = array();';
foreach ($table_file_map as $tname => $tpath) {
    $L[] = '$tfm["' . $tname . '"] = "' . $tpath . '";';
}

$L[] = '';
$L[] = '?>';
$L[] = '<!DOCTYPE html>';
$L[] = '<html lang="zh-CN">';
$L[] = '<head>';
$L[] = '<meta charset="UTF-8">';
$L[] = '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
$L[] = '<title>表格中心 - 贵州动合空间体育发展</title>';
$L[] = '<?php wp_head(); ?>';
$L[] = '<style>';
$L[] = '@import url("https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@300;400;500;600;700;800;900&display=swap");';
$L[] = '* { box-sizing: border-box; margin: 0; padding: 0; }';
$L[] = 'html { scroll-behavior: smooth; margin-top: 0 !important; }';
$L[] = 'body { font-family: "Noto Sans SC", -apple-system, BlinkMacSystemFont, sans-serif; color: #1d1d1f; -webkit-font-smoothing: antialiased; background: #fafafa; line-height: 1.6; }';
$L[] = '#wpadminbar { display: none !important; }';
$L[] = '';
$L[] = '/* 导航栏 - 毛玻璃效果，与首页一致 */';
$L[] = '.site-nav { position: sticky; top: 0; z-index: 1000; background: rgba(255,255,255,0.85); backdrop-filter: saturate(180%) blur(20px); -webkit-backdrop-filter: saturate(180%) blur(20px); border-bottom: 1px solid rgba(0,0,0,0.06); padding: 0 24px; }';
$L[] = '.nav-inner { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; height: 56px; }';
$L[] = '.nav-brand { font-size: 16px; font-weight: 800; color: #1d1d1f; }';
$L[] = '.nav-menu { display: flex; list-style: none; gap: 4px; }';
$L[] = '.nav-menu li a { display: block; padding: 8px 16px; font-size: 14px; font-weight: 600; color: #1d1d1f; border-radius: 980px; transition: all 0.3s ease; text-decoration: none; }';
$L[] = '.nav-menu li a:hover { background: rgba(255,107,53,0.08); color: #FF6B35; }';
$L[] = '.nav-menu li.active a { background: #FF6B35; color: #fff; }';
$L[] = '.nav-sep { border-left: 2px solid rgba(0,0,0,0.08); margin-left: 8px; padding-left: 16px; }';
$L[] = '.nav-sep a { color: #FF6B35 !important; font-weight: 700 !important; }';
$L[] = '';
$L[] = '/* Banner - 渐变色 #FFD166 -> #06D6A0 */';
$L[] = '.page-banner { width: 100%; padding: 60px 24px; text-align: center; background: linear-gradient(135deg, #FFD166 0%, #06D6A0 100%); position: relative; overflow: hidden; }';
$L[] = '.page-banner::before { content: ""; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle at 30% 50%, rgba(255,255,255,0.15) 0%, transparent 50%); animation: bannerFloat 12s ease-in-out infinite; }';
$L[] = '@keyframes bannerFloat { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(20px, -15px); } }';
$L[] = '.page-banner .banner-inner { position: relative; z-index: 1; }';
$L[] = '.page-banner h1 { font-size: 36px; font-weight: 800; color: #fff; margin-bottom: 10px; text-shadow: 0 2px 10px rgba(0,0,0,0.1); }';
$L[] = '.page-banner p { font-size: 17px; color: rgba(255,255,255,0.9); font-weight: 400; }';
$L[] = '.page-banner .banner-stats { display: flex; justify-content: center; gap: 32px; margin-top: 20px; }';
$L[] = '.page-banner .stat { text-align: center; }';
$L[] = '.page-banner .stat-num { font-size: 28px; font-weight: 800; color: #fff; }';
$L[] = '.page-banner .stat-label { font-size: 13px; color: rgba(255,255,255,0.8); }';
$L[] = '';
$L[] = '/* 搜索框 - 带图标 */';
$L[] = '.search-wrap { max-width: 560px; margin: -32px auto 0; position: relative; z-index: 10; }';
$L[] = '.search-wrap input { width: 100%; padding: 16px 20px 16px 48px; border: none; border-radius: 16px; box-shadow: 0 8px 30px rgba(0,0,0,0.12); font-size: 15px; outline: none; font-family: inherit; background: #fff; transition: box-shadow 0.3s; }';
$L[] = '.search-wrap input:focus { box-shadow: 0 8px 30px rgba(255,107,53,0.2); }';
$L[] = '.search-wrap .search-icon { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); font-size: 18px; color: #86868b; pointer-events: none; }';
$L[] = '';
$L[] = '/* 筛选栏 - 8个分类按钮 */';
$L[] = '.filter-bar { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; padding: 28px 24px 12px; max-width: 1200px; margin: 0 auto; }';
$L[] = '.filter-btn { padding: 8px 20px; border: 2px solid rgba(0,0,0,0.06); border-radius: 980px; background: #fff; cursor: pointer; font-size: 13px; font-weight: 600; font-family: inherit; transition: all 0.3s ease; color: #555; }';
$L[] = '.filter-btn:hover { border-color: #FF6B35; color: #FF6B35; }';
$L[] = '.filter-btn.active { border-color: #FF6B35; background: #FF6B35; color: #fff; }';
$L[] = '';
$L[] = '/* 表格列表 - 按分类分组，可折叠/展开 */';
$L[] = '.tables-container { max-width: 1200px; margin: 0 auto; padding: 16px 24px 60px; }';
$L[] = '.category-section { margin-bottom: 20px; }';
$L[] = '.category-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 24px; background: #fff; border-radius: 16px; border: 2px solid rgba(0,0,0,0.04); cursor: pointer; transition: all 0.3s ease; }';
$L[] = '.category-header:hover { border-color: rgba(255,107,53,0.15); box-shadow: 0 4px 20px rgba(255,107,53,0.08); transform: translateY(-1px); }';
$L[] = '.category-header h2 { font-size: 17px; font-weight: 700; color: #1d1d1f; display: flex; align-items: center; gap: 10px; }';
$L[] = '.category-header .cat-count { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; padding: 0 8px; border-radius: 980px; background: rgba(255,107,53,0.1); color: #FF6B35; font-size: 13px; font-weight: 700; }';
$L[] = '.category-header .toggle-icon { font-size: 14px; transition: transform 0.3s; color: #aeaeb2; }';
$L[] = '.category-header.collapsed .toggle-icon { transform: rotate(-90deg); }';
$L[] = '';
$L[] = '.table-list { padding: 10px 0 0; }';
$L[] = '.table-list.hidden { display: none; }';
$L[] = '.table-item { display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; background: #fff; margin-bottom: 8px; border-radius: 14px; border: 2px solid rgba(0,0,0,0.04); transition: all 0.3s ease; text-decoration: none; color: #1d1d1f; }';
$L[] = '.table-item:hover { border-color: rgba(255,107,53,0.12); box-shadow: 0 8px 24px rgba(0,0,0,0.06); transform: translateY(-2px); }';
$L[] = '.table-item .info h3 { font-size: 15px; font-weight: 600; color: #1d1d1f; margin-bottom: 4px; }';
$L[] = '.table-item .info .source { font-size: 12px; color: #aeaeb2; }';
$L[] = '.table-item .no-file { font-size: 12px; color: #ccc; font-style: italic; }';
$L[] = '.download-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 22px; background: #FF6B35; color: #fff; text-decoration: none; border-radius: 980px; font-size: 13px; font-weight: 700; transition: all 0.3s ease; white-space: nowrap; box-shadow: 0 2px 8px rgba(255,107,53,0.2); }';
$L[] = '.download-btn:hover { background: #E55A25; transform: scale(1.03); box-shadow: 0 4px 16px rgba(255,107,53,0.3); }';
$L[] = '.no-upload { display: inline-flex; align-items: center; padding: 10px 22px; background: #f0f0f0; color: #bbb; border-radius: 980px; font-size: 13px; font-weight: 600; white-space: nowrap; }';
$L[] = '';
$L[] = '.no-results { text-align: center; padding: 80px 20px; color: #aeaeb2; font-size: 16px; display: none; }';
$L[] = '.no-results .no-results-icon { font-size: 48px; margin-bottom: 16px; display: block; }';
$L[] = '';
$L[] = '/* 页脚 */';
$L[] = '.site-footer { background: #1a1a2e; color: rgba(255,255,255,0.6); padding: 32px 24px; text-align: center; font-size: 13px; margin-top: 40px; }';
$L[] = '.site-footer a { color: rgba(255,255,255,0.8); text-decoration: none; }';
$L[] = '.site-footer a:hover { color: #fff; }';
$L[] = '';
$L[] = '/* 响应式 */';
$L[] = '@media (max-width: 768px) {';
$L[] = '  .nav-menu { flex-wrap: wrap; gap: 4px; }';
$L[] = '  .nav-menu li a { padding: 6px 12px; font-size: 13px; }';
$L[] = '  .page-banner h1 { font-size: 28px; }';
$L[] = '  .page-banner .banner-stats { gap: 20px; }';
$L[] = '  .page-banner .stat-num { font-size: 22px; }';
$L[] = '  .filter-btn { font-size: 12px; padding: 6px 14px; }';
$L[] = '  .table-item { flex-direction: column; align-items: flex-start; gap: 12px; }';
$L[] = '  .download-btn, .no-upload { width: 100%; justify-content: center; }';
$L[] = '}';
$L[] = '</style>';
$L[] = '</head>';
$L[] = '<body>';

// 导航栏 - 7项 + 表格中心，与首页一致
$L[] = '<nav class="site-nav">';
$L[] = '  <div class="nav-inner">';
$L[] = '    <div class="nav-brand">贵州动合空间体育发展</div>';
$L[] = '    <ul class="nav-menu">';
$L[] = '      <li><a href="/">首页</a></li>';
$L[] = '      <li><a href="/制度标准/">制度标准</a></li>';
$L[] = '      <li><a href="/知识库/">知识库</a></li>';
$L[] = '      <li><a href="/新闻公告/">新闻公告</a></li>';
$L[] = '      <li><a href="/培训资料库/">培训资料库</a></li>';
$L[] = '      <li><a href="/素材中心/">素材中心</a></li>';
$L[] = '      <li><a href="/新员工学习/">新员工学习</a></li>';
$L[] = '      <li class="nav-sep active"><a href="/表格中心/">表格中心</a></li>';
$L[] = '    </ul>';
$L[] = '  </div>';
$L[] = '</nav>';

// Banner - 渐变色 #FFD166 -> #06D6A0
$L[] = '<section class="page-banner">';
$L[] = '  <div class="banner-inner">';
$L[] = '    <h1>表格中心</h1>';
$L[] = '    <p>全部管理表单与模板，一键搜索、一键下载</p>';
$L[] = '    <div class="banner-stats">';
$L[] = '      <div class="stat"><div class="stat-num" id="totalCount">0</div><div class="stat-label">个表格</div></div>';
$L[] = '      <div class="stat"><div class="stat-num">8</div><div class="stat-label">个分类</div></div>';
$L[] = '      <div class="stat"><div class="stat-num">&#x1F4E5;</div><div class="stat-label">一键下载</div></div>';
$L[] = '    </div>';
$L[] = '  </div>';
$L[] = '</section>';

// 搜索框 - 带图标
$L[] = '<div class="search-wrap">';
$L[] = '  <span class="search-icon">&#x1F50D;</span>';
$L[] = '  <input type="text" id="searchInput" placeholder="搜索表格名称，如：考勤、招聘、巡店...">';
$L[] = '</div>';

// 分类筛选按钮
$L[] = '<div class="filter-bar">';
$L[] = '  <button class="filter-btn active" data-filter="all">全部</button>';
$cat_names = array_keys($table_categories);
foreach ($cat_names as $cn) {
    $L[] = '  <button class="filter-btn" data-filter="' . $cn . '">' . $cn . '</button>';
}
$L[] = '</div>';

// 表格列表容器 - 按分类分组
$L[] = '<div class="tables-container" id="tableContainer">';

foreach ($table_categories as $cat_name => $tables) {
    $L[] = '  <div class="category-section" data-category="' . $cat_name . '">';
    $L[] = '    <div class="category-header" onclick="toggleCategory(this)">';
    $L[] = '      <h2>' . $cat_name . ' <span class="cat-count">' . count($tables) . '</span></h2>';
    $L[] = '      <span class="toggle-icon">&#9660;</span>';
    $L[] = '    </div>';
    $L[] = '    <div class="table-list">';

    foreach ($tables as $tbl) {
        $L[] = '      <div class="table-item" data-name="' . $tbl . '">';
        $L[] = '        <div class="info">';
        $L[] = '          <h3>' . $tbl . '</h3>';
        if (isset($table_file_map[$tbl])) {
            $L[] = '          <span class="source">文件：' . basename($table_file_map[$tbl]) . '</span>';
        } else {
            $L[] = '          <span class="source no-file">暂无对应文件</span>';
        }
        $L[] = '        </div>';
        if (isset($table_file_map[$tbl])) {
            $L[] = '        <a class="download-btn" href="' . $table_file_map[$tbl] . '" download>下载</a>';
        } else {
            $L[] = '        <span class="no-upload">暂未上传</span>';
        }
        $L[] = '      </div>';
    }

    $L[] = '    </div>';
    $L[] = '  </div>';
}

$L[] = '  <div class="no-results" id="noResults">';
$L[] = '    <span class="no-results-icon">&#x1F50D;</span>';
$L[] = '    没有找到匹配的表格';
$L[] = '  </div>';
$L[] = '</div>';

// 页脚
$L[] = '<footer class="site-footer">';
$L[] = '  <p>&copy; 2025 贵州动合空间体育发展有限公司</p>';
$L[] = '</footer>';

// JavaScript - 折叠/展开、筛选、搜索
$L[] = '<script>';
$L[] = '// 统计总表格数';
$L[] = '(function() {';
$L[] = '  var total = document.querySelectorAll(".table-item").length;';
$L[] = '  var el = document.getElementById("totalCount");';
$L[] = '  if (el) el.textContent = total;';
$L[] = '})();';
$L[] = '';
$L[] = '// 折叠/展开分类';
$L[] = 'function toggleCategory(header) {';
$L[] = '  var list = header.nextElementSibling;';
$L[] = '  list.classList.toggle("hidden");';
$L[] = '  header.classList.toggle("collapsed");';
$L[] = '}';
$L[] = '';
$L[] = '// 分类筛选';
$L[] = 'document.querySelectorAll(".filter-btn").forEach(function(btn) {';
$L[] = '  btn.addEventListener("click", function() {';
$L[] = '    document.querySelectorAll(".filter-btn").forEach(function(b) { b.classList.remove("active"); });';
$L[] = '    this.classList.add("active");';
$L[] = '    var filter = this.getAttribute("data-filter");';
$L[] = '    document.querySelectorAll(".category-section").forEach(function(sec) {';
$L[] = '      if (filter === "all" || sec.getAttribute("data-category") === filter) {';
$L[] = '        sec.style.display = "";';
$L[] = '      } else {';
$L[] = '        sec.style.display = "none";';
$L[] = '      }';
$L[] = '    });';
$L[] = '  });';
$L[] = '});';
$L[] = '';
$L[] = '// 搜索功能';
$L[] = 'document.getElementById("searchInput").addEventListener("input", function() {';
$L[] = '  var keyword = this.value.trim().toLowerCase();';
$L[] = '  var hasResult = false;';
$L[] = '  document.querySelectorAll(".table-item").forEach(function(item) {';
$L[] = '    var name = item.getAttribute("data-name").toLowerCase();';
$L[] = '    if (keyword === "" || name.indexOf(keyword) !== -1) {';
$L[] = '      item.style.display = "";';
$L[] = '      hasResult = true;';
$L[] = '    } else {';
$L[] = '      item.style.display = "none";';
$L[] = '    }';
$L[] = '  });';
$L[] = '  document.querySelectorAll(".category-section").forEach(function(sec) {';
$L[] = '    var visibleItems = sec.querySelectorAll(".table-item:not([style*=\'display: none\'])");';
$L[] = '    sec.style.display = visibleItems.length > 0 ? "" : "none";';
$L[] = '  });';
$L[] = '  document.getElementById("noResults").style.display = hasResult || keyword === "" ? "none" : "block";';
$L[] = '});';
$L[] = '</script>';

$L[] = '<?php wp_footer(); ?>';
$L[] = '</body>';
$L[] = '</html>';

$template_content = implode("\n", $L);

$template_path = $child_theme . 'page-tables.php';
if (!is_dir($child_theme)) {
    mkdir($child_theme, 0755, true);
    echo "  创建子主题目录：{$child_theme}\n";
}
file_put_contents($template_path, $template_content);
echo "  模板文件已创建：{$template_path}\n";

// 创建表格中心WordPress页面
echo "=== Step 6: 创建表格中心WordPress页面 ===\n";

$existing_page = get_page_by_path('表格中心');

if ($existing_page) {
    wp_update_post(array(
        'ID'         => $existing_page->ID,
        'post_title' => '表格中心',
        'post_status' => 'publish',
    ));
    update_post_meta($existing_page->ID, '_wp_page_template', 'page-tables.php');
    echo "  页面已更新：表格中心 (ID: {$existing_page->ID})\n";
} else {
    $page_id = wp_insert_post(array(
        'post_title'  => '表格中心',
        'post_name'   => '表格中心',
        'post_status' => 'publish',
        'post_type'   => 'page',
    ));
    if (is_wp_error($page_id)) {
        echo "  错误：创建页面失败：" . $page_id->get_error_message() . "\n";
    } else {
        update_post_meta($page_id, '_wp_page_template', 'page-tables.php');
        echo "  页面已创建：表格中心 (ID: {$page_id})\n";
    }
}

echo "\n";

// ============================================================
// Step 7: 清除缓存
// ============================================================
echo "=== Step 7: 清除缓存 ===\n";

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "  OPcache 已清除\n";
} else {
    echo "  OPcache 不可用，跳过\n";
}

if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
    echo "  WordPress 对象缓存已清除\n";
}

echo "\n";
echo "========================================\n";
echo "全部步骤执行完成！\n";
echo "========================================\n";
