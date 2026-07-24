<?php

declare(strict_types=1);

namespace ProOceanVan\Rest;

use DateTimeImmutable;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\StateRepository;
use ProOceanVan\Service\EligibilityTokenService;
use ProOceanVan\Service\MailService;
use ProOceanVan\Service\RequestRoutingService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RequestController
{
    private const MAX_BODY_BYTES = 65536;
    private const MAX_RANGE_DAYS = 180;
    private const MAX_BOOKING_MONTHS = 18;
    private const MAX_GROUPS = 10;
    private const MAX_PARTICIPANTS = 500;

    private const TEXT_LIMITS = [
        'institution_name' => 255,
        'institution_type' => 120,
        'contact_first_name' => 120,
        'contact_last_name' => 120,
        'contact_email' => 190,
        'contact_phone' => 80,
        'street' => 190,
        'house_number' => 40,
        'postal_code' => 5,
        'city' => 120,
        'state_code' => 8,
        'request_mode' => 32,
        'specific_requested_date' => 10,
        'desired_date_from' => 10,
        'desired_date_to' => 10,
        'eligibility_token' => 20000,
        'accessibility_notes' => 5000,
        'group_notes' => 5000,
        'general_notes' => 5000,
    ];

    private string $namespace = 'pro-ocean-van/v1';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/requests', [
            'methods' => 'POST',
            'callback' => [$this, 'create'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (strlen((string) $request->get_body()) > self::MAX_BODY_BYTES) {
            return new WP_Error('pov_payload_too_large', 'Die Anfrage enthält zu viele Daten.', ['status' => 413]);
        }

        $payload = (array) $request->get_json_params();
        if (! empty($payload['website'])) {
            return new WP_Error('pov_spam', 'Die Anfrage konnte nicht gesendet werden. Bitte versuche es erneut.', ['status' => 400]);
        }
        if ((time() - (int) ($payload['form_started_at'] ?? time())) < 2) {
            return new WP_Error('pov_too_fast', 'Die Anfrage konnte nicht gesendet werden. Bitte versuche es erneut.', ['status' => 400]);
        }
        $validation = $this->validate($payload);
        if ($validation) {
            return new WP_Error('pov_validation', $validation, ['status' => 422]);
        }

        $payload = (new RequestRoutingService())->enrich($payload);
        $id = (new RequestRepository())->create($payload);
        if ($id <= 0) {
            return new WP_Error('pov_create_failed', 'Die Anfrage konnte nicht gesendet werden. Bitte versuche es erneut.', ['status' => 500]);
        }

        $mail = new MailService();
        $mail->sendConfirmation($id);
        $mail->sendTeamNotification($id);
        $stored = (new RequestRepository())->find($id);

        return new WP_REST_Response([
            'ok' => true,
            'public_uuid' => $stored['public_uuid'] ?? '',
            'message' => 'Anfrage gesendet',
        ], 201);
    }

    private function validate(array $payload): string
    {
        foreach (self::TEXT_LIMITS as $field => $limit) {
            if (isset($payload[$field]) && ! is_scalar($payload[$field])) {
                return 'Bitte prüfe die eingegebenen Textfelder.';
            }
            if ($this->textLength((string) ($payload[$field] ?? '')) > $limit) {
                return 'Mindestens ein Textfeld ist zu lang.';
            }
        }

        if (empty($payload['privacy_consent'])) {
            return 'Bitte bestätige die Datenschutzerklärung.';
        }
        foreach (['institution_name', 'institution_type', 'contact_first_name', 'contact_last_name', 'contact_phone', 'street', 'house_number', 'city'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                return 'Bitte fülle alle Pflichtfelder aus.';
            }
        }
        if (! (new StateRepository())->isActive((string) ($payload['state_code'] ?? ''))) {
            return 'Für dieses Bundesland nehmen wir aktuell noch keine Anfragen an.';
        }
        if (! is_email((string) ($payload['contact_email'] ?? ''))) {
            return 'Bitte prüfe die E-Mail-Adresse.';
        }
        if (! preg_match('/^\d{5}$/', (string) ($payload['postal_code'] ?? ''))) {
            return 'Bitte prüfe die PLZ.';
        }
        $children = filter_var($payload['children_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_PARTICIPANTS]]);
        $adults = filter_var($payload['adult_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_PARTICIPANTS]]);
        if ($children === false || $adults === false || ($children + $adults) < 1 || ($children + $adults) > self::MAX_PARTICIPANTS) {
            return 'Bitte prüfe die Anzahl der Kinder und Erwachsenen.';
        }
        $mode = (string) ($payload['request_mode'] ?? 'date_range');
        if (! in_array($mode, ['specific_date', 'date_range'], true)) {
            return 'Bitte prüfe die gewünschte Terminart.';
        }

        $todayDate = new DateTimeImmutable('today', wp_timezone());
        $today = $todayDate->format('Y-m-d');
        $latestDate = $todayDate->modify('+' . self::MAX_BOOKING_MONTHS . ' months');
        if ($mode === 'specific_date') {
            $date = (string) ($payload['specific_requested_date'] ?? '');
            if (! $this->validDate($date)) {
                return 'Bitte wähle einen gültigen zukünftigen Werktag.';
            }
            $requestedDate = new DateTimeImmutable($date, wp_timezone());
            if ($date < $today || $requestedDate > $latestDate || (int) $requestedDate->format('N') >= 6) {
                return 'Bitte wähle einen zukünftigen Werktag.';
            }
            if (! (new EligibilityTokenService())->allows(
                (string) ($payload['eligibility_token'] ?? ''),
                (string) ($payload['postal_code'] ?? ''),
                (string) ($payload['state_code'] ?? ''),
                $date
            )) {
                return 'Dieser Tag passt nicht mehr zur geprüften Route. Bitte suche die Termine erneut.';
            }
            if ((new CalendarDayRepository())->isBlocked($date) || (new AppointmentRepository())->existsOnDate($date)) {
                return 'Dieser Tag ist inzwischen nicht mehr verfügbar. Bitte wähle einen anderen Termin.';
            }
        } else {
            $from = (string) ($payload['desired_date_from'] ?? '');
            $to = (string) ($payload['desired_date_to'] ?? '');
            if (! $this->validDate($from) || ! $this->validDate($to) || $from > $to) {
                return 'Bitte prüfe den gewünschten Zeitraum.';
            }
            $fromDate = new DateTimeImmutable($from, wp_timezone());
            $toDate = new DateTimeImmutable($to, wp_timezone());
            if ($fromDate < $todayDate || $toDate > $latestDate || ((int) $fromDate->diff($toDate)->days + 1) > self::MAX_RANGE_DAYS) {
                return 'Der Zeitraum darf höchstens 180 Tage umfassen und maximal 18 Monate in der Zukunft liegen.';
            }
            $submittedWeekdays = $payload['possible_weekdays'] ?? [];
            if (! is_array($submittedWeekdays) || array_filter($submittedWeekdays, static fn (mixed $weekday): bool => ! is_string($weekday))) {
                return 'Bitte prüfe die möglichen Wochentage.';
            }
            $weekdays = array_values(array_intersect($submittedWeekdays, ['mon', 'tue', 'wed', 'thu', 'fri']));
            if (! $weekdays) {
                return 'Bitte wähle mindestens einen möglichen Wochentag.';
            }
        }
        if (empty($payload['classes']) || ! is_array($payload['classes'])) {
            return 'Mindestens eine Klasse ist erforderlich.';
        }
        if (count($payload['classes']) > self::MAX_GROUPS) {
            return 'Es können höchstens zehn Klassen oder Gruppen angefragt werden.';
        }
        $participants = 0;
        foreach ((array) $payload['classes'] as $class) {
            if (! is_array($class)) {
                return 'Bitte prüfe die Klassenangaben.';
            }
            foreach (['class_name', 'grade', 'participant_count'] as $classField) {
                if (isset($class[$classField]) && ! is_scalar($class[$classField])) {
                    return 'Bitte prüfe die Klassenangaben.';
                }
            }
            $className = trim((string) ($class['class_name'] ?? ''));
            $participantCount = (int) ($class['participant_count'] ?? 0);
            if ($className === '' || $this->textLength($className) > 80 || $participantCount < 1 || $participantCount > self::MAX_PARTICIPANTS || (int) ($class['grade'] ?? 0) < 1 || (int) ($class['grade'] ?? 0) > 6) {
                return 'Bitte prüfe die Klassenangaben.';
            }
            $participants += $participantCount;
            if ($participants > self::MAX_PARTICIPANTS) {
                return 'Insgesamt können höchstens 500 Teilnehmende angefragt werden.';
            }
        }
        if ($participants !== ($children + $adults)) {
            return 'Die Personenzahl stimmt nicht mit der Gruppe überein.';
        }
        foreach (['parking_available', 'indoor_room_available', 'bad_weather_option_available', 'electricity_available', 'water_available'] as $field) {
            if (isset($payload[$field]) && ! is_scalar($payload[$field])) {
                return 'Bitte beantworte alle Vor-Ort-Fragen.';
            }
            if (! in_array((string) ($payload[$field] ?? ''), ['yes', 'no', 'unknown'], true)) {
                return 'Bitte beantworte alle Vor-Ort-Fragen.';
            }
        }

        return '';
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
