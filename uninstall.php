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

$attachmentOptions = [
    'pov_confirmation_attachment_ids',
    'pov_proposal_attachment_ids',
    'pov_accept_attachment_ids',
    'pov_question_attachment_ids',
    'pov_reject_attachment_ids',
];
$attachmentIds = [];
$attachmentIds = array_merge($attachmentIds, get_posts([
    'post_type' => 'attachment',
    'post_status' => 'any',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'meta_key' => '_pov_private_attachment',
    'meta_value' => '1',
    'no_found_rows' => true,
]));
foreach ($attachmentOptions as $option) {
    $decoded = json_decode((string) get_option($option, ''), true);
    if (is_array($decoded)) {
        $attachmentIds = array_merge($attachmentIds, array_map('absint', $decoded));
    }
}
$communicationsTable = $wpdb->prefix . 'pov_communications';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $communicationsTable)) === $communicationsTable) {
    foreach (($wpdb->get_col("SELECT attachment_ids FROM {$communicationsTable}") ?: []) as $encoded) {
        $decoded = json_decode((string) $encoded, true);
        if (is_array($decoded)) {
            $attachmentIds = array_merge($attachmentIds, array_map('absint', $decoded));
        }
    }
}
foreach (array_values(array_unique(array_filter($attachmentIds))) as $attachmentId) {
    wp_delete_attachment((int) $attachmentId, true);
}

require_once __DIR__ . '/src/Service/AttachmentService.php';
$privateDirectory = \ProOceanVan\Service\AttachmentService::storageDirectoryPath();
if ($privateDirectory !== '' && is_dir($privateDirectory)) {
    $resolvedDirectory = realpath($privateDirectory);
    if ($resolvedDirectory !== false) {
        foreach ((array) glob($resolvedDirectory . DIRECTORY_SEPARATOR . '*') as $file) {
            $resolvedFile = realpath($file);
            if ($resolvedFile !== false
                && is_file($resolvedFile)
                && str_starts_with(wp_normalize_path($resolvedFile), trailingslashit(wp_normalize_path($resolvedDirectory)))) {
                @unlink($resolvedFile);
            }
        }
        @rmdir($resolvedDirectory);
    }
}

$tables = [
    'pov_request_classes',
    'pov_communications',
    'pov_tour_expenses',
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

foreach (($wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('pov_') . '%'
)) ?: []) as $name) {
    delete_option((string) $name);
}
