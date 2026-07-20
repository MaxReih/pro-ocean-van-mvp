<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$cleanup = get_option('pov_cleanup_on_uninstall', '0');
if ($cleanup !== '1') {
    return;
}

global $wpdb;

$tables = [
    'pov_request_classes',
    'pov_suggestions',
    'pov_appointments',
    'pov_requests',
    'pov_calendar_days',
    'pov_states',
    'pov_route_cache',
];

foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . $table) . '`');
}

foreach (array_keys(wp_load_alloptions()) as $name) {
    if (strpos($name, 'pov_') === 0) {
        delete_option($name);
    }
}
