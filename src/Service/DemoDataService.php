<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateTimeImmutable;
use ProOceanVan\Domain\CalendarState;
use ProOceanVan\Domain\EventType;
use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\ClassRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\SuggestionRepository;
use RuntimeException;
use Throwable;

/**
 * Creates deterministic demo records without calling routing providers.
 *
 * The service is deliberately not registered during normal plugin boot. It is
 * only instantiated by explicit demo tooling such as the local CLI script or
 * the public WordPress Playground blueprint.
 */
final class DemoDataService
{
    public const OPTION_KEY = 'pov_demo_data_manifest_v1';

    private const MANIFEST_VERSION = 2;
    private const MARKER_PREFIX = 'POV_DEMO_DATA:';

    public function seed(): array
    {
        global $wpdb;

        $this->assertRequiredTables();
        $manifest = $this->manifest();
        $manifest['appointment_dates'] = $this->resolveAppointmentDates($manifest);
        $fixtures = $this->fixtures($manifest);
        $requests = new RequestRepository();
        $classes = new ClassRepository();
        $requestIds = [];
        $appointmentIds = [];
        $createdRequests = [];
        $createdAppointments = [];

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($fixtures as $key => $fixture) {
                $requestId = $this->findRequestId($key, $fixture, $manifest);
                if ($requestId <= 0) {
                    $requestId = $requests->create($fixture['payload']);
                    if ($requestId <= 0) {
                        throw new RuntimeException('Demo-Anfrage konnte nicht angelegt werden: ' . $key . '. ' . (string) $wpdb->last_error);
                    }
                    $createdRequests[] = $requestId;
                } elseif (! $classes->forRequest($requestId)) {
                    $classes->replaceForRequest($requestId, $fixture['payload']['classes']);
                }

                $requestIds[$key] = $requestId;
                $this->synchronizeRequestProfile($requestId, $fixture['payload'], $key);
                $this->synchronizeRequestSchedule($requestId, $fixture['payload'], $key);
                $requests->updateInternalNote($requestId, $this->marker($key) . "\n" . $fixture['internal_note']);
                $requests->updateRoutingData($requestId, [
                    'latitude' => $fixture['payload']['_server_latitude'],
                    'longitude' => $fixture['payload']['_server_longitude'],
                    'distance_km' => $fixture['payload']['_server_route_distance_km'],
                    'cost' => $fixture['payload']['_server_route_cost'],
                ]);
                $requests->updateStatus(
                    $requestId,
                    $fixture['main_status'],
                    $fixture['work_state'],
                    $this->workflowTimestamps($fixture, $manifest)
                );
                if (in_array($fixture['work_state'], [WorkState::NEW, WorkState::IN_REVIEW], true)) {
                    $this->synchronizeSuggestions($requestId, $fixture);
                }
            }

            foreach ($fixtures as $key => $fixture) {
                if (empty($fixture['appointment'])) {
                    continue;
                }

                $requestId = $requestIds[$key];
                $appointmentId = $this->findAppointmentId($requestId);
                $request = $requests->find($requestId);
                if (! $request) {
                    throw new RuntimeException('Demo-Anfrage für Termin nicht gefunden: ' . $key);
                }

                if ($appointmentId <= 0) {
                    $appointmentId = (new AppointmentRepository())->createFromRequest(
                        $request,
                        $fixture['appointment']['date'],
                        $fixture['appointment']['public_city'],
                        $fixture['appointment']['route'],
                        $this->marker($key) . ' Bestätigter Demo-Termin.'
                    );
                    if ($appointmentId <= 0) {
                        throw new RuntimeException('Demo-Termin konnte nicht angelegt werden: ' . $key . '. ' . (string) $wpdb->last_error);
                    }
                    $createdAppointments[] = $appointmentId;
                }

                $this->synchronizeAppointment($appointmentId, $request, $fixture['appointment'], $key);
                $appointmentIds[$key] = $appointmentId;
            }

            $walkInDate = (string) ($fixtures['public_event_confirmed']['appointment']['date'] ?? '');
            if ($this->validDate($walkInDate)) {
                (new CalendarDayRepository())->upsert(
                    $walkInDate,
                    CalendarState::WALK_IN,
                    'Offenes Programm – ohne Anmeldung.',
                    $this->marker('walk_in'),
                    [],
                    [
                        'title' => 'Zukunftsfest Nürnberg',
                        'description' => 'Der Ocean Van ist mit einem offenen Mitmachprogramm vor Ort. Kommt einfach vorbei.',
                        'location' => 'Hauptmarkt, Nürnberg',
                        'url' => 'https://www.pro-ocean.com/',
                        'participants_children' => '46',
                        'participants_adults' => '34',
                    ]
                );
            }

            $manifest['request_ids'] = $requestIds;
            $manifest['appointment_ids'] = $appointmentIds;
            $manifest['updated_at'] = current_time('mysql');
            update_option(self::OPTION_KEY, $manifest, false);
            $wpdb->query('COMMIT');
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }

        return [
            'ok' => true,
            'manifest_option' => self::OPTION_KEY,
            'anchor_date' => $manifest['anchor_date'],
            'request_count' => count($requestIds),
            'appointment_count' => count($appointmentIds),
            'created_request_count' => count($createdRequests),
            'created_appointment_count' => count($createdAppointments),
            'request_ids' => $requestIds,
            'appointment_ids' => $appointmentIds,
            'created_request_ids' => $createdRequests,
            'created_appointment_ids' => $createdAppointments,
        ];
    }

    private function manifest(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        $manifest = is_array($stored) ? $stored : [];
        $anchor = (string) ($manifest['anchor_date'] ?? '');
        if (! $this->validDate($anchor)) {
            $anchor = current_datetime()->setTime(12, 0)->modify('next monday')->format('Y-m-d');
        }

        return [
            'version' => self::MANIFEST_VERSION,
            'seeded_at' => (string) ($manifest['seeded_at'] ?? current_time('mysql')),
            'anchor_date' => $anchor,
            'appointment_dates' => is_array($manifest['appointment_dates'] ?? null) ? $manifest['appointment_dates'] : [],
            'request_ids' => is_array($manifest['request_ids'] ?? null) ? $manifest['request_ids'] : [],
            'appointment_ids' => is_array($manifest['appointment_ids'] ?? null) ? $manifest['appointment_ids'] : [],
        ];
    }

    private function resolveAppointmentDates(array $manifest): array
    {
        $offsets = [
            'school_confirmed' => 2,
            'public_event_confirmed' => 7,
            'initiative_confirmed' => 12,
        ];
        $resolved = [];

        foreach ($offsets as $key => $offset) {
            $requestId = (int) ($manifest['request_ids'][$key] ?? 0);
            if ($requestId <= 0) {
                $requestId = $this->requestIdByMarker($key);
            }
            $existingDate = $requestId > 0 ? $this->appointmentDateForRequest($requestId) : '';
            if ($this->validDate($existingDate)) {
                $resolved[$key] = $existingDate;
                continue;
            }

            $candidate = (string) ($manifest['appointment_dates'][$key] ?? '');
            if (! $this->validDate($candidate)) {
                $candidate = $this->businessDate((string) $manifest['anchor_date'], $offset);
            }

            while ($this->appointmentDateOccupied($candidate) || in_array($candidate, $resolved, true)) {
                $candidate = $this->nextBusinessDay($candidate);
            }
            $resolved[$key] = $candidate;
        }

        return $resolved;
    }

    private function fixtures(array $manifest): array
    {
        $anchor = (string) $manifest['anchor_date'];
        $appointmentDates = $manifest['appointment_dates'];

        return [
            'school_new' => $this->fixture([
                'institution_name' => 'DEMO · Grundschule Am Neckar',
                'institution_type' => 'Grundschule',
                'contact_first_name' => 'Mara',
                'contact_last_name' => 'Beispiel',
                'contact_email' => 'demo+neckar@proocean.example',
                'contact_phone' => '07071 555 0101',
                'street' => 'Uhlandstraße',
                'house_number' => '10',
                'postal_code' => '72072',
                'city' => 'Tübingen',
                'state_code' => 'BW',
                'latitude' => 48.5216,
                'longitude' => 9.0576,
                'distance_km' => 12.4,
                'cost' => 10.54,
                'suggestion_distance_km' => 12.4,
                'suggestion_cost' => 10.54,
                'request_mode' => 'specific_date',
                'specific_requested_date' => $this->businessDate($anchor, 1),
                'classes' => [
                    ['class_name' => '4a', 'grade' => 4, 'participant_count' => 24],
                    ['class_name' => '4b', 'grade' => 4, 'participant_count' => 23],
                ],
                'parking_available' => 'yes',
                'indoor_room_available' => 'yes',
                'bad_weather_option_available' => 'unknown',
                'electricity_available' => 'yes',
                'water_available' => 'yes',
                'main_status' => RequestStatus::RECEIVED,
                'work_state' => WorkState::NEW,
                'internal_note' => 'Neue Schulanfrage mit zwei Klassen; kurze Anfahrt ab Depot Tübingen.',
            ]),
            'public_event_review' => $this->fixture([
                'institution_name' => 'DEMO · Regnitz-Familientag',
                'institution_type' => 'Öffentliche Veranstaltung',
                'contact_first_name' => 'Jonas',
                'contact_last_name' => 'Muster',
                'contact_email' => 'demo+mainufer@proocean.example',
                'contact_phone' => '0951 555 0202',
                'street' => 'Markusplatz',
                'house_number' => '1',
                'postal_code' => '96047',
                'city' => 'Bamberg',
                'state_code' => 'BY',
                'latitude' => 49.8988,
                'longitude' => 10.9028,
                'distance_km' => 384.0,
                'cost' => 326.40,
                'suggestion_distance_km' => 62.5,
                'suggestion_cost' => 53.13,
                'request_mode' => 'date_range',
                'desired_date_from' => $this->businessDate($anchor, 5),
                'desired_date_to' => $this->businessDate($anchor, 9),
                'possible_weekdays' => ['tue', 'wed', 'thu'],
                'classes' => [['class_name' => 'Offenes Familienprogramm', 'grade' => 4, 'participant_count' => 60]],
                'parking_available' => 'unknown',
                'indoor_room_available' => 'no',
                'bad_weather_option_available' => 'no',
                'electricity_available' => 'yes',
                'water_available' => 'unknown',
                'main_status' => RequestStatus::RECEIVED,
                'work_state' => WorkState::IN_REVIEW,
                'internal_note' => 'Öffentliche Veranstaltung; Zufahrt und Schlechtwetterkonzept müssen geprüft werden.',
            ]),
            'initiative_awaiting' => $this->fixture([
                'institution_name' => 'DEMO · Initiative StadtNatur Saarbrücken',
                'institution_type' => 'Verein / Initiative',
                'contact_first_name' => 'Aylin',
                'contact_last_name' => 'Demo',
                'contact_email' => 'demo+stadtnatur@proocean.example',
                'contact_phone' => '0681 555 0303',
                'street' => 'St. Johanner Markt',
                'house_number' => '1',
                'postal_code' => '66111',
                'city' => 'Saarbrücken',
                'state_code' => 'SL',
                'latitude' => 49.2402,
                'longitude' => 6.9969,
                'distance_km' => 526.0,
                'cost' => 447.10,
                'request_mode' => 'date_range',
                'desired_date_from' => $this->businessDate($anchor, 10),
                'desired_date_to' => $this->businessDate($anchor, 14),
                'possible_weekdays' => ['mon', 'wed', 'fri'],
                'classes' => [['class_name' => 'Jugendgruppe Meeresschutz', 'grade' => 6, 'participant_count' => 18]],
                'parking_available' => 'yes',
                'indoor_room_available' => 'yes',
                'bad_weather_option_available' => 'yes',
                'electricity_available' => 'yes',
                'water_available' => 'yes',
                'main_status' => RequestStatus::PROPOSAL_SENT,
                'work_state' => WorkState::AWAITING_RESPONSE,
                'internal_note' => 'Vorschlagsmail ist versendet; Rückmeldung der Initiative steht aus.',
            ]),
            'municipality_rejected' => $this->fixture([
                'institution_name' => 'DEMO · Klimabüro Münster',
                'institution_type' => 'Kommune / sonstiges',
                'contact_first_name' => 'Sven',
                'contact_last_name' => 'Platzhalter',
                'contact_email' => 'demo+kommune@proocean.example',
                'contact_phone' => '0251 555 0404',
                'street' => 'Klemensstraße',
                'house_number' => '10',
                'postal_code' => '48143',
                'city' => 'Münster',
                'state_code' => 'NW',
                'latitude' => 51.9607,
                'longitude' => 7.6261,
                'distance_km' => 1008.0,
                'cost' => 856.80,
                'request_mode' => 'specific_date',
                'specific_requested_date' => $this->businessDate($anchor, 16),
                'classes' => [['class_name' => 'Kommunaler Aktionstag', 'grade' => 5, 'participant_count' => 40]],
                'parking_available' => 'no',
                'indoor_room_available' => 'unknown',
                'bad_weather_option_available' => 'unknown',
                'electricity_available' => 'yes',
                'water_available' => 'yes',
                'main_status' => RequestStatus::RECEIVED,
                'work_state' => WorkState::REJECTED,
                'internal_note' => 'Beispiel für abgelehnte Anfrage: gewünschter Einzeltermin war logistisch nicht darstellbar.',
            ]),
            'school_confirmed' => $this->fixture([
                'institution_name' => 'DEMO · Gemeinschaftsschule Stuttgart-West',
                'institution_type' => 'Gemeinschaftsschule',
                'contact_first_name' => 'Nora',
                'contact_last_name' => 'Test',
                'contact_email' => 'demo+stuttgart@proocean.example',
                'contact_phone' => '0711 555 0505',
                'street' => 'Rotebühlstraße',
                'house_number' => '100',
                'postal_code' => '70178',
                'city' => 'Stuttgart',
                'state_code' => 'BW',
                'latitude' => 48.7758,
                'longitude' => 9.1829,
                'distance_km' => 104.0,
                'cost' => 88.40,
                'request_mode' => 'specific_date',
                'specific_requested_date' => $appointmentDates['school_confirmed'],
                'classes' => [['class_name' => '5a', 'grade' => 5, 'participant_count' => 27]],
                'main_status' => RequestStatus::CONFIRMED,
                'work_state' => WorkState::ACCEPTED,
                'internal_note' => 'Bestätigter Schultermin mit kurzer Direktfahrt.',
                'appointment' => [
                    'date' => $appointmentDates['school_confirmed'],
                    'public_city' => 'Stuttgart',
                    'route' => ['start_label' => 'Depot Tübingen', 'start_latitude' => 48.5216364, 'start_longitude' => 9.0576448, 'distance_km' => 104.0, 'cost' => 88.40],
                    'event_type' => EventType::SCHOOL,
                    'participants_children' => 27,
                    'participants_adults' => 3,
                ],
            ]),
            'public_event_confirmed' => $this->fixture([
                'institution_name' => 'DEMO · Zukunftsfest Nürnberg',
                'institution_type' => 'Öffentliche Veranstaltung',
                'contact_first_name' => 'David',
                'contact_last_name' => 'Beispiel',
                'contact_email' => 'demo+nuernberg@proocean.example',
                'contact_phone' => '0911 555 0606',
                'street' => 'Hauptmarkt',
                'house_number' => '18',
                'postal_code' => '90403',
                'city' => 'Nürnberg',
                'state_code' => 'BY',
                'latitude' => 49.4521,
                'longitude' => 11.0767,
                'distance_km' => 226.0,
                'cost' => 192.10,
                'request_mode' => 'specific_date',
                'specific_requested_date' => $appointmentDates['public_event_confirmed'],
                'classes' => [['class_name' => 'Offenes Bühnenprogramm', 'grade' => 4, 'participant_count' => 75]],
                'parking_available' => 'yes',
                'indoor_room_available' => 'no',
                'bad_weather_option_available' => 'yes',
                'electricity_available' => 'yes',
                'water_available' => 'unknown',
                'main_status' => RequestStatus::CONFIRMED,
                'work_state' => WorkState::ACCEPTED,
                'internal_note' => 'Bestätigter öffentlicher Termin als Anschlussfahrt ab Würzburg.',
                'appointment' => [
                    'date' => $appointmentDates['public_event_confirmed'],
                    'public_city' => 'Nürnberg',
                    'route' => ['start_label' => 'Tourstopp Würzburg', 'start_latitude' => 49.7913, 'start_longitude' => 9.9534, 'distance_km' => 226.0, 'cost' => 192.10],
                    'event_type' => EventType::EVENT,
                    'participants_children' => 46,
                    'participants_adults' => 34,
                ],
            ]),
            'initiative_confirmed' => $this->fixture([
                'institution_name' => 'DEMO · Moselinitiative Trier',
                'institution_type' => 'Verein / Initiative',
                'contact_first_name' => 'Leonie',
                'contact_last_name' => 'Musterfrau',
                'contact_email' => 'demo+trier@proocean.example',
                'contact_phone' => '0651 555 0707',
                'street' => 'Viehmarktplatz',
                'house_number' => '20',
                'postal_code' => '54290',
                'city' => 'Trier',
                'state_code' => 'RP',
                'latitude' => 49.7499,
                'longitude' => 6.6371,
                'distance_km' => 258.0,
                'cost' => 219.30,
                'request_mode' => 'specific_date',
                'specific_requested_date' => $appointmentDates['initiative_confirmed'],
                'classes' => [['class_name' => 'Feriengruppe Mosel', 'grade' => 5, 'participant_count' => 21]],
                'main_status' => RequestStatus::CONFIRMED,
                'work_state' => WorkState::ACCEPTED,
                'internal_note' => 'Bestätigter Vereinstermin als Anschlussfahrt ab Koblenz.',
                'appointment' => [
                    'date' => $appointmentDates['initiative_confirmed'],
                    'public_city' => 'Trier',
                    'route' => ['start_label' => 'Tourstopp Koblenz', 'start_latitude' => 50.3569, 'start_longitude' => 7.5889, 'distance_km' => 258.0, 'cost' => 219.30],
                    'event_type' => EventType::OTHER,
                    'participants_children' => 18,
                    'participants_adults' => 4,
                ],
            ]),
        ];
    }

    private function fixture(array $data): array
    {
        $participantTotal = array_sum(array_map(
            static fn (array $class): int => (int) ($class['participant_count'] ?? 0),
            (array) ($data['classes'] ?? [])
        ));
        $payload = [
            'request_mode' => $data['request_mode'],
            'specific_requested_date' => $data['specific_requested_date'] ?? null,
            'desired_date_from' => $data['desired_date_from'] ?? null,
            'desired_date_to' => $data['desired_date_to'] ?? null,
            'possible_weekdays' => $data['possible_weekdays'] ?? ['mon', 'tue', 'wed', 'thu', 'fri'],
            'institution_name' => $data['institution_name'],
            'institution_type' => $data['institution_type'],
            'children_count' => (int) ($data['children_count'] ?? $participantTotal),
            'adult_count' => (int) ($data['adult_count'] ?? 0),
            'participant_total' => (int) ($data['children_count'] ?? $participantTotal) + (int) ($data['adult_count'] ?? 0),
            'contact_first_name' => $data['contact_first_name'],
            'contact_last_name' => $data['contact_last_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'],
            'street' => $data['street'],
            'house_number' => $data['house_number'],
            'postal_code' => $data['postal_code'],
            'city' => $data['city'],
            'state_code' => $data['state_code'],
            '_server_latitude' => $data['latitude'],
            '_server_longitude' => $data['longitude'],
            '_server_route_distance_km' => $data['distance_km'],
            '_server_route_cost' => $data['cost'],
            'classes' => $data['classes'],
            'parking_available' => $data['parking_available'] ?? 'yes',
            'indoor_room_available' => $data['indoor_room_available'] ?? 'yes',
            'bad_weather_option_available' => $data['bad_weather_option_available'] ?? 'yes',
            'electricity_available' => $data['electricity_available'] ?? 'yes',
            'electricity_outdoor_available' => $data['electricity_outdoor_available'] ?? 'yes',
            'electricity_charging_available' => $data['electricity_charging_available'] ?? 'yes',
            'water_available' => $data['water_available'] ?? 'yes',
            'changing_room_available' => $data['changing_room_available'] ?? 'yes',
            'shower_available' => $data['shower_available'] ?? 'yes',
            'natural_water_nearby' => $data['natural_water_nearby'] ?? 'no',
            'presentation_equipment' => $data['presentation_equipment'] ?? ['projector', 'digital_display'],
            'presentation_equipment_other' => $data['presentation_equipment_other'] ?? '',
            'laptop_connections' => $data['laptop_connections'] ?? ['usb_c', 'usb_a'],
            'laptop_connection_other' => $data['laptop_connection_other'] ?? '',
            'wifi_available' => $data['wifi_available'] ?? 'yes',
            'accessibility_notes' => $data['accessibility_notes'] ?? 'Demo-Datensatz für die Teamansicht.',
            'group_notes' => $data['group_notes'] ?? '',
            'general_notes' => 'Temporäre Demo-Daten – nicht für den Produktivbetrieb.',
            'privacy_consent' => 1,
        ];

        return [
            'payload' => $payload,
            'main_status' => $data['main_status'],
            'work_state' => $data['work_state'],
            'internal_note' => $data['internal_note'],
            'appointment' => $data['appointment'] ?? null,
            'suggestion_distance_km' => $data['suggestion_distance_km'] ?? null,
            'suggestion_cost' => $data['suggestion_cost'] ?? null,
        ];
    }

    private function workflowTimestamps(array $fixture, array $manifest): array
    {
        $seededAt = (string) $manifest['seeded_at'];
        $extra = [];
        if ($fixture['main_status'] === RequestStatus::PROPOSAL_SENT) {
            $extra['proposal_sent_at'] = $seededAt;
        }
        if ($fixture['main_status'] === RequestStatus::CONFIRMED) {
            $extra['proposal_sent_at'] = $seededAt;
            $extra['confirmed_at'] = $seededAt;
        }
        if (in_array($fixture['work_state'], [WorkState::REJECTED, WorkState::CANCELLED], true)) {
            $extra['closed_at'] = $seededAt;
        }
        return $extra;
    }

    private function findRequestId(string $key, array $fixture, array $manifest): int
    {
        global $wpdb;

        $manifestId = (int) ($manifest['request_ids'][$key] ?? 0);
        if ($manifestId > 0) {
            $stored = (new RequestRepository())->find($manifestId);
            if ($stored && ((string) $stored['institution_name'] === (string) $fixture['payload']['institution_name'] || str_starts_with((string) $stored['internal_note'], $this->marker($key)))) {
                return $manifestId;
            }
        }

        $table = $wpdb->prefix . 'pov_requests';
        $byMarker = $this->requestIdByMarker($key);
        if ($byMarker > 0) {
            return $byMarker;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE institution_name = %s ORDER BY id ASC LIMIT 1",
            (string) $fixture['payload']['institution_name']
        ));
    }

    private function requestIdByMarker(string $key): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}pov_requests WHERE internal_note LIKE %s ORDER BY id ASC LIMIT 1",
            $wpdb->esc_like($this->marker($key)) . '%'
        ));
    }

    private function synchronizeRequestProfile(int $requestId, array $payload, string $key): void
    {
        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . 'pov_requests', [
            'institution_name' => (string) $payload['institution_name'],
            'institution_type' => (string) $payload['institution_type'],
            'children_count' => (int) $payload['children_count'],
            'adult_count' => (int) $payload['adult_count'],
            'participant_total' => (int) $payload['participant_total'],
            'contact_first_name' => (string) $payload['contact_first_name'],
            'contact_last_name' => (string) $payload['contact_last_name'],
            'contact_email' => (string) $payload['contact_email'],
            'contact_phone' => (string) $payload['contact_phone'],
            'street' => (string) $payload['street'],
            'house_number' => (string) $payload['house_number'],
            'postal_code' => (string) $payload['postal_code'],
            'city' => (string) $payload['city'],
            'state_code' => (string) $payload['state_code'],
            'updated_at' => current_time('mysql'),
        ], ['id' => $requestId]);

        if ($updated === false) {
            throw new RuntimeException('Demo-Anfrageprofil konnte nicht aktualisiert werden: ' . $key . '. ' . (string) $wpdb->last_error);
        }
    }

    private function synchronizeSuggestions(int $requestId, array $fixture): void
    {
        $payload = $fixture['payload'];
        $dates = [];
        if ($payload['request_mode'] === 'specific_date') {
            $dates[] = (string) $payload['specific_requested_date'];
        } else {
            $allowed = (array) $payload['possible_weekdays'];
            $period = new \DatePeriod(
                new DateTimeImmutable((string) $payload['desired_date_from']),
                new \DateInterval('P1D'),
                (new DateTimeImmutable((string) $payload['desired_date_to']))->modify('+1 day')
            );
            foreach ($period as $day) {
                if (in_array(strtolower($day->format('D')), $allowed, true)
                    && ! (new AppointmentRepository())->existsOnDate($day->format('Y-m-d'))) {
                    $dates[] = $day->format('Y-m-d');
                }
                if (count($dates) >= 2) {
                    break;
                }
            }
        }

        $distance = $fixture['suggestion_distance_km'];
        $cost = $fixture['suggestion_cost'];
        $suggestions = [];
        foreach (array_slice($dates, 0, 2) as $index => $date) {
            $suggestions[] = [
                'date' => $date,
                'type' => 'demo_route_insertion',
                'score' => $index + 1,
                'distance_km' => $distance,
                'cost' => $cost,
                'reason_codes' => ['DEMO_ROUTE'],
                'reason_summary' => 'Vorbereiteter Demo-Routenwert.',
            ];
        }
        (new SuggestionRepository())->replaceGenerated($requestId, $suggestions);
    }

    private function synchronizeRequestSchedule(int $requestId, array $payload, string $key): void
    {
        global $wpdb;
        $mode = $payload['request_mode'] === 'specific_date' ? 'specific_date' : 'date_range';
        $updated = $wpdb->update($wpdb->prefix . 'pov_requests', [
            'request_mode' => $mode,
            'specific_requested_date' => $mode === 'specific_date' ? $payload['specific_requested_date'] : null,
            'desired_date_from' => $mode === 'date_range' ? $payload['desired_date_from'] : null,
            'desired_date_to' => $mode === 'date_range' ? $payload['desired_date_to'] : null,
            'possible_weekdays' => implode(',', array_map('sanitize_key', (array) $payload['possible_weekdays'])),
            'updated_at' => current_time('mysql'),
        ], ['id' => $requestId]);

        if ($updated === false) {
            throw new RuntimeException('Demo-Anfragezeitraum konnte nicht aktualisiert werden: ' . $key . '. ' . (string) $wpdb->last_error);
        }
    }

    private function findAppointmentId(int $requestId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}pov_appointments WHERE request_id = %d ORDER BY id ASC LIMIT 1",
            $requestId
        ));
    }

    private function appointmentDateForRequest(int $requestId): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT appointment_date FROM {$wpdb->prefix}pov_appointments WHERE request_id = %d ORDER BY id ASC LIMIT 1",
            $requestId
        ));
    }

    private function appointmentDateOccupied(string $date): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pov_appointments WHERE status = 'confirmed' AND appointment_date = %s",
            $date
        )) > 0;
    }

    private function synchronizeAppointment(int $appointmentId, array $request, array $appointment, string $key): void
    {
        global $wpdb;
        $route = $appointment['route'];
        $updated = $wpdb->update($wpdb->prefix . 'pov_appointments', [
            'appointment_date' => $appointment['date'],
            'status' => 'confirmed',
            'public_city' => sanitize_text_field((string) $appointment['public_city']),
            'latitude' => is_numeric($request['latitude'] ?? null) ? (float) $request['latitude'] : null,
            'longitude' => is_numeric($request['longitude'] ?? null) ? (float) $request['longitude'] : null,
            'start_label' => sanitize_text_field((string) $route['start_label']),
            'start_latitude' => (float) $route['start_latitude'],
            'start_longitude' => (float) $route['start_longitude'],
            'route_distance_km' => (float) $route['distance_km'],
            'route_cost' => (float) $route['cost'],
            'event_type' => (string) ($appointment['event_type'] ?? EventType::normalize((string) $request['institution_type'])),
            'participants_children' => isset($appointment['participants_children']) ? (int) $appointment['participants_children'] : null,
            'participants_adults' => isset($appointment['participants_adults']) ? (int) $appointment['participants_adults'] : null,
            'metrics_recorded_at' => isset($appointment['participants_children']) || isset($appointment['participants_adults']) ? current_time('mysql') : null,
            'internal_notes' => $this->marker($key) . ' Bestätigter Demo-Termin.',
            'updated_at' => current_time('mysql'),
        ], ['id' => $appointmentId]);

        if ($updated === false) {
            throw new RuntimeException('Demo-Termin konnte nicht aktualisiert werden: ' . $key . '. ' . (string) $wpdb->last_error);
        }
    }

    private function businessDate(string $anchor, int $offset): string
    {
        $date = new DateTimeImmutable($anchor . ' 12:00:00', wp_timezone());
        $remaining = max(0, $offset);
        while ($remaining > 0) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') <= 5) {
                $remaining--;
            }
        }
        return $date->format('Y-m-d');
    }

    private function nextBusinessDay(string $date): string
    {
        $next = new DateTimeImmutable($date . ' 12:00:00', wp_timezone());
        do {
            $next = $next->modify('+1 day');
        } while ((int) $next->format('N') >= 6);
        return $next->format('Y-m-d');
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private function marker(string $key): string
    {
        return self::MARKER_PREFIX . $key;
    }

    private function assertRequiredTables(): void
    {
        global $wpdb;
        foreach (['pov_requests', 'pov_request_classes', 'pov_appointments'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                throw new RuntimeException('Erforderliche Tabelle fehlt: ' . $table . '. Bitte das Plugin zuerst aktivieren.');
            }
        }
    }
}
