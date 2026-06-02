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

add_filter('xmlrpc_enabled', '__return_false');
add_filter('pre_update_option_default_ping_status', function () {
    return 'closed';
});
add_filter('pre_option_default_ping_status', function () {
    return 'closed';
});

function astra_child_disable_pingback_header($headers) {
    if (isset($headers['X-Pingback'])) {
        unset($headers['X-Pingback']);
    }
    return $headers;
}
add_filter('wp_headers', 'astra_child_disable_pingback_header');

function astra_child_remove_pingback_link() {
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'wp_shortlink_wp_head');
    remove_action('wp_head', 'rest_output_link_wp_head');
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    remove_action('wp_head', 'feed_links_extra', 3);
}
add_action('init', 'astra_child_remove_pingback_link');

function astra_child_block_trackbacks() {
    if (isset($_GET['tb']) || basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'wp-trackback.php') {
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Trackbacks disabled');
    }
}
add_action('template_redirect', 'astra_child_block_trackbacks', 0);
