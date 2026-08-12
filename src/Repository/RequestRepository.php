<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\SuggestionState;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Service\AttachmentService;

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
        $children = max(0, (int) ($payload['children_count'] ?? 0));
        $adults = max(0, (int) ($payload['adult_count'] ?? 0));
        $participantTotal = max($children + $adults, (int) ($payload['participant_total'] ?? 0));

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
            'institution_website' => esc_url_raw((string) ($payload['institution_website'] ?? '')),
            'contact_role' => sanitize_text_field((string) ($payload['contact_role'] ?? '')),
            'children_count' => $children,
            'adult_count' => $adults,
            'participant_total' => $participantTotal,
            'school_grade' => sanitize_text_field((string) ($payload['school_grade'] ?? '')),
            'school_class_count' => $this->positiveIntOrNull($payload['school_class_count'] ?? null),
            'school_teachers_per_class' => $this->nonNegativeIntOrNull($payload['school_teachers_per_class'] ?? null),
            'school_children_per_class' => $this->positiveIntOrNull($payload['school_children_per_class'] ?? null),
            'school_needs' => sanitize_textarea_field((string) ($payload['school_needs'] ?? '')),
            'school_schedule_notes' => sanitize_textarea_field((string) ($payload['school_schedule_notes'] ?? '')),
            'event_child_age_range' => sanitize_text_field((string) ($payload['event_child_age_range'] ?? '')),
            'occasion_description' => sanitize_textarea_field((string) ($payload['occasion_description'] ?? '')),
            'availability_window' => $this->allowedValue($payload['availability_window'] ?? '', ['morning', 'afternoon', 'full_day']),
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
            'parking_type' => $this->allowedValue($payload['parking_type'] ?? '', ['schoolyard', 'parking_lot', 'street', 'other']),
            'parking_location' => esc_url_raw((string) ($payload['parking_location'] ?? '')),
            'indoor_room_available' => $this->availabilityAnswer($payload['indoor_room_available'] ?? ''),
            'venue_type' => $this->allowedValue($payload['venue_type'] ?? '', ['indoor', 'outdoor', 'both']),
            'indoor_room_description' => sanitize_textarea_field((string) ($payload['indoor_room_description'] ?? '')),
            'outdoor_area_description' => sanitize_textarea_field((string) ($payload['outdoor_area_description'] ?? '')),
            'bad_weather_option_available' => $this->availabilityAnswer($payload['bad_weather_option_available'] ?? ''),
            'electricity_available' => $this->availabilityAnswer($payload['electricity_available'] ?? ''),
            'electricity_outdoor_available' => '',
            'electricity_outdoor_distance_m' => $this->nonNegativeIntOrNull($payload['electricity_outdoor_distance_m'] ?? null),
            'electricity_charging_available' => $this->availabilityAnswer($payload['electricity_charging_available'] ?? ''),
            'water_available' => $this->availabilityAnswer($payload['water_available'] ?? ''),
            'changing_room_available' => $this->availabilityAnswer($payload['changing_room_available'] ?? ''),
            'shower_available' => $this->availabilityAnswer($payload['shower_available'] ?? ''),
            'natural_water_nearby' => $this->availabilityAnswer($payload['natural_water_nearby'] ?? ''),
            'presentation_equipment' => $this->allowedValuesCsv($payload['presentation_equipment'] ?? [], ['chalkboard', 'projector', 'digital_display', 'other']),
            'presentation_equipment_other' => sanitize_text_field((string) ($payload['presentation_equipment_other'] ?? '')),
            'laptop_connections' => $this->allowedValuesCsv($payload['laptop_connections'] ?? [], ['usb_c', 'usb_a', 'other']),
            'laptop_connection_other' => sanitize_text_field((string) ($payload['laptop_connection_other'] ?? '')),
            'wifi_available' => $this->availabilityAnswer($payload['wifi_available'] ?? ''),
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
        $classParticipants = (new ClassRepository())->participantCount($id);
        $row['participant_count'] = (int) ($row['participant_total'] ?? 0) > 0
            ? (int) $row['participant_total']
            : $classParticipants;
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
            'warnings' => 0,
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

    public function updateDetails(int $id, array $data): bool
    {
        $request = $this->find($id);
        if (! $request) {
            return false;
        }
        $mode = ($data['request_mode'] ?? '') === 'specific_date' ? 'specific_date' : 'date_range';
        $children = max(0, (int) ($data['children_count'] ?? 0));
        $adults = max(0, (int) ($data['adult_count'] ?? 0));
        $total = $children + $adults;
        if ($total <= 0) {
            return false;
        }
        $specific = $mode === 'specific_date' ? $this->dateOrNull($data['specific_requested_date'] ?? null) : null;
        $from = $mode === 'date_range' ? $this->dateOrNull($data['desired_date_from'] ?? null) : null;
        $to = $mode === 'date_range' ? $this->dateOrNull($data['desired_date_to'] ?? null) : null;
        $weekdays = array_values(array_intersect(
            array_map('sanitize_key', (array) ($data['possible_weekdays'] ?? [])),
            ['mon', 'tue', 'wed', 'thu', 'fri']
        ));
        if (($mode === 'specific_date' && ! $specific)
            || ($mode === 'date_range' && (! $from || ! $to || $from > $to || ! $weekdays))) {
            return false;
        }
        $parkingType = $this->allowedValue($data['parking_type'] ?? '', ['schoolyard', 'parking_lot', 'street', 'other']);
        if ($parkingType === '') {
            return false;
        }

        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . 'pov_requests', [
            'request_mode' => $mode,
            'specific_requested_date' => $specific,
            'desired_date_from' => $from,
            'desired_date_to' => $to,
            'possible_weekdays' => $mode === 'date_range' ? implode(',', $weekdays) : '',
            'institution_name' => sanitize_text_field((string) ($data['institution_name'] ?? '')),
            'institution_type' => sanitize_text_field((string) ($data['institution_type'] ?? '')),
            'institution_website' => esc_url_raw((string) ($data['institution_website'] ?? '')),
            'contact_role' => sanitize_text_field((string) ($data['contact_role'] ?? '')),
            'children_count' => $children,
            'adult_count' => $adults,
            'participant_total' => $total,
            'school_grade' => sanitize_text_field((string) ($data['school_grade'] ?? '')),
            'school_class_count' => $this->positiveIntOrNull($data['school_class_count'] ?? null),
            'school_teachers_per_class' => $this->nonNegativeIntOrNull($data['school_teachers_per_class'] ?? null),
            'school_children_per_class' => $this->positiveIntOrNull($data['school_children_per_class'] ?? null),
            'school_needs' => sanitize_textarea_field((string) ($data['school_needs'] ?? '')),
            'school_schedule_notes' => sanitize_textarea_field((string) ($data['school_schedule_notes'] ?? '')),
            'event_child_age_range' => sanitize_text_field((string) ($data['event_child_age_range'] ?? '')),
            'occasion_description' => sanitize_textarea_field((string) ($data['occasion_description'] ?? '')),
            'availability_window' => $this->allowedValue($data['availability_window'] ?? '', ['morning', 'afternoon', 'full_day']),
            'contact_first_name' => sanitize_text_field((string) ($data['contact_first_name'] ?? '')),
            'contact_last_name' => sanitize_text_field((string) ($data['contact_last_name'] ?? '')),
            'contact_email' => sanitize_email((string) ($data['contact_email'] ?? '')),
            'contact_phone' => sanitize_text_field((string) ($data['contact_phone'] ?? '')),
            'general_notes' => sanitize_textarea_field((string) ($data['general_notes'] ?? '')),
            'parking_available' => $this->availabilityAnswer($data['parking_available'] ?? ''),
            'parking_type' => $parkingType,
            'parking_location' => esc_url_raw((string) ($data['parking_location'] ?? '')),
            'indoor_room_available' => $this->availabilityAnswer($data['indoor_room_available'] ?? ''),
            'venue_type' => $this->allowedValue($data['venue_type'] ?? '', ['indoor', 'outdoor', 'both']),
            'indoor_room_description' => sanitize_textarea_field((string) ($data['indoor_room_description'] ?? '')),
            'outdoor_area_description' => sanitize_textarea_field((string) ($data['outdoor_area_description'] ?? '')),
            'bad_weather_option_available' => $this->availabilityAnswer($data['bad_weather_option_available'] ?? ''),
            'electricity_available' => $this->availabilityAnswer($data['electricity_available'] ?? ''),
            'electricity_outdoor_available' => '',
            'electricity_outdoor_distance_m' => $this->nonNegativeIntOrNull($data['electricity_outdoor_distance_m'] ?? null),
            'electricity_charging_available' => $this->availabilityAnswer($data['electricity_charging_available'] ?? ''),
            'water_available' => $this->availabilityAnswer($data['water_available'] ?? ''),
            'changing_room_available' => $this->availabilityAnswer($data['changing_room_available'] ?? ''),
            'shower_available' => $this->availabilityAnswer($data['shower_available'] ?? ''),
            'natural_water_nearby' => $this->availabilityAnswer($data['natural_water_nearby'] ?? ''),
            'presentation_equipment' => $this->allowedValuesCsv($data['presentation_equipment'] ?? [], ['chalkboard', 'projector', 'digital_display', 'other']),
            'presentation_equipment_other' => sanitize_text_field((string) ($data['presentation_equipment_other'] ?? '')),
            'laptop_connections' => $this->allowedValuesCsv($data['laptop_connections'] ?? [], ['usb_c', 'usb_a', 'other']),
            'laptop_connection_other' => sanitize_text_field((string) ($data['laptop_connection_other'] ?? '')),
            'wifi_available' => $this->availabilityAnswer($data['wifi_available'] ?? ''),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        if ($updated === false) {
            return false;
        }
        $classes = new ClassRepository();
        $classes->updateFirstName($id, sanitize_text_field((string) ($data['target_group'] ?? '')));
        $classes->syncParticipantTotal($id, $total);
        return true;
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
            'appointment' => (new AppointmentRepository())->findForRequest($id),
            'communications' => (new CommunicationRepository())->forRequest($id),
            'tour_expenses' => (new TourExpenseRepository())->forRequest($id),
        ];
    }

    public function anonymize(int $id): void
    {
        $attachmentIds = [];
        foreach ((new CommunicationRepository())->forRequest($id) as $communication) {
            $attachmentIds = array_merge($attachmentIds, (array) ($communication['attachment_ids'] ?? []));
        }
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'pov_requests', [
            'institution_name' => 'Anonymisiert',
            'contact_first_name' => '',
            'contact_last_name' => '',
            'contact_email' => '',
            'contact_phone' => '',
            'contact_role' => '',
            'institution_website' => '',
            'street' => '',
            'house_number' => '',
            'accessibility_notes' => '',
            'group_notes' => '',
            'general_notes' => '',
            'school_needs' => '',
            'school_schedule_notes' => '',
            'event_child_age_range' => '',
            'occasion_description' => '',
            'indoor_room_description' => '',
            'outdoor_area_description' => '',
            'parking_location' => '',
            'presentation_equipment_other' => '',
            'laptop_connection_other' => '',
            'privacy_consent' => 0,
            'updated_at' => current_time('mysql'),
            'closed_at' => current_time('mysql'),
        ], ['id' => $id]);
        $wpdb->update($wpdb->prefix . 'pov_appointments', [
            'institution_name' => 'Anonymisiert',
            'contact_name' => '',
            'contact_email' => '',
            'contact_phone' => '',
            'street' => '',
            'house_number' => '',
            'internal_notes' => '',
            'cancellation_reason' => '',
            'updated_at' => current_time('mysql'),
        ], ['request_id' => $id]);
        $wpdb->update($wpdb->prefix . 'pov_communications', [
            'subject' => '',
            'message' => '[Anonymisiert]',
            'sender_name' => '',
            'recipient' => '',
            'attachment_ids' => '[]',
        ], ['request_id' => $id]);
        $expenses = $wpdb->prefix . 'pov_tour_expenses';
        $appointments = $wpdb->prefix . 'pov_appointments';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$expenses} e
             LEFT JOIN {$appointments} a ON a.id = e.appointment_id
             SET e.place = '', e.note = ''
             WHERE e.request_id = %d OR a.request_id = %d",
            $id,
            $id
        ));
        (new AttachmentService())->deleteRequestFiles($id, $attachmentIds);
    }

    private function newPublicUuid(): string
    {
        return 'OV-' . strtoupper(wp_generate_password(10, false, false));
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = sanitize_text_field((string) $value);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            return null;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
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

    private function allowedValue(mixed $value, array $allowed): string
    {
        $value = sanitize_key((string) $value);
        return in_array($value, $allowed, true) ? $value : '';
    }

    private function allowedValuesCsv(mixed $values, array $allowed): string
    {
        $values = is_array($values) ? $values : explode(',', (string) $values);
        $values = array_map(static fn (mixed $value): string => sanitize_key((string) $value), $values);
        return implode(',', array_values(array_unique(array_intersect($values, $allowed))));
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        return max(1, (int) $value);
    }

    private function nonNegativeIntOrNull(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        return max(0, (int) $value);
    }
}
