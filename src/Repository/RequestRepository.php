<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\SuggestionState;
use ProOceanVan\Domain\WorkState;

final class RequestRepository
{
    public function create(array $payload): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_requests';
        $mode = (string) ($payload['request_mode'] ?? 'date_range');
        $mode = $mode === 'specific_date' ? 'specific_date' : 'date_range';
        $now = current_time('mysql');
        $uuid = $this->newPublicUuid();

        $inserted = $wpdb->insert($table, [
            'public_uuid' => $uuid,
            'main_status' => RequestStatus::RECEIVED,
            'work_state' => WorkState::NEW,
            'request_mode' => $mode,
            'specific_requested_date' => $mode === 'specific_date' ? $this->dateOrNull($payload['specific_requested_date'] ?? null) : null,
            'desired_date_from' => $mode === 'date_range' ? $this->dateOrNull($payload['desired_date_from'] ?? null) : null,
            'desired_date_to' => $mode === 'date_range' ? $this->dateOrNull($payload['desired_date_to'] ?? null) : null,
            'possible_weekdays' => implode(',', array_values(array_intersect(
                array_map('sanitize_key', (array) ($payload['possible_weekdays'] ?? [])),
                ['mon', 'tue', 'wed', 'thu', 'fri']
            ))),
            'institution_name' => sanitize_text_field((string) ($payload['institution_name'] ?? '')),
            'institution_type' => sanitize_text_field((string) ($payload['institution_type'] ?? '')),
            'contact_first_name' => sanitize_text_field((string) ($payload['contact_first_name'] ?? '')),
            'contact_last_name' => sanitize_text_field((string) ($payload['contact_last_name'] ?? '')),
            'contact_email' => sanitize_email((string) ($payload['contact_email'] ?? '')),
            'contact_phone' => sanitize_text_field((string) ($payload['contact_phone'] ?? '')),
            'street' => sanitize_text_field((string) ($payload['street'] ?? '')),
            'house_number' => sanitize_text_field((string) ($payload['house_number'] ?? '')),
            'postal_code' => preg_replace('/\D+/', '', (string) ($payload['postal_code'] ?? '')),
            'city' => sanitize_text_field((string) ($payload['city'] ?? '')),
            'state_code' => strtoupper(sanitize_key((string) ($payload['state_code'] ?? ''))),
            'latitude' => $this->coordinateOrNull($payload['_server_latitude'] ?? null, -90, 90),
            'longitude' => $this->coordinateOrNull($payload['_server_longitude'] ?? null, -180, 180),
            'parking_available' => $this->availabilityAnswer($payload['parking_available'] ?? ''),
            'indoor_room_available' => $this->availabilityAnswer($payload['indoor_room_available'] ?? ''),
            'bad_weather_option_available' => $this->availabilityAnswer($payload['bad_weather_option_available'] ?? ''),
            'electricity_available' => $this->availabilityAnswer($payload['electricity_available'] ?? ''),
            'water_available' => $this->availabilityAnswer($payload['water_available'] ?? ''),
            'accessibility_notes' => sanitize_textarea_field((string) ($payload['accessibility_notes'] ?? '')),
            'group_notes' => sanitize_textarea_field((string) ($payload['group_notes'] ?? '')),
            'general_notes' => sanitize_textarea_field((string) ($payload['general_notes'] ?? '')),
            'privacy_consent' => ! empty($payload['privacy_consent']) ? 1 : 0,
            'privacy_consent_at' => ! empty($payload['privacy_consent']) ? $now : null,
            'route_distance_km' => $payload['_server_route_distance_km'] ?? null,
            'route_cost' => $payload['_server_route_cost'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $requestId = $inserted ? (int) $wpdb->insert_id : 0;
        if ($requestId > 0) {
            (new ClassRepository())->replaceForRequest($requestId, (array) ($payload['classes'] ?? []));
        }

        return $requestId;
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_requests';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if (! $row) {
            return null;
        }
        $row['classes'] = (new ClassRepository())->forRequest($id);
        $row['participant_count'] = (new ClassRepository())->participantCount($id);
        return $row;
    }

    public function list(array $filters = [], int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_requests';
        $where = ['1=1'];
        $args = [];
        foreach (['main_status', 'work_state', 'state_code', 'request_mode'] as $field) {
            if (! empty($filters[$field])) {
                $where[] = "{$field} = %s";
                $args[] = sanitize_text_field((string) $filters[$field]);
            }
        }
        if (! empty($filters['s'])) {
            $like = '%' . $wpdb->esc_like((string) $filters['s']) . '%';
            $where[] = '(institution_name LIKE %s OR city LIKE %s OR postal_code LIKE %s OR contact_last_name LIKE %s OR public_uuid LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT %d';
        $args[] = max(1, min(200, $limit));

        return $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: [];
    }

    public function planningForRange(string $start, string $end): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
            || $start > $end) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pov_requests';
        $suggestions = $wpdb->prefix . 'pov_suggestions';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.work_state, r.request_mode, r.specific_requested_date, r.desired_date_from, r.desired_date_to, r.possible_weekdays, r.state_code,
                    (SELECT s.suggestion_date
                     FROM {$suggestions} s
                     WHERE s.request_id = r.id AND s.state IN (%s, %s, %s)
                     ORDER BY CASE s.state WHEN 'accepted' THEN 1 WHEN 'sent' THEN 2 ELSE 3 END, s.sort_order ASC, s.score ASC
                     LIMIT 1) AS planned_suggestion_date
             FROM {$table} r
             WHERE r.work_state NOT IN (%s, %s, %s)
               AND (
                    (r.request_mode = 'specific_date' AND r.specific_requested_date BETWEEN %s AND %s)
                    OR
                    (r.request_mode = 'date_range' AND r.desired_date_from <= %s AND r.desired_date_to >= %s)
               )
             ORDER BY r.created_at ASC",
            SuggestionState::ACCEPTED,
            SuggestionState::SENT,
            SuggestionState::PENDING,
            WorkState::ACCEPTED,
            WorkState::REJECTED,
            WorkState::CANCELLED,
            $start,
            $end,
            $end,
            $start
        ), ARRAY_A) ?: [];
    }

    public function counts(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_requests';
        return [
            'open' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE work_state NOT IN ('accepted','rejected','cancelled')"),
            'new' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE work_state = 'new'"),
            'in_review' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE work_state = 'in_review'"),
            'awaiting_response' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE work_state = 'awaiting_response'"),
            'warnings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE parking_available != 'yes' OR indoor_room_available != 'yes' OR bad_weather_option_available != 'yes' OR electricity_available != 'yes' OR water_available != 'yes'"),
            'routing_errors' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE work_state NOT IN ('accepted','rejected','cancelled') AND (latitude IS NULL OR longitude IS NULL OR route_distance_km IS NULL)"),
        ];
    }

    public function updateStatus(int $id, string $mainStatus, string $workState, array $extra = []): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_requests';
        $data = array_merge([
            'main_status' => $mainStatus,
            'work_state' => $workState,
            'updated_at' => current_time('mysql'),
        ], $extra);
        $wpdb->update($table, $data, ['id' => $id]);
    }

    public function updateMailAttempt(int $id, bool $sent, string $error = ''): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'pov_requests', [
            'confirmation_mail_sent' => $sent ? 1 : 0,
            'confirmation_mail_error' => $error,
            'confirmation_mail_attempted_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public function updateInternalNote(int $id, string $note): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'pov_requests', [
            'internal_note' => sanitize_textarea_field($note),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public function updateAddress(int $id, array $address): bool
    {
        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . 'pov_requests', [
            'street' => sanitize_text_field((string) ($address['street'] ?? '')),
            'house_number' => sanitize_text_field((string) ($address['house_number'] ?? '')),
            'postal_code' => preg_replace('/\D+/', '', (string) ($address['postal_code'] ?? '')),
            'city' => sanitize_text_field((string) ($address['city'] ?? '')),
            'state_code' => strtoupper(sanitize_key((string) ($address['state_code'] ?? ''))),
            'latitude' => null,
            'longitude' => null,
            'route_distance_km' => null,
            'route_cost' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        return $updated !== false;
    }

    public function updateRoutingData(int $id, array $routing): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'pov_requests', [
            'latitude' => $this->coordinateOrNull($routing['latitude'] ?? null, -90, 90),
            'longitude' => $this->coordinateOrNull($routing['longitude'] ?? null, -180, 180),
            'route_distance_km' => is_numeric($routing['distance_km'] ?? null) ? max(0, (float) $routing['distance_km']) : null,
            'route_cost' => is_numeric($routing['cost'] ?? null) ? max(0, (float) $routing['cost']) : null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public function export(int $id): ?array
    {
        $request = $this->find($id);
        if (! $request) {
            return null;
        }
        return [
            'request' => $request,
            'classes' => (new ClassRepository())->forRequest($id),
            'suggestions' => (new SuggestionRepository())->forRequest($id),
        ];
    }

    public function anonymize(int $id): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'pov_requests', [
            'institution_name' => 'Anonymisiert',
            'contact_first_name' => '',
            'contact_last_name' => '',
            'contact_email' => '',
            'contact_phone' => '',
            'street' => '',
            'house_number' => '',
            'accessibility_notes' => '',
            'group_notes' => '',
            'general_notes' => '',
            'privacy_consent' => 0,
            'updated_at' => current_time('mysql'),
            'closed_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    private function newPublicUuid(): string
    {
        return 'OV-' . strtoupper(wp_generate_password(10, false, false));
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = sanitize_text_field((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function coordinateOrNull(mixed $value, float $min, float $max): ?float
    {
        if ($value === '' || $value === null || ! is_numeric($value)) {
            return null;
        }
        $coordinate = (float) $value;
        return $coordinate >= $min && $coordinate <= $max ? $coordinate : null;
    }

    private function availabilityAnswer(mixed $value): string
    {
        $value = sanitize_key((string) $value);
        return in_array($value, ['yes', 'no', 'unknown'], true) ? $value : 'unknown';
    }
}
