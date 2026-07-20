<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$administrator = get_role('administrator');
if ($administrator) {
    foreach ([
        'pov_access_portal',
        'pov_view_requests',
        'pov_manage_requests',
        'pov_send_responses',
        'pov_view_tours',
        'pov_manage_tours',
        'pov_view_calendar',
        'pov_manage_calendar',
        'pov_view_statistics',
        'pov_export_calendar',
        'pov_manage_privacy',
    ] as $capability) {
        $administrator->remove_cap($capability);
    }
}
remove_role('pov_van_team');
remove_role('pov_van_reader');

$cleanup = get_option('pov_cleanup_on_uninstall', '0');
$portalPageId = (int) get_option('pov_portal_page_id', 0);
if ($portalPageId > 0 && get_post_meta($portalPageId, '_pov_operations_portal', true) === '1') {
    if ($cleanup === '1') {
        wp_delete_post($portalPageId, true);
    } else {
        wp_trash_post($portalPageId);
    }
}
delete_option('pov_portal_page_id');
delete_option('pov_portal_setup_version');

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
