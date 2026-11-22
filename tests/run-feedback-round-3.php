<?php

declare(strict_types=1);

use ProOceanVan\Domain\SchoolClasses;
use ProOceanVan\Rest\RequestController;
use ProOceanVan\Service\AvailabilityService;
use ProOceanVan\Service\MailService;

const ARRAY_A = 'ARRAY_A';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ProOceanVan\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
    }
});

function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Berlin'); }
function is_email(string $value): bool { return (bool) filter_var($value, FILTER_VALIDATE_EMAIL); }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $value)); }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['povOptions'][$key] ?? $default; }

function expect_feedback(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "OK: {$message}\n";
}

$wpdb = new class {
    public string $prefix = 'wp_';
    public array $calendar = [];
    public array $appointments = [];

    public function prepare(string $sql, string ...$values): array
    {
        return [$sql, ...$values];
    }

    public function get_var(array $query): int
    {
        return $query[1] === 'BW' ? 1 : 0;
    }

    public function get_results(array $query, string $output): array
    {
        [$sql, $from, $to] = $query;
        $isCalendar = str_contains($sql, 'pov_calendar_days');
        $key = $isCalendar ? 'calendar_date' : 'appointment_date';
        return array_filter($isCalendar ? $this->calendar : $this->appointments, static fn (array $day): bool => $day[$key] >= $from && $day[$key] <= $to);
    }
};

$monday = new DateTimeImmutable('monday next week', wp_timezone());
$date = static fn (int $offset): string => $monday->modify("+{$offset} days")->format('Y-m-d');
for ($day = 0; $day < 5; $day++) {
    $wpdb->calendar[] = ['calendar_date' => $date($day), 'availability_state' => 'unavailable'];
}
$availability = new AvailabilityService();
expect_feedback($availability->requestableDates('BW', $date(0), $date(6), ['mon', 'sat', 'sun']) === [], 'gesperrte Woche öffnet keine Wochenendtage automatisch');
$wpdb->calendar[] = ['calendar_date' => $date(5), 'availability_state' => 'limited'];
expect_feedback($availability->requestableDates('BW', $date(5), $date(6), ['sat', 'sun']) === [$date(5)], 'ausdrücklich anfragbares Wochenende ist auswählbar');
$wpdb->calendar[2]['availability_state'] = 'available';
expect_feedback($availability->requestableDates('BW', $date(0), $date(6), ['mon', 'wed']) === [$date(2)], 'freie Werktage entsprechen der Wochentagspräferenz');
$wpdb->appointments = [['appointment_date' => $date(2)]];
expect_feedback($availability->requestableDates('BW', $date(2), $date(2), ['wed']) === [], 'bestätigter Termin blockiert weitere Anfragen');
$wpdb->appointments = [];
$povOptions['pov_state_windows'] = [['from' => $date(0), 'to' => $date(6), 'states' => ['BY']]];
expect_feedback($availability->requestableDates('BW', $date(2), $date(2), ['wed']) === [], 'saisonale Regionsfreigabe gilt auch bei freier Kachel');
$povOptions['pov_state_windows'] = [];
$povOptions['pov_school_grade_options'] = '4,11';

$classes = SchoolClasses::normalize([
    ['grade' => 4, 'children' => 24, 'teachers' => 1, 'adults' => 0],
    ['grade' => 11, 'children' => 18, 'teachers' => 0, 'adults' => 0],
], SchoolClasses::configuredGrades());
expect_feedback(count($classes) === 2 && $classes[1]['grade'] === 11 && array_sum(array_column($classes, 'participant_count')) === 43, 'zwei unterschiedliche Klassen und genau eine erwachsene Person sind gültig');

$payload = [
    'privacy_consent' => 1, 'institution_name' => 'Demo-Schule', 'institution_type' => 'Schule',
    'contact_role' => 'Lehrkraft', 'contact_first_name' => 'Alex', 'contact_last_name' => 'Demo',
    'contact_phone' => '0123456', 'contact_email' => 'test@example.test', 'street' => 'Teststraße',
    'house_number' => '1', 'city' => 'Stuttgart', 'postal_code' => '70180', 'state_code' => 'BW',
    'children_count' => 42, 'adult_count' => 1, 'classes' => $classes, 'school_class_count' => 2,
    'school_lesson_duration' => '45', 'school_schedule_notes' => 'Wird nachgereicht',
    'availability_window' => 'morning', 'venue_type' => 'indoor', 'parking_type' => 'schoolyard',
    'request_mode' => 'date_range', 'desired_date_from' => $date(2), 'desired_date_to' => $date(2),
    'possible_weekdays' => ['wed'], 'public_uuid' => 'OV-TEST',
];
$controller = new RequestController();
$validate = new ReflectionMethod($controller, 'validate');
expect_feedback($validate->invoke($controller, $payload) === '', 'das Anfrageformular akzeptiert die neue Klassenstruktur');
expect_feedback($validate->invoke($controller, array_replace($payload, ['possible_weekdays' => ['tue']])) !== '', 'das Absenden weist einen Zeitraum ohne anfragbaren Wunschtag ab');
expect_feedback($validate->invoke($controller, array_replace($payload, ['school_schedule_notes' => ''])) !== '', 'Unterrichts- und Pausenzeiten sind bei einer neuen Schulanfrage Pflicht');
expect_feedback($validate->invoke($controller, array_replace($payload, [
    'institution_type' => 'Unternehmen', 'occasion_description' => 'Aktionstag',
    'classes' => [['class_name' => 'Gruppe', 'grade' => 1, 'participant_count' => 43]],
])) === '', 'Unternehmen ist als Veranstaltungsart anfragbar');
$payload['possible_weekdays'] = 'wed';
$mail = (new MailService())->render('{Termin oder Zeitraum}' . "\n" . '{Buchungsübersicht}', $payload, []);
expect_feedback(str_contains($mail, 'Mittwoch') && str_contains($mail, '42 Kinder, 1 erwachsene Person') && str_contains($mail, 'Klasse 2 (11. Stufe)') && str_contains($mail, 'Teststraße 1'), 'Bestätigung enthält deutsches Datum, Adresse und Klassenaufteilung');
