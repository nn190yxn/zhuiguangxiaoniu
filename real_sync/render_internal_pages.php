<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
declare(strict_types=1);

require __DIR__ . '/wp-load.php';

function render_page_template_to_file(int $page_id, string $template_path, string $output_path): void
{
    $_SERVER['HTTP_HOST'] = '122.51.223.46';
    $_SERVER['SERVER_NAME'] = '122.51.223.46';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = get_permalink($page_id) ?: '/';

    global $post;
    $post = get_post($page_id);
    if (!$post instanceof WP_Post) {
        throw new RuntimeException("Page not found: {$page_id}");
    }

    setup_postdata($post);
    ob_start();
    include $template_path;
    $html = ob_get_clean();
    wp_reset_postdata();

    if (!is_string($html) || $html === '') {
        throw new RuntimeException("Empty render for page {$page_id}");
    }

    if (strpos($html, '/internal-auth.js') === false) {
        $html = str_replace('</head>', "\n    <script src=\"/internal-auth.js\" defer></script>\n  </head>", $html);
    }

    if (file_put_contents($output_path, $html) === false) {
        throw new RuntimeException("Write failed: {$output_path}");
    }
}

render_page_template_to_file(83, __DIR__ . '/wp-content/themes/astra-child/page-tables.php', __DIR__ . '/表格中心/index.html');
render_page_template_to_file(15, __DIR__ . '/wp-content/themes/astra-child/page-zhidu-biaozhun.php', __DIR__ . '/制度标准/index.html');
render_page_template_to_file(16, __DIR__ . '/wp-content/themes/astra-child/page-category.php', __DIR__ . '/知识库/index.html');
render_page_template_to_file(20, __DIR__ . '/wp-content/themes/astra-child/page-category.php', __DIR__ . '/新员工学习/index.html');
