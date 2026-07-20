<?php

declare(strict_types=1);

namespace ProOceanVan\Portal;

use ProOceanVan\Admin\Menu;
use ProOceanVan\Security\Capabilities;
use WP_Post;
use WP_Query;
use WP_User;

final class OperationsPortal
{
    public const SLUG = 'van-operations';
    public const PAGE_OPTION = 'pov_portal_page_id';
    private const SETUP_VERSION = '1';

    private const VIEW_TO_PAGE = [
        'requests' => 'pov-requests',
        'routes' => 'pov-routes',
        'calendar' => 'pov-calendar',
        'statistics' => 'pov-statistics',
    ];

    public function register(): void
    {
        add_action('init', [self::class, 'ensurePage'], 5);
        add_action('template_redirect', [$this, 'protect'], 0);
        add_filter('template_include', [$this, 'template'], 99);
        add_action('wp_enqueue_scripts', [$this, 'assets'], 100);
        add_action('admin_init', [$this, 'redirectAdmin']);
        add_filter('login_redirect', [$this, 'loginRedirect'], 10, 3);
        add_filter('show_admin_bar', [$this, 'showAdminBar']);
        add_filter('wp_robots', [$this, 'robots']);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'excludeFromSitemap'], 10, 2);
        add_filter('wp_list_pages_excludes', [$this, 'excludeFromPageLists']);
        add_filter('wp_nav_menu_objects', [$this, 'excludeFromMenus']);
        add_action('pre_get_posts', [$this, 'excludeFromSearch']);
        add_action('login_enqueue_scripts', [$this, 'loginBranding']);
        add_filter('login_headerurl', static fn (): string => home_url('/'));
    }

    public static function ensurePage(): int
    {
        $pageId = (int) get_option(self::PAGE_OPTION, 0);
        $page = $pageId > 0 ? get_post($pageId) : null;
        $owned = $page instanceof WP_Post
            && $page->post_type === 'page'
            && $page->post_status !== 'trash'
            && get_post_meta($pageId, '_pov_operations_portal', true) === '1';

        if (! $owned) {
            $page = null;
            $pageId = 0;
        }

        if ($pageId <= 0) {
            $created = wp_insert_post([
                'post_title' => 'Van Operations',
                'post_name' => self::SLUG,
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_content' => '',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ], true);
            $pageId = is_wp_error($created) ? 0 : (int) $created;
            if ($pageId > 0) {
                update_post_meta($pageId, '_pov_operations_portal', '1');
            }
        } elseif ($page instanceof WP_Post && $page->post_status !== 'publish') {
            wp_update_post(['ID' => $pageId, 'post_status' => 'publish']);
        }

        if ($pageId > 0) {
            update_option(self::PAGE_OPTION, $pageId, false);
            update_option('pov_portal_setup_version', self::SETUP_VERSION, false);
        }

        return $pageId;
    }

    public static function url(string $page = 'pov-requests', array $args = []): string
    {
        $pageId = (int) get_option(self::PAGE_OPTION, 0);
        $base = $pageId > 0 ? get_permalink($pageId) : home_url('/' . self::SLUG . '/');
        if (! is_string($base) || $base === '') {
            $base = home_url('/' . self::SLUG . '/');
        }

        $view = array_search($page, self::VIEW_TO_PAGE, true);
        if ($view !== false && $view !== 'requests') {
            $args = array_merge(['view' => $view], $args);
        }

        return $args ? add_query_arg($args, $base) : $base;
    }

    public static function pageForView(string $view): string
    {
        return self::VIEW_TO_PAGE[$view] ?? 'pov-requests';
    }

    public static function landingUrl(?WP_User $user = null): string
    {
        $page = self::firstAllowedPage($user);
        return $page !== null ? self::url($page) : self::url();
    }

    public static function viewForPage(string $page): string
    {
        $view = array_search($page, self::VIEW_TO_PAGE, true);
        return is_string($view) ? $view : 'requests';
    }

    public static function isPortalRequest(): bool
    {
        $pageId = (int) get_option(self::PAGE_OPTION, 0);
        return $pageId > 0
            && get_post_meta($pageId, '_pov_operations_portal', true) === '1'
            && is_page($pageId);
    }

    public function protect(): void
    {
        if (! self::isPortalRequest()) {
            return;
        }

        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
        header('Referrer-Policy: same-origin', true);
        header('X-Frame-Options: SAMEORIGIN', true);
        header('Vary: Cookie', false);

        if (! is_user_logged_in()) {
            auth_redirect();
        }
        if (! current_user_can(Capabilities::ACCESS_PORTAL)) {
            status_header(403);
            wp_die('Du hast keinen Zugriff auf Van Operations.', 'Zugriff verweigert', ['response' => 403]);
        }

        if (self::firstAllowedPage() === null) {
            status_header(403);
            wp_die('Für dein Konto ist noch kein Portalbereich freigeschaltet.', 'Zugriff verweigert', ['response' => 403]);
        }

        $page = self::pageForView(sanitize_key((string) ($_GET['view'] ?? 'requests')));
        if (! current_user_can(Capabilities::viewForPage($page)) && ! isset($_GET['view'])) {
            wp_safe_redirect(self::landingUrl());
            exit;
        }
        if (! current_user_can(Capabilities::viewForPage($page))) {
            status_header(403);
            wp_die('Du hast keinen Zugriff auf diesen Bereich.', 'Zugriff verweigert', ['response' => 403]);
        }
    }

    public function template(string $template): string
    {
        return self::isPortalRequest() ? POV_PLUGIN_DIR . 'templates/operations-portal.php' : $template;
    }

    public function assets(): void
    {
        if (! self::isPortalRequest()) {
            return;
        }
        wp_enqueue_style('pov-admin', POV_PLUGIN_URL . 'assets/dist/admin.css', [], POV_VERSION);
        wp_enqueue_style('pov-portal', POV_PLUGIN_URL . 'assets/dist/portal.css', ['pov-admin'], POV_VERSION);
        wp_enqueue_script('pov-admin', POV_PLUGIN_URL . 'assets/dist/admin.js', [], POV_VERSION, true);
    }

    public function render(): void
    {
        $view = sanitize_key((string) ($_GET['view'] ?? 'requests'));
        $page = self::pageForView($view);
        $menu = new Menu(true);
        match ($page) {
            'pov-routes' => $menu->routes(),
            'pov-calendar' => $menu->calendar(),
            'pov-statistics' => $menu->statistics(),
            default => $menu->requests(),
        };
    }

    public function redirectAdmin(): void
    {
        if (! is_user_logged_in() || wp_doing_ajax()) {
            return;
        }

        $script = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
        if (in_array($script, ['admin-post.php', 'admin-ajax.php', 'profile.php'], true)) {
            return;
        }

        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if (in_array($page, array_values(self::VIEW_TO_PAGE), true) && current_user_can(Capabilities::ACCESS_PORTAL)) {
            $args = [];
            foreach (['request_id', 'pov_month', 'period', 'edit_address', 'pov_notice'] as $key) {
                if (isset($_GET[$key])) {
                    $args[$key] = sanitize_text_field((string) $_GET[$key]);
                }
            }
            wp_safe_redirect(self::url($page, $args));
            exit;
        }

        if (! current_user_can('manage_options') && current_user_can(Capabilities::ACCESS_PORTAL)) {
            if (self::firstAllowedPage() === null) {
                status_header(403);
                wp_die('Für dein Konto ist noch kein Portalbereich freigeschaltet.', 'Zugriff verweigert', ['response' => 403]);
            }
            wp_safe_redirect(self::landingUrl());
            exit;
        }
    }

    public function loginRedirect(string $redirectTo, string $requested, mixed $user): string
    {
        if ($user instanceof WP_User && $user->has_cap(Capabilities::ACCESS_PORTAL) && ! $user->has_cap('manage_options')) {
            $requestedTarget = $requested !== '' ? $requested : $redirectTo;
            if (self::isPortalUrl($requestedTarget)) {
                return wp_validate_redirect($requestedTarget, self::landingUrl($user));
            }
            return self::landingUrl($user);
        }
        return $redirectTo;
    }

    public function showAdminBar(bool $show): bool
    {
        if (self::isPortalRequest()
            || (is_user_logged_in() && current_user_can(Capabilities::ACCESS_PORTAL) && ! current_user_can('manage_options'))) {
            return false;
        }
        return $show;
    }

    public function robots(array $robots): array
    {
        if (self::isPortalRequest()) {
            return ['noindex' => true, 'nofollow' => true, 'noarchive' => true, 'nosnippet' => true];
        }
        return $robots;
    }

    public function excludeFromSitemap(array $args, string $postType): array
    {
        $pageId = (int) get_option(self::PAGE_OPTION, 0);
        if ($postType === 'page' && $pageId > 0) {
            $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? []), [$pageId])));
        }
        return $args;
    }

    public function excludeFromPageLists(array $excluded): array
    {
        $pageId = (int) get_option(self::PAGE_OPTION, 0);
        return $pageId > 0 ? array_values(array_unique(array_merge($excluded, [$pageId]))) : $excluded;
    }

    public function excludeFromMenus(array $items): array
    {
        $pageId = (int) get_option(self::PAGE_OPTION, 0);
        if ($pageId <= 0) {
            return $items;
        }
        return array_values(array_filter($items, static fn (mixed $item): bool => (int) ($item->object_id ?? 0) !== $pageId));
    }

    public function excludeFromSearch(WP_Query $query): void
    {
        if (is_admin() || ! $query->is_main_query() || ! $query->is_search()) {
            return;
        }
        $pageId = (int) get_option(self::PAGE_OPTION, 0);
        if ($pageId > 0) {
            $query->set('post__not_in', array_values(array_unique(array_merge((array) $query->get('post__not_in'), [$pageId]))));
        }
    }

    public function loginBranding(): void
    {
        wp_enqueue_style('pov-login', POV_PLUGIN_URL . 'assets/dist/login.css', [], POV_VERSION);
    }

    private static function isPortalUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $portalUrl = self::url();
        $requestedHost = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $portalHost = strtolower((string) wp_parse_url($portalUrl, PHP_URL_HOST));
        if ($requestedHost !== '' && $portalHost !== '' && ! hash_equals($portalHost, $requestedHost)) {
            return false;
        }

        $requestedPath = untrailingslashit((string) wp_parse_url($url, PHP_URL_PATH));
        $portalPath = untrailingslashit((string) wp_parse_url($portalUrl, PHP_URL_PATH));
        if (! hash_equals($portalPath, $requestedPath)) {
            return false;
        }

        parse_str((string) wp_parse_url($portalUrl, PHP_URL_QUERY), $portalQuery);
        if (isset($portalQuery['page_id'])) {
            parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $requestedQuery);
            return (int) ($requestedQuery['page_id'] ?? 0) === (int) $portalQuery['page_id'];
        }

        return $requestedPath !== '';
    }

    private static function firstAllowedPage(?WP_User $user = null): ?string
    {
        foreach (['pov-requests', 'pov-routes', 'pov-calendar', 'pov-statistics'] as $page) {
            $capability = Capabilities::viewForPage($page);
            if (($user instanceof WP_User && $user->has_cap($capability)) || (! $user && current_user_can($capability))) {
                return $page;
            }
        }
        return null;
    }
}
