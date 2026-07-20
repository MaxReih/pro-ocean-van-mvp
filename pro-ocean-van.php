<?php
/**
 * Plugin Name: Pro Ocean Van Planner
 * Description: Buchungs-, Kalender- und Routing-MVP für den Pro Ocean Ocean Van.
 * Version: 0.5.1
 * Author: Pro Ocean
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Text Domain: pro-ocean-van
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('POV_VERSION', '0.5.1');
define('POV_PLUGIN_FILE', __FILE__);
define('POV_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POV_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'ProOceanVan\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = POV_PLUGIN_DIR . 'src/' . $relative . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, [ProOceanVan\Activation::class, 'activate']);
register_deactivation_hook(__FILE__, [ProOceanVan\Activation::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    ProOceanVan\Plugin::instance()->boot();
});
