<?php

declare(strict_types=1);

namespace ProOceanVan;

use ProOceanVan\Database\Schema;

final class Activation
{
    public static function activate(): void
    {
        self::installTables();
        self::seedStates();
        self::seedOptions();

        if (! wp_next_scheduled('pov_cleanup_route_cache')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'pov_cleanup_route_cache');
        }
    }

    public static function maybeUpgrade(): void
    {
        self::seedOptions();
        if ((string) get_option('pov_schema_version', '') === Schema::VERSION) {
            return;
        }

        self::installTables();
        self::seedStates();
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('pov_cleanup_route_cache');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'pov_cleanup_route_cache');
        }
    }

    public static function installTables(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ((new Schema())->sql() as $statement) {
            dbDelta($statement);
        }

        self::migrateSuggestionOrderColumn();

        update_option('pov_schema_version', Schema::VERSION);
    }

    private static function migrateSuggestionOrderColumn(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_suggestions';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}") ?: [];
        if (in_array('rank', $columns, true) && in_array('sort_order', $columns, true)) {
            $wpdb->query("UPDATE {$table} SET sort_order = `rank` WHERE sort_order = 0");
        }
    }

    public static function seedStates(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_states';
        $states = [
            'BW' => 'Baden-Württemberg',
            'BY' => 'Bayern',
            'BE' => 'Berlin',
            'BB' => 'Brandenburg',
            'HB' => 'Bremen',
            'HH' => 'Hamburg',
            'HE' => 'Hessen',
            'MV' => 'Mecklenburg-Vorpommern',
            'NI' => 'Niedersachsen',
            'NW' => 'Nordrhein-Westfalen',
            'RP' => 'Rheinland-Pfalz',
            'SL' => 'Saarland',
            'SN' => 'Sachsen',
            'ST' => 'Sachsen-Anhalt',
            'SH' => 'Schleswig-Holstein',
            'TH' => 'Thüringen',
        ];

        $position = 10;
        foreach ($states as $code => $name) {
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE state_code = %s", $code));
            if ($exists > 0) {
                continue;
            }
            $wpdb->insert($table, [
                'state_code' => $code,
                'state_name' => $name,
                'is_active' => $code === 'BW' ? 1 : 0,
                'sort_order' => $position,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            $position += 10;
        }
    }

    public static function seedOptions(): void
    {
        $defaults = [
            'pov_default_start_label' => 'Tübingen',
            'pov_default_start_latitude' => '',
            'pov_default_start_longitude' => '',
            'pov_kilometer_rate' => '',
            'pov_personnel_hourly_rate' => '30',
            'pov_personnel_count' => '2',
            'pov_default_visit_hours' => '6',
            'pov_average_driving_speed_kmh' => '70',
            'pov_max_public_suggestion_cost' => '',
            'pov_max_public_suggestion_distance_km' => '',
            'pov_cluster_radius_km' => '80',
            'pov_public_booking_horizon_days' => '365',
            'pov_route_cache_ttl' => (string) WEEK_IN_SECONDS,
            'pov_geocoding_cache_ttl' => (string) (30 * DAY_IN_SECONDS),
            'pov_routing_provider' => 'null',
            'pov_routing_base_url' => '',
            'pov_geocoding_provider' => 'null',
            'pov_geocoding_base_url' => '',
            'pov_heigit_api_key' => '',
            'pov_map_tile_url' => '',
            'pov_map_attribution' => '',
            'pov_email_sender_name' => 'Pro Ocean',
            'pov_email_sender_address' => get_option('admin_email'),
            'pov_team_notification_emails' => get_option('admin_email'),
            'pov_confirmation_email_subject' => 'Deine Anfrage für den Ocean Van ist eingegangen',
            'pov_confirmation_email_body' => "Hallo {Vorname},\n\ndeine Anfrage für den Ocean Van ist eingegangen.\n\nAnfrage: {Anfragekennung}\nTermin: {Termin oder Zeitraum}\nOrt: {Ort}\n\nWir prüfen die Route und melden uns mit möglichen Terminen.\n\nViele Grüße\nPro Ocean",
            'pov_proposal_email_subject' => 'Terminvorschläge für den Ocean Van',
            'pov_proposal_email_body' => "Hallo {Vorname},\n\nwir haben deine Anfrage geprüft. Diese Termine sind möglich:\n\n{Terminvorschläge}\n\nBitte antworte uns, welcher Termin für euch passt.\n\nViele Grüße\nPro Ocean",
            'pov_response_accept_template' => 'vielen Dank für eure Anfrage. Wir können den Ocean Van am ausgewählten Termin einplanen.',
            'pov_response_question_template' => 'vielen Dank für eure Anfrage. Für die weitere Planung benötigen wir noch folgende Information:',
            'pov_response_reject_template' => 'vielen Dank für eure Anfrage. Leider können wir den Ocean Van im angefragten Zeitraum nicht einplanen.',
            'pov_privacy_page_url' => '',
            'pov_cleanup_on_uninstall' => '0',
            'pov_test_profile_enabled' => '0',
            'pov_test_profile_applied' => '0',
        ];

        foreach ($defaults as $name => $value) {
            if (get_option($name, null) === null) {
                add_option($name, $value);
            }
        }
    }

    public static function cleanupRouteCache(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_route_cache';
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %s", current_time('mysql', true)));
    }

    public static function applyTestProfileIfUnconfigured(): void
    {
        if (! in_array(wp_get_environment_type(), ['local', 'development'], true)) {
            return;
        }

        if (get_option('pov_test_profile_applied', '0') === '1') {
            return;
        }

        $routingMissing = in_array((string) get_option('pov_routing_provider', 'null'), ['', 'null'], true)
            && (string) get_option('pov_routing_base_url', '') === '';
        $geocodingMissing = in_array((string) get_option('pov_geocoding_provider', 'null'), ['', 'null'], true)
            && (string) get_option('pov_geocoding_base_url', '') === '';

        if ($routingMissing && $geocodingMissing) {
            self::enableTestProfile(false);
        }
    }

    public static function enableTestProfile(bool $force = true): void
    {
        $profile = self::testProfile();
        foreach ($profile as $name => $value) {
            if (! $force && get_option($name, '') !== '' && ! in_array((string) get_option($name), ['null', '0'], true)) {
                continue;
            }
            update_option($name, $value);
        }

        update_option('pov_test_profile_enabled', '1');
        update_option('pov_test_profile_applied', '1');
    }

    public static function testProfile(): array
    {
        return [
            'pov_default_start_label' => 'Tübingen',
            'pov_default_start_latitude' => '48.5216364',
            'pov_default_start_longitude' => '9.0576448',
            'pov_kilometer_rate' => '0.85',
            'pov_personnel_hourly_rate' => '30',
            'pov_personnel_count' => '2',
            'pov_default_visit_hours' => '6',
            'pov_average_driving_speed_kmh' => '70',
            'pov_max_public_suggestion_cost' => '300',
            'pov_max_public_suggestion_distance_km' => '350',
            'pov_cluster_radius_km' => '80',
            'pov_public_booking_horizon_days' => '365',
            'pov_route_cache_ttl' => (string) WEEK_IN_SECONDS,
            'pov_geocoding_cache_ttl' => (string) (30 * DAY_IN_SECONDS),
            'pov_routing_provider' => 'osrm',
            'pov_routing_base_url' => 'https://router.project-osrm.org/',
            'pov_geocoding_provider' => 'nominatim',
            'pov_geocoding_base_url' => 'https://nominatim.openstreetmap.org/',
            'pov_map_tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'pov_map_attribution' => '© OpenStreetMap-Mitwirkende',
        ];
    }

    public static function isTestProfileActive(): bool
    {
        return get_option('pov_test_profile_enabled', '0') === '1'
            && get_option('pov_geocoding_provider') === 'nominatim'
            && get_option('pov_routing_provider') === 'osrm'
            && str_contains((string) get_option('pov_geocoding_base_url'), 'nominatim.openstreetmap.org')
            && str_contains((string) get_option('pov_routing_base_url'), 'router.project-osrm.org');
    }
}
