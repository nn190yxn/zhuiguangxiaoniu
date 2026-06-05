<?php
/**
 * Astra Child Theme - 动合空间
 */

function astra_child_internal_home_url(): string {
    return home_url('/internal.html#workspace');
}

function astra_child_internal_login_url(string $redirect = ''): string {
    $target = $redirect !== '' ? $redirect : astra_child_internal_home_url();
    return wp_login_url($target);
}

function astra_child_staff_capabilities_map(): array {
    return array(
        'zgxn_staff' => array(
            'read' => true,
            'zgxn_access_internal' => true,
        ),
        'zgxn_store_manager' => array(
            'read' => true,
            'zgxn_access_internal' => true,
            'zgxn_manage_store_team' => true,
        ),
    );
}

function astra_child_is_protected_post(): bool {
    if (!is_single()) {
        return false;
    }

    return has_category(array('知识库', '制度标准', '新闻公告', '培训资料库', '素材中心', '新员工学习'));
}

function astra_child_register_staff_roles() {
    $roles = array(
        'zgxn_staff' => '员工',
        'zgxn_store_manager' => '店长',
    );

    foreach ($roles as $role_key => $role_label) {
        $capabilities = astra_child_staff_capabilities_map()[$role_key];
        $role = get_role($role_key);

        if (!$role) {
            add_role($role_key, $role_label, $capabilities);
            continue;
        }

        foreach ($capabilities as $capability => $grant) {
            if ($grant) {
                $role->add_cap($capability);
            } else {
                $role->remove_cap($capability);
            }
        }
    }
}
add_action('init', 'astra_child_register_staff_roles');

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

function astra_child_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (!$user instanceof WP_User) {
        return $redirect_to;
    }

    if (user_can($user, 'manage_options')) {
        return admin_url();
    }

    return astra_child_internal_home_url();
}
add_filter('login_redirect', 'astra_child_login_redirect', 10, 3);

function astra_child_limit_admin_access() {
    if (!is_user_logged_in() || wp_doing_ajax()) {
        return;
    }

    if (current_user_can('manage_options')) {
        return;
    }

    wp_safe_redirect(astra_child_internal_home_url());
    exit;
}
add_action('admin_init', 'astra_child_limit_admin_access');

function astra_child_control_admin_bar($show) {
    return current_user_can('manage_options') ? $show : false;
}
add_filter('show_admin_bar', 'astra_child_control_admin_bar');

add_filter('pre_option_users_can_register', '__return_zero');
add_filter('pre_option_default_role', static function () {
    return 'zgxn_staff';
});

function astra_child_editable_roles($roles) {
    if (!current_user_can('manage_options')) {
        return $roles;
    }

    $allowed_roles = array(
        'administrator',
        'zgxn_store_manager',
        'zgxn_staff',
    );

    return array_intersect_key($roles, array_flip($allowed_roles));
}
add_filter('editable_roles', 'astra_child_editable_roles');

function astra_child_block_public_registration() {
    if (!isset($_GET['action'])) {
        return;
    }

    $action = wp_unslash((string) $_GET['action']);
    if ($action !== 'register') {
        return;
    }

    wp_safe_redirect(astra_child_internal_login_url());
    exit;
}
add_action('login_init', 'astra_child_block_public_registration');

function astra_child_user_admin_notice() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) {
        return;
    }

    if (!in_array($screen->id, array('user', 'profile', 'users', 'user-new'), true)) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $message = '<strong>账号录入规则：</strong>用户名建议直接使用手机号；可选角色只保留管理员、店长、员工；默认新账号为员工；密码至少 6 位，可手动设置，后续允许修改。';

    if ($screen->id === 'user-new') {
        $message .= ' 密码位置在页面中部的“账号管理”区域，点“显示密码”后可直接改成你要的密码。';
    }

    echo '<div class="notice notice-info"><p>' . wp_kses_post($message) . '</p></div>';
}
add_action('admin_notices', 'astra_child_user_admin_notice');

function astra_child_validate_password_length(WP_Error $errors) {
    $password = isset($_POST['pass1']) ? (string) wp_unslash($_POST['pass1']) : '';
    if ($password === '') {
        return;
    }

    if (function_exists('mb_strlen')) {
        $length = mb_strlen($password);
    } else {
        $length = strlen($password);
    }

    if ($length < 6) {
        $errors->add('astra_child_password_too_short', '密码至少需要 6 位。');
    }
}

function astra_child_validate_profile_password($errors, $update = null, $user = null) {
    if (!$errors instanceof WP_Error) {
        return;
    }

    astra_child_validate_password_length($errors);
}
add_action('user_profile_update_errors', 'astra_child_validate_profile_password', 10, 3);

function astra_child_validate_reset_password($errors) {
    if (!$errors instanceof WP_Error) {
        return;
    }

    astra_child_validate_password_length($errors);
}
add_action('validate_password_reset', 'astra_child_validate_reset_password');

function astra_child_password_hint($hint) {
    return '密码至少 6 位，可由管理员手动设置，后续用户也可以修改。';
}
add_filter('password_hint', 'astra_child_password_hint');

function astra_child_require_internal_login() {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    if (is_user_logged_in()) {
        return;
    }

    $needs_login = is_page_template(array('page-category.php', 'page-tables.php', 'page-zhidu-biaozhun.php')) || astra_child_is_protected_post();
    if (!$needs_login) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
    wp_safe_redirect(astra_child_internal_login_url(home_url($request_uri)));
    exit;
}
add_action('template_redirect', 'astra_child_require_internal_login');

function astra_child_ajax_auth_status() {
    $redirect = isset($_REQUEST['redirect']) ? wp_unslash((string) $_REQUEST['redirect']) : '/';
    if ($redirect === '' || strpos($redirect, 'http://') === 0 || strpos($redirect, 'https://') === 0) {
        $redirect = '/';
    }

    $redirect_url = home_url($redirect);
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        wp_send_json(array(
            'logged_in' => true,
            'display_name' => $user->display_name,
            'redirect' => $redirect_url,
        ));
    }

    wp_send_json(array(
        'logged_in' => false,
        'login_url' => astra_child_internal_login_url($redirect_url),
    ));
}
add_action('wp_ajax_astra_child_auth_status', 'astra_child_ajax_auth_status');
add_action('wp_ajax_nopriv_astra_child_auth_status', 'astra_child_ajax_auth_status');

function astra_child_remove_hello_world() {
    $hello = get_page_by_title('Hello World!', OBJECT, 'post');
    if ($hello) {
        wp_delete_post($hello->ID, true);
    }
}
add_action('init', 'astra_child_remove_hello_world');
