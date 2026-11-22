<?php

declare(strict_types=1);

use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Service\AppointmentDates;
use ProOceanVan\Service\AppointmentScheduleService;
use ProOceanVan\Service\AvailabilityService;
use ProOceanVan\Service\GermanDateFormatter;
use ProOceanVan\Service\MailService;

const ARRAY_A = 'ARRAY_A';
spl_autoload_register(static function (string $class): void {
    require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
});

function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Berlin'); }
function current_time(string $format): string { return (new DateTimeImmutable('now', wp_timezone()))->format($format === 'mysql' ? 'Y-m-d H:i:s' : $format); }
function get_option(string $key, mixed $default = false): mixed { return $default; }
function sanitize_key(string $v): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $v)); }
function sanitize_text_field(string $v): string { return trim(strip_tags($v)); }
function sanitize_textarea_field(string $v): string { return sanitize_text_field($v); }
function remove_accents(string $value): string { return $value; }
function is_email(string $v): bool { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function absint(mixed $value): int { return abs((int) $value); }
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function wp_get_current_user(): object { return (object) ['display_name' => 'Test']; }
function get_current_user_id(): int { return 1; }
function add_filter(string $name, callable $filter): void {}
function remove_filter(string $name, callable $filter): void {}
function wp_mail(string $recipient, string $subject, string $body, array $headers, array $attachments): bool
{
    $GLOBALS['testMails'][] = [$recipient, $subject, $body];
    return true;
}

function expectSchedule(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "OK: {$message}\n";
}

$wpdb = new class {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    public array $calendar = [];
    public array $locks = [];
    public array $tables = ['wp_pov_requests' => [], 'wp_pov_appointments' => [], 'wp_pov_communications' => []];
    public string $failOnDate = '';
    private ?array $snapshot = null;

    public function prepare(string $sql, mixed ...$args): array { return [$sql, ...$args]; }

    public function get_results(array $query, string $output): array
    {
        [$sql, $first] = $query;
        if (str_contains($sql, 'pov_calendar_days')) {
            return array_filter($this->calendar, static fn ($row) => $row['calendar_date'] >= $first && $row['calendar_date'] <= $query[2]);
        }
        if (str_contains($sql, 'pov_appointments')) {
            $rows = $this->tables['wp_pov_appointments'];
            $rows = array_filter($rows, static fn ($row) => (! str_contains($sql, "status = 'confirmed'") || $row['status'] === 'confirmed')
                && (str_contains($sql, 'BETWEEN')
                    ? $row['appointment_date'] >= $first && $row['appointment_date'] <= $query[2]
                    : $row['request_id'] === $first));
            usort($rows, static fn ($a, $b) => $a['appointment_date'] <=> $b['appointment_date']);
            return $rows;
        }
        return [];
    }

    public function get_row(array $query, string $output): ?array
    {
        $table = str_contains($query[0], 'pov_requests') ? 'wp_pov_requests' : 'wp_pov_appointments';
        return $this->tables[$table][$query[1]] ?? null;
    }

    public function get_var(array $query): int
    {
        [$sql, $value] = $query;
        if (str_contains($sql, 'GET_LOCK')) {
            if (isset($this->locks[$value])) throw new RuntimeException('Lock reentry must be tracked by the repository.');
            $this->locks[$value] = true;
            return 1;
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            if (! isset($this->locks[$value])) throw new RuntimeException('Missing lock.');
            unset($this->locks[$value]);
            return 1;
        }
        if (str_contains($sql, 'pov_appointments')) {
            $column = str_contains($sql, 'appointment_date =') ? 'appointment_date' : 'request_id';
            return count(array_filter($this->tables['wp_pov_appointments'], static fn ($row) => $row[$column] === $value
                && $row['id'] !== $query[2] && $row['status'] === 'confirmed'));
        }
        return 0;
    }

    public function query(string $sql): int
    {
        if ($sql === 'START TRANSACTION') $this->snapshot = $this->tables;
        if ($sql === 'ROLLBACK') $this->tables = $this->snapshot;
        if ($sql !== 'START TRANSACTION') $this->snapshot = null;
        return 1;
    }

    public function insert(string $table, array $data): int|false
    {
        if (($data['appointment_date'] ?? null) === $this->failOnDate) return false;
        $id = ++$this->insert_id;
        $this->tables[$table][$id] = ['id' => $id] + $data;
        return 1;
    }

    public function update(string $table, array $data, array $where): int
    {
        $this->tables[$table][$where['id']] = array_replace($this->tables[$table][$where['id']], $data);
        return 1;
    }
};

$monday = new DateTimeImmutable('monday next week', wp_timezone());
$day = static fn (int $offset): string => $monday->modify("+{$offset} days")->format('Y-m-d');
$template = [
    'institution_name' => 'Testschule', 'institution_type' => 'Schule',
    'contact_first_name' => 'Alex', 'contact_last_name' => 'Test', 'contact_email' => 'alex@example.test',
    'contact_phone' => '012345678', 'street' => 'Testweg', 'house_number' => '1', 'postal_code' => '70180',
    'city' => 'Stuttgart', 'state_code' => 'BW', 'latitude' => null, 'longitude' => null,
    'request_mode' => 'date_range', 'desired_date_from' => $day(0), 'desired_date_to' => $day(11),
    'possible_weekdays' => 'mon,tue,wed,thu,fri', 'main_status' => RequestStatus::RECEIVED,
    'work_state' => WorkState::NEW,
];
foreach ([100, 101] as $id) $wpdb->tables['wp_pov_requests'][$id] = ['id' => $id] + $template;
$repo = new AppointmentRepository();
$service = new AppointmentScheduleService();
$range = ['schedule_mode' => 'range', 'date_from' => $day(0), 'date_to' => $day(2)];
expectSchedule(AppointmentDates::fromSelection($range) === [$day(0), $day(1), $day(2)], 'Zeitraum schließt Anfang und Ende ein');
expectSchedule(AppointmentDates::fromSelection(['dates' => [$day(2), $day(0), $day(2)]]) === [$day(0), $day(2)], 'Einzeltage sortiert und dedupliziert');
expectSchedule(AppointmentDates::fromSelection(['date' => '2026-02-31']) === [], 'ungültiges Kalenderdatum abgewiesen');

$wpdb->calendar[] = ['calendar_date' => $day(1), 'availability_state' => 'unavailable'];
expectSchedule($service->confirm(100, $range) === ['notice' => 'schedule-conflict', 'date' => $day(1)] && ! $repo->forRequest(100), 'gesperrter Tag blockiert gesamte Zusage');
$wpdb->calendar = [];
$wpdb->failOnDate = $day(1);
expectSchedule(isset($service->confirm(100, $range)['notice']) && ! $repo->forRequest(100)
    && $wpdb->tables['wp_pov_requests'][100]['work_state'] === WorkState::NEW && ! $wpdb->locks, 'Datenbankfehler rollt alle bisherigen Änderungen zurück und gibt Sperren frei');
$wpdb->failOnDate = '';
$result = $service->confirm(100, $range);
$days = [$day(0), $day(1), $day(2)];
expectSchedule(array_column($result['appointments'], 'appointment_date') === $days
    && $wpdb->tables['wp_pov_requests'][100]['work_state'] === WorkState::ACCEPTED, 'drei Kalendereinträge gehören zur selben bestätigten Anfrage');
expectSchedule(array_column((new AvailabilityService())->publicCalendar($day(0), $day(2)), 'public_city') === ['Stuttgart', 'Stuttgart', 'Stuttgart'], 'alle Tage stehen im öffentlichen Kalender');
expectSchedule($service->confirm(100, $range) === ['notice' => 'schedule-already-confirmed'], 'erneutes Speichern erzeugt keine Doppelbuchung');
expectSchedule(($service->confirm(101, ['date' => $day(2)])['date'] ?? '') === $day(2), 'andere Anfragen können keinen belegten Tag bestätigen');
expectSchedule(($service->confirm(101, ['date' => $day(14)])['date'] ?? '') === $day(14)
    && count($service->confirm(101, ['date' => $day(14), 'override_request_dates' => '1'])['appointments'] ?? []) === 1,
    'nur ausdrücklich abgesprochene Termine dürfen vom Wunsch abweichen');
$result = $service->confirm(100, ['dates' => [$day(4), $day(7)]]);
expectSchedule(count($result['appointments']) === 5, 'weitere getrennte Tage ergänzen dieselbe Anfrage');
expectSchedule($service->confirm(100, ['schedule_mode' => 'existing']) === $result, 'erneute Zusage verwendet die unveränderten Buchungstage');
$confirmedDates = array_column($result['appointments'], 'appointment_date');
expectSchedule((new MailService())->sendResponse($template + ['id' => 100], 'accept', 'hier ist eure Zusage.', $confirmedDates)
    && count($testMails) === 1 && array_reduce($confirmedDates, static fn ($ok, $date) => $ok && str_contains($testMails[0][2], GermanDateFormatter::full($date)), true),
    'eine Zusage nennt alle bestätigten Tage');

$ids = array_map('intval', array_column($result['appointments'], 'id'));
expectSchedule(! $service->changeDay(100, $ids[0], 'move', $day(3), '') && count($repo->forRequest(100, true)) === 5, 'Verschieben erhält die übrigen Tage');
expectSchedule(! $service->changeDay(100, $ids[1], 'cancel', '', 'Test') && count($repo->forRequest(100, true)) === 4
    && $wpdb->tables['wp_pov_requests'][100]['work_state'] === WorkState::ACCEPTED, 'ein abgesagter Tag lässt die Anfrage bestätigt');
foreach ($repo->forRequest(100, true) as $row) $service->changeDay(100, $row['id'], 'cancel', '', '');
expectSchedule(! $repo->forRequest(100, true) && $wpdb->tables['wp_pov_requests'][100]['work_state'] === WorkState::CANCELLED, 'erst ohne Einsatztage ist die Anfrage abgesagt');
expectSchedule(! $service->changeDay(100, $ids[1], 'restore', $day(1), '') && count($repo->forRequest(100, true)) === 1
    && $wpdb->tables['wp_pov_requests'][100]['work_state'] === WorkState::ACCEPTED && ! $wpdb->locks, 'Reaktivierung stellt Anfrage und Kalendereintrag wieder her');
