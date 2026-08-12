<?php

declare(strict_types=1);

namespace ProOceanVan\Security;

use WP_Error;
use WP_Post;

final class FrontendAccess
{
    public const OPTION = 'pov_frontend_password_enabled';
    public const PASSWORD = 'Ocean';
    private const COOKIE = 'pov_frontend_access';
    private const NONCE_ACTION = 'pov_frontend_access';
    private const ACCESS_DURATION = 12 * HOUR_IN_SECONDS;

    public function register(): void
    {
        add_action('template_redirect', [$this, 'handleBookingPage'], 0);
    }

    public function handleBookingPage(): void
    {
        if (! self::enabled() || (! $this->containsBookingShortcode() && ! $this->isUnlockRequest())) {
            return;
        }

        self::disableCaching();
        if (! $this->isUnlockRequest()) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_pov_access_nonce'] ?? '')));
        $password = (string) wp_unslash($_POST['pov_frontend_password'] ?? '');
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)
            || ! hash_equals(self::PASSWORD, $password)) {
            wp_safe_redirect(add_query_arg('pov_access_error', '1', $this->currentUrl()));
            exit;
        }

        $expires = time() + self::ACCESS_DURATION;
        $token = $expires . '.' . self::signature($expires);
        setcookie(self::COOKIE, $token, [
            'expires' => $expires,
            'path' => $this->cookiePath(),
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $token;

        wp_safe_redirect(remove_query_arg('pov_access_error', $this->currentUrl()));
        exit;
    }

    public static function enabled(): bool
    {
        return (string) get_option(self::OPTION, '0') === '1';
    }

    public static function isGranted(): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        [$expires, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($expires === '' || ! ctype_digit($expires) || (int) $expires < time() || $signature === '') {
            return false;
        }

        return hash_equals(self::signature((int) $expires), $signature);
    }

    public static function authorizeRest(): bool|WP_Error
    {
        if (self::isGranted()) {
            return true;
        }

        return new WP_Error(
            'pov_frontend_locked',
            'Bitte zuerst die Buchungsseite mit dem Passwort öffnen.',
            ['status' => 401]
        );
    }

    public static function disableCaching(): void
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (! headers_sent()) {
            nocache_headers();
        }
    }

    public static function renderGate(): string
    {
        $hasError = sanitize_key((string) wp_unslash($_GET['pov_access_error'] ?? '')) === '1';
        ob_start();
        include POV_PLUGIN_DIR . 'templates/frontend-access.php';
        return (string) ob_get_clean();
    }

    private static function signature(int $expires): string
    {
        return hash_hmac('sha256', $expires . '|' . home_url('/'), wp_salt('auth'));
    }

    private function isUnlockRequest(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && sanitize_key((string) ($_POST['pov_frontend_access_action'] ?? '')) === 'unlock';
    }

    private function containsBookingShortcode(): bool
    {
        $post = get_queried_object();
        return $post instanceof WP_Post
            && has_shortcode((string) $post->post_content, 'pro_ocean_van_booking');
    }

    private function currentUrl(): string
    {
        $requestUri = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        $scheme = (string) wp_parse_url(home_url('/'), PHP_URL_SCHEME);
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $port = wp_parse_url(home_url('/'), PHP_URL_PORT);
        $origin = $scheme . '://' . $host . ($port ? ':' . (int) $port : '');
        return $origin . '/' . ltrim($requestUri, '/');
    }

    private function cookiePath(): string
    {
        $path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        return $path !== '' ? trailingslashit($path) : '/';
    }
}
