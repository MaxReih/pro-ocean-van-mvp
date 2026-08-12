<?php

declare(strict_types=1);

namespace ProOceanVan\Rest;

use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\SuggestionState;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\SuggestionRepository;
use ProOceanVan\Security\Capabilities;
use ProOceanVan\Service\InternalSuggestionService;
use ProOceanVan\Service\MailService;
use ProOceanVan\Service\WeeklyClusterService;
use WP_Error;
use WP_REST_Request;

final class AdminController
{
    private string $namespace = 'pro-ocean-van/v1';

    public function register_routes(): void
    {
        $routes = [
            ['/admin/requests', 'GET', 'requests', Capabilities::VIEW_REQUESTS],
            ['/admin/requests/(?P<id>\d+)', 'GET', 'request', Capabilities::VIEW_REQUESTS],
            ['/admin/requests/(?P<id>\d+)/suggestions/recalculate', 'POST', 'recalculate', Capabilities::MANAGE_TOURS],
            ['/admin/suggestions/(?P<id>\d+)/accept', 'POST', 'accept', Capabilities::SEND_RESPONSES],
            ['/admin/suggestions/(?P<id>\d+)/reject', 'POST', 'reject', Capabilities::SEND_RESPONSES],
            ['/admin/requests/(?P<id>\d+)/send-proposals', 'POST', 'sendProposals', Capabilities::SEND_RESPONSES],
            ['/admin/requests/(?P<id>\d+)/confirm', 'POST', 'confirm', Capabilities::MANAGE_REQUESTS],
            ['/admin/weeks', 'GET', 'weeks', Capabilities::VIEW_TOURS],
            ['/admin/routes/recalculate', 'POST', 'routesRecalculate', Capabilities::MANAGE_TOURS],
        ];

        foreach ($routes as [$route, $method, $callback, $capability]) {
            register_rest_route($this->namespace, $route, [
                'methods' => $method,
                'callback' => [$this, $callback],
                'permission_callback' => static fn (): bool => current_user_can($capability),
            ]);
        }
    }

    public function requests(WP_REST_Request $request): array
    {
        return array_map(
            [$this, 'requestListDto'],
            (new RequestRepository())->list($request->get_params(), 100)
        );
    }

    public function request(WP_REST_Request $request): array|WP_Error
    {
        $item = (new RequestRepository())->find((int) $request['id']);
        return $item
            ? $this->requestDetailDto($item)
            : new WP_Error('pov_not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
    }

    public function recalculate(WP_REST_Request $request): array
    {
        $suggestions = (new InternalSuggestionService())->recalculate((int) $request['id']);
        return ['ok' => true, 'suggestion_count' => count($suggestions)];
    }

    public function accept(WP_REST_Request $request): array
    {
        (new SuggestionRepository())->setState((int) $request['id'], SuggestionState::ACCEPTED);
        return ['ok' => true];
    }

    public function reject(WP_REST_Request $request): array
    {
        (new SuggestionRepository())->setState((int) $request['id'], SuggestionState::REJECTED);
        return ['ok' => true];
    }

    public function sendProposals(WP_REST_Request $request): array|WP_Error
    {
        $id = (int) $request['id'];
        $repo = new RequestRepository();
        $item = $repo->find($id);
        if (! $item) {
            return new WP_Error('pov_not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
        }
        $suggestions = (new SuggestionRepository())->acceptedForRequest($id);
        foreach ($suggestions as $index => $suggestion) {
            if (! $this->dateAllowedForRequest((string) $suggestion['suggestion_date'], $item)
                || (new CalendarDayRepository())->isBlocked((string) $suggestion['suggestion_date'])
                || (new AppointmentRepository())->existsOnDate((string) $suggestion['suggestion_date'])) {
                (new SuggestionRepository())->setState((int) $suggestion['id'], SuggestionState::EXPIRED);
                unset($suggestions[$index]);
            }
        }
        $suggestions = array_values($suggestions);
        if (! $suggestions) {
            return new WP_Error('pov_no_suggestions', 'Keine akzeptierten Vorschläge vorhanden.', ['status' => 422]);
        }
        $sent = (new MailService())->sendProposal($item, $suggestions, (string) $request->get_param('subject'), (string) $request->get_param('message'));
        if (! $sent) {
            return new WP_Error('pov_mail_failed', 'E-Mail konnte nicht gesendet werden.', ['status' => 500]);
        }
        (new SuggestionRepository())->markSent(array_column($suggestions, 'id'));
        $repo->updateStatus($id, RequestStatus::PROPOSAL_SENT, WorkState::AWAITING_RESPONSE, ['proposal_sent_at' => current_time('mysql')]);
        return ['ok' => true];
    }

    public function confirm(WP_REST_Request $request): array|WP_Error
    {
        $id = (int) $request['id'];
        $item = (new RequestRepository())->find($id);
        if (! $item) {
            return new WP_Error('pov_not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
        }
        $date = sanitize_text_field((string) $request->get_param('date'));
        $city = sanitize_text_field((string) $request->get_param('public_city'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $city === '') {
            return new WP_Error('pov_validation', 'Datum und öffentliche Stadt sind erforderlich.', ['status' => 422]);
        }
        if (! $this->dateAllowedForRequest($date, $item)
            || (int) (new \DateTimeImmutable($date))->format('N') >= 6
            || (new CalendarDayRepository())->isBlocked($date)) {
            return new WP_Error('pov_conflict', 'Der Termin liegt außerhalb der Anfrage oder ist nicht mehr verfügbar.', ['status' => 409]);
        }
        $appointmentId = (new AppointmentRepository())->createFromRequest($item, $date, $city, [], (string) $request->get_param('internal_notes'));
        if ($appointmentId <= 0) {
            return new WP_Error('pov_conflict', 'An diesem Tag existiert bereits ein bestätigter Termin.', ['status' => 409]);
        }
        (new RequestRepository())->updateStatus($id, RequestStatus::CONFIRMED, WorkState::ACCEPTED, ['confirmed_at' => current_time('mysql')]);
        return ['ok' => true, 'appointment_id' => $appointmentId];
    }

    public function weeks(): array
    {
        return array_map([$this, 'tourDto'], (new WeeklyClusterService())->clusters());
    }

    public function routesRecalculate(): array
    {
        $clusters = (new WeeklyClusterService())->clusters();
        return [
            'ok' => true,
            'tour_count' => count($clusters),
        ];
    }

    private function requestListDto(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0),
            'public_uuid' => (string) ($item['public_uuid'] ?? ''),
            'institution_name' => (string) ($item['institution_name'] ?? ''),
            'postal_code' => (string) ($item['postal_code'] ?? ''),
            'city' => (string) ($item['city'] ?? ''),
            'state_code' => (string) ($item['state_code'] ?? ''),
            'created_at' => (string) ($item['created_at'] ?? ''),
            'request_mode' => (string) ($item['request_mode'] ?? ''),
            'specific_requested_date' => (string) ($item['specific_requested_date'] ?? ''),
            'desired_date_from' => (string) ($item['desired_date_from'] ?? ''),
            'desired_date_to' => (string) ($item['desired_date_to'] ?? ''),
            'main_status' => (string) ($item['main_status'] ?? ''),
            'work_state' => (string) ($item['work_state'] ?? ''),
        ];
    }

    private function requestDetailDto(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0),
            'public_uuid' => (string) ($item['public_uuid'] ?? ''),
            'institution_name' => (string) ($item['institution_name'] ?? ''),
            'institution_type' => (string) ($item['institution_type'] ?? ''),
            'contact_first_name' => (string) ($item['contact_first_name'] ?? ''),
            'contact_last_name' => (string) ($item['contact_last_name'] ?? ''),
            'contact_email' => (string) ($item['contact_email'] ?? ''),
            'contact_phone' => (string) ($item['contact_phone'] ?? ''),
            'street' => (string) ($item['street'] ?? ''),
            'house_number' => (string) ($item['house_number'] ?? ''),
            'postal_code' => (string) ($item['postal_code'] ?? ''),
            'city' => (string) ($item['city'] ?? ''),
            'state_code' => (string) ($item['state_code'] ?? ''),
            'request_mode' => (string) ($item['request_mode'] ?? ''),
            'specific_requested_date' => (string) ($item['specific_requested_date'] ?? ''),
            'desired_date_from' => (string) ($item['desired_date_from'] ?? ''),
            'desired_date_to' => (string) ($item['desired_date_to'] ?? ''),
            'possible_weekdays' => (string) ($item['possible_weekdays'] ?? ''),
            'main_status' => (string) ($item['main_status'] ?? ''),
            'work_state' => (string) ($item['work_state'] ?? ''),
            'parking_available' => (string) ($item['parking_available'] ?? ''),
            'indoor_room_available' => (string) ($item['indoor_room_available'] ?? ''),
            'bad_weather_option_available' => (string) ($item['bad_weather_option_available'] ?? ''),
            'electricity_available' => (string) ($item['electricity_available'] ?? ''),
            'electricity_outdoor_available' => (string) ($item['electricity_outdoor_available'] ?? ''),
            'electricity_outdoor_distance_m' => isset($item['electricity_outdoor_distance_m']) ? (int) $item['electricity_outdoor_distance_m'] : null,
            'electricity_charging_available' => (string) ($item['electricity_charging_available'] ?? ''),
            'water_available' => (string) ($item['water_available'] ?? ''),
            'changing_room_available' => (string) ($item['changing_room_available'] ?? ''),
            'shower_available' => (string) ($item['shower_available'] ?? ''),
            'natural_water_nearby' => (string) ($item['natural_water_nearby'] ?? ''),
            'presentation_equipment' => (string) ($item['presentation_equipment'] ?? ''),
            'presentation_equipment_other' => (string) ($item['presentation_equipment_other'] ?? ''),
            'laptop_connections' => (string) ($item['laptop_connections'] ?? ''),
            'laptop_connection_other' => (string) ($item['laptop_connection_other'] ?? ''),
            'wifi_available' => (string) ($item['wifi_available'] ?? ''),
            'accessibility_notes' => (string) ($item['accessibility_notes'] ?? ''),
            'group_notes' => (string) ($item['group_notes'] ?? ''),
            'general_notes' => (string) ($item['general_notes'] ?? ''),
            'internal_note' => (string) ($item['internal_note'] ?? ''),
            'privacy_consent' => (bool) ($item['privacy_consent'] ?? false),
            'privacy_consent_at' => (string) ($item['privacy_consent_at'] ?? ''),
            'route_distance_km' => $this->numberOrNull($item['route_distance_km'] ?? null),
            'route_cost' => $this->numberOrNull($item['route_cost'] ?? null),
            'participant_count' => (int) ($item['participant_count'] ?? 0),
            'classes' => array_map(static fn (array $class): array => [
                'class_name' => (string) ($class['class_name'] ?? ''),
                'grade' => (int) ($class['grade'] ?? 0),
                'participant_count' => (int) ($class['participant_count'] ?? 0),
            ], (array) ($item['classes'] ?? [])),
            'proposal_sent_at' => (string) ($item['proposal_sent_at'] ?? ''),
            'confirmed_at' => (string) ($item['confirmed_at'] ?? ''),
            'closed_at' => (string) ($item['closed_at'] ?? ''),
            'created_at' => (string) ($item['created_at'] ?? ''),
            'updated_at' => (string) ($item['updated_at'] ?? ''),
        ];
    }

    private function tourDto(array $cluster): array
    {
        return [
            'week' => (string) ($cluster['week'] ?? ''),
            'week_number' => (string) ($cluster['week_number'] ?? ''),
            'week_start' => (string) ($cluster['week_start'] ?? ''),
            'week_end' => (string) ($cluster['week_end'] ?? ''),
            'title' => (string) ($cluster['title'] ?? ''),
            'state_codes' => array_values(array_map('strval', (array) ($cluster['state_codes'] ?? []))),
            'request_count' => (int) ($cluster['request_count'] ?? 0),
            'confirmed_count' => (int) ($cluster['confirmed_count'] ?? 0),
            'missing_coordinates' => (int) ($cluster['missing_coordinates'] ?? 0),
            'route_distance_km' => $this->numberOrNull($cluster['route_distance_km'] ?? null),
            'route_duration_minutes' => $this->numberOrNull($cluster['route_duration_minutes'] ?? null),
            'estimated_cost' => $this->numberOrNull($cluster['estimated_cost'] ?? null),
            'start_label' => (string) ($cluster['start_label'] ?? ''),
            'priority' => (string) ($cluster['priority'] ?? ''),
            'stops' => array_map([$this, 'tourStopDto'], (array) ($cluster['stops'] ?? [])),
            'legs' => array_map([$this, 'tourLegDto'], (array) ($cluster['legs'] ?? [])),
            'overnights' => array_map([$this, 'tourOvernightDto'], (array) ($cluster['overnights'] ?? [])),
        ];
    }

    private function tourStopDto(array $stop): array
    {
        $type = (string) ($stop['_stop_type'] ?? '');
        $date = (string) ($stop['_planning_date'] ?? $stop['appointment_date'] ?? $stop['specific_requested_date'] ?? '');

        return [
            'id' => (int) ($stop['id'] ?? 0),
            'type' => $type,
            'date' => $date,
            'institution_name' => (string) ($stop['institution_name'] ?? ''),
            'postal_code' => (string) ($stop['postal_code'] ?? ''),
            'city' => (string) ($stop['city'] ?? $stop['public_city'] ?? ''),
            'state_code' => (string) ($stop['state_code'] ?? ''),
            'status' => $type === 'confirmed' ? RequestStatus::CONFIRMED : (string) ($stop['main_status'] ?? ''),
            'work_state' => $type === 'confirmed' ? WorkState::ACCEPTED : (string) ($stop['work_state'] ?? ''),
        ];
    }

    private function tourLegDto(array $leg): array
    {
        return [
            'from' => (string) ($leg['from'] ?? ''),
            'to' => (string) ($leg['to'] ?? ''),
            'distance_km' => $this->numberOrNull($leg['distance_km'] ?? null),
            'duration_minutes' => $this->numberOrNull($leg['duration_minutes'] ?? null),
        ];
    }

    private function tourOvernightDto(array $overnight): array
    {
        return [
            'after_stop' => (int) ($overnight['after_stop'] ?? 0),
            'date' => (string) ($overnight['date'] ?? ''),
            'place' => (string) ($overnight['place'] ?? ''),
            'next_place' => (string) ($overnight['next_place'] ?? ''),
            'next_leg_duration_minutes' => $this->numberOrNull($overnight['next_leg_duration_minutes'] ?? null),
            'reason' => (string) ($overnight['reason'] ?? ''),
        ];
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function dateAllowedForRequest(string $date, array $request): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        if (($request['request_mode'] ?? '') === 'specific_date') {
            return $date === (string) ($request['specific_requested_date'] ?? '');
        }
        $from = (string) ($request['desired_date_from'] ?? '');
        $to = (string) ($request['desired_date_to'] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
            && $date >= $from
            && $date <= $to;
    }
}
