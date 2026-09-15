<?php

declare(strict_types=1);

namespace ProOceanVan;

use ProOceanVan\Database\Schema;
use ProOceanVan\Portal\OperationsPortal;
use ProOceanVan\Security\Capabilities;
use ProOceanVan\Service\AttachmentService;

final class Activation
{
    public static function activate(): void
    {
        self::installTables();
        self::seedStates();
        self::seedOptions();
        Capabilities::syncRoles();
        update_option('pov_capability_setup_version', '1', false);
        if (did_action('init')) {
            OperationsPortal::ensurePage();
        } else {
            add_action('init', [OperationsPortal::class, 'ensurePage'], 5);
        }

        if (! wp_next_scheduled('pov_cleanup_route_cache')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'pov_cleanup_route_cache');
        }
    }

    public static function maybeUpgrade(): void
    {
        self::seedOptions();
        if ((string) get_option('pov_capability_setup_version', '') !== '1') {
            Capabilities::syncRoles();
            update_option('pov_capability_setup_version', '1', false);
        }
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
        $previousVersion = (string) get_option('pov_schema_version', '');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ((new Schema())->sql() as $statement) {
            dbDelta($statement);
        }

        self::migrateSuggestionOrderColumn();
        if ($previousVersion === '' || version_compare($previousVersion, '2026.07.24.1', '<')) {
            self::migrateAppointmentReportingData();
        }
        if ($previousVersion === '' || version_compare($previousVersion, '2026.07.24.4', '<')) {
            (new AttachmentService())->protectKnownAttachments();
        }
        if ($previousVersion === '' || version_compare($previousVersion, '2026.07.24.5', '<')) {
            self::migrateWalkInEventGroups();
        }
        self::migrateAppointmentDateIndex();

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

    private static function migrateAppointmentReportingData(): void
    {
        global $wpdb;
        $appointments = $wpdb->prefix . 'pov_appointments';
        $requests = $wpdb->prefix . 'pov_requests';
        $classes = $wpdb->prefix . 'pov_request_classes';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $appointments));
        if ($exists !== $appointments) {
            return;
        }

        $wpdb->query(
            "UPDATE {$requests} r
             SET r.participant_total = (
                 SELECT COALESCE(SUM(c.participant_count), 0)
                 FROM {$classes} c
                 WHERE c.request_id = r.id
             )
             WHERE r.participant_total = 0"
        );
        $wpdb->query(
            "UPDATE {$appointments} a
             INNER JOIN {$requests} r ON r.id = a.request_id
             SET a.event_type = CASE
                    WHEN LOWER(r.institution_type) LIKE '%schule%' THEN 'school'
                    WHEN LOWER(r.institution_type) LIKE '%veranstaltung%' OR LOWER(r.institution_type) LIKE '%event%' THEN 'event'
                    ELSE 'other'
                 END
             WHERE a.event_type = '' OR a.event_type = 'other'"
        );
    }

    private static function migrateAppointmentDateIndex(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        $index = $wpdb->get_row("SHOW INDEX FROM {$table} WHERE Key_name = 'appointment_date'", ARRAY_A);
        if ($index && (int) ($index['Non_unique'] ?? 1) === 0) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX appointment_date, ADD KEY appointment_date (appointment_date)");
        }
    }

    private static function migrateWalkInEventGroups(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_calendar_days';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, calendar_date, public_title, public_description, public_location, public_url, public_note
             FROM {$table}
             WHERE availability_state = %s AND (public_event_group IS NULL OR public_event_group = '')
             ORDER BY calendar_date ASC",
            'walk_in'
        ), ARRAY_A) ?: [];
        $previousDate = null;
        $previousFingerprint = null;
        $group = '';
        foreach ($rows as $row) {
            $date = (string) ($row['calendar_date'] ?? '');
            $fingerprint = hash('sha256', (string) wp_json_encode([
                $row['public_title'] ?? '',
                $row['public_description'] ?? '',
                $row['public_location'] ?? '',
                $row['public_url'] ?? '',
                $row['public_note'] ?? '',
            ]));
            $contiguous = $previousDate instanceof \DateTimeImmutable
                && $previousDate->modify('+1 day')->format('Y-m-d') === $date;
            if (! $contiguous || $fingerprint !== $previousFingerprint) {
                $group = wp_generate_uuid4();
            }
            $wpdb->update($table, ['public_event_group' => $group], ['id' => (int) $row['id']]);
            $previousDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: null;
            $previousFingerprint = $fingerprint;
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
            'pov_response_teacher_template' => "hier kommen die wichtigsten Informationen für Lehrkräfte zur Vorbereitung des Ocean-Van-Einsatzes:",
            'pov_response_parents_template' => "hier kommen die Informationen für Eltern und Teilnehmende zur Vorbereitung des Ocean-Van-Einsatzes:",
            'pov_response_followup_template' => "vielen Dank für den gemeinsamen Ocean-Van-Einsatz. Wir freuen uns über euer Feedback:",
            'pov_school_grade_options' => '3,4',
            'pov_state_windows' => [],
            'pov_confirmation_attachment_ids' => '',
            'pov_proposal_attachment_ids' => '',
            'pov_accept_attachment_ids' => '',
            'pov_question_attachment_ids' => '',
            'pov_reject_attachment_ids' => '',
            'pov_teacher_attachment_ids' => '',
            'pov_parents_attachment_ids' => '',
            'pov_followup_attachment_ids' => '',
            'pov_privacy_page_url' => '',
            'pov_frontend_password_enabled' => '0',
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
