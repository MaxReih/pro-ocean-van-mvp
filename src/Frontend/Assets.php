<?php

declare(strict_types=1);

namespace ProOceanVan\Frontend;

final class Assets
{
    private bool $shouldLoad = false;

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
        add_filter('the_posts', [$this, 'detectShortcode']);
    }

    public function detectShortcode(array $posts): array
    {
        foreach ($posts as $post) {
            if (isset($post->post_content) && has_shortcode((string) $post->post_content, 'pro_ocean_van_booking')) {
                $this->shouldLoad = true;
                break;
            }
        }
        return $posts;
    }

    public function registerAssets(): void
    {
        wp_register_style('pov-calendar', POV_PLUGIN_URL . 'assets/vendor/pov-calendar/pov-calendar.css', [], POV_VERSION);
        wp_register_script('pov-calendar', POV_PLUGIN_URL . 'assets/vendor/pov-calendar/pov-calendar.js', [], POV_VERSION, true);
        wp_register_style('pov-leaflet', POV_PLUGIN_URL . 'assets/vendor/pov-leaflet/pov-leaflet.css', [], POV_VERSION);
        wp_register_script('pov-leaflet', POV_PLUGIN_URL . 'assets/vendor/pov-leaflet/pov-leaflet.js', [], POV_VERSION, true);
        wp_register_style('pov-frontend', POV_PLUGIN_URL . 'assets/dist/frontend.css', [], POV_VERSION);
        wp_register_script('pov-frontend', POV_PLUGIN_URL . 'assets/dist/frontend.js', ['pov-calendar'], POV_VERSION, true);
        wp_localize_script('pov-frontend', 'POV_BOOKING', [
            'restUrl' => esc_url_raw(rest_url('pro-ocean-van/v1/')),
            'states' => [],
            'privacyUrl' => esc_url_raw((string) get_option('pov_privacy_page_url', '')),
            'requestTimeoutMs' => 12000,
        ]);

        if ($this->shouldLoad || is_admin()) {
            wp_enqueue_style('pov-calendar');
            wp_enqueue_style('pov-frontend');
            wp_enqueue_script('pov-calendar');
            wp_enqueue_script('pov-frontend');
        }
    }
}
