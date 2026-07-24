<?php

declare(strict_types=1);

$testOptions = [
    'pov_average_driving_speed_kmh' => 70,
    'pov_cluster_radius_km' => 120,
    'pov_default_visit_hours' => 6,
    'pov_kilometer_rate' => 0.85,
    'pov_personnel_count' => 2,
    'pov_personnel_hourly_rate' => 30,
];

function get_option(string $name, mixed $default = false): mixed
{
    global $testOptions;
    return $testOptions[$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    global $testOptions;
    $testOptions[$name] = $value;
    return true;
}

function remove_accents(string $value): string
{
    return $value;
}

function sanitize_key(string $value): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value));
}

function wp_json_encode(mixed $value): string|false
{
    return json_encode($value);
}

require_once dirname(__DIR__) . '/src/Domain/EventType.php';
require_once dirname(__DIR__) . '/src/Domain/WorkState.php';
require_once dirname(__DIR__) . '/src/Routing/RoutingProviderInterface.php';
require_once dirname(__DIR__) . '/src/Routing/GeocodingProviderInterface.php';
require_once dirname(__DIR__) . '/src/Routing/RouteResult.php';
require_once dirname(__DIR__) . '/src/Routing/NullRoutingProvider.php';
require_once dirname(__DIR__) . '/src/Service/CostService.php';
require_once dirname(__DIR__) . '/src/Service/GermanDateFormatter.php';
require_once dirname(__DIR__) . '/src/Service/RouteOptimizationService.php';
require_once dirname(__DIR__) . '/src/Service/WeeklyClusterService.php';
require_once dirname(__DIR__) . '/src/Service/StatisticsService.php';

use ProOceanVan\Routing\RouteResult;
use ProOceanVan\Routing\RoutingProviderInterface;
use ProOceanVan\Service\RouteOptimizationService;
use ProOceanVan\Service\StatisticsService;
use ProOceanVan\Service\WeeklyClusterService;

final class CountingRoutingProvider implements RoutingProviderInterface
{
    public int $matrixCalls = 0;

    public function calculateRoute(array $coordinates): RouteResult
    {
        return RouteResult::failure('Offline test');
    }

    public function calculateMatrix(array $sources, array $destinations): array
    {
        $this->matrixCalls++;
        $distances = [];
        $durations = [];
        foreach ($sources as $source) {
            $distanceRow = [];
            $durationRow = [];
            foreach ($destinations as $destination) {
                $samePoint = abs((float) $source['lat'] - (float) $destination['lat']) < 0.000001
                    && abs((float) $source['lon'] - (float) $destination['lon']) < 0.000001;
                $distanceRow[] = $samePoint ? 0.0 : 50.0;
                $durationRow[] = $samePoint ? 0.0 : 45.0;
            }
            $distances[] = $distanceRow;
            $durations[] = $durationRow;
        }
        return ['ok' => true, 'distances' => $distances, 'durations' => $durations, 'error' => ''];
    }
}

function expect_true(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "OK: {$message}\n";
}

function expect_close(float $actual, float $expected, string $message, float $tolerance = 0.01): void
{
    expect_true(abs($actual - $expected) <= $tolerance, $message . " ({$actual} ≈ {$expected})");
}

$depot = [
    'start_label' => 'Tübingen',
    'start_latitude' => 48.5216,
    'start_longitude' => 9.0576,
];
$appointments = [
    array_merge($depot, [
        'id' => 1,
        'request_id' => 101,
        'status' => 'confirmed',
        'appointment_date' => '2026-08-03',
        'institution_name' => 'Schule A',
        'event_type' => 'school',
        'city' => 'Reutlingen',
        'public_city' => 'Reutlingen',
        'state_code' => 'BW',
        'latitude' => 48.4914,
        'longitude' => 9.2043,
        'route_distance_km' => 600,
        'route_cost' => 510,
        'participants_children' => 40,
        'participants_adults' => 4,
    ]),
    array_merge($depot, [
        'id' => 2,
        'request_id' => 102,
        'status' => 'confirmed',
        'appointment_date' => '2026-08-04',
        'institution_name' => 'Schule B',
        'event_type' => 'school',
        'city' => 'Metzingen',
        'public_city' => 'Metzingen',
        'state_code' => 'BW',
        'latitude' => 48.5369,
        'longitude' => 9.2835,
        'route_distance_km' => 600,
        'route_cost' => 510,
        'participants_children' => 35,
        'participants_adults' => 3,
    ]),
    array_merge($depot, [
        'id' => 3,
        'request_id' => 103,
        'status' => 'confirmed',
        'appointment_date' => '2026-08-05',
        'institution_name' => 'Veranstaltung C',
        'event_type' => 'event',
        'city' => 'Nürtingen',
        'public_city' => 'Nürtingen',
        'state_code' => 'BW',
        'latitude' => 48.6257,
        'longitude' => 9.3420,
        'route_distance_km' => 600,
        'route_cost' => 510,
        'participants_children' => 20,
        'participants_adults' => 25,
    ]),
    array_merge($depot, [
        'id' => 4,
        'request_id' => 104,
        'status' => 'cancelled',
        'appointment_date' => '2026-08-06',
        'institution_name' => 'Abgesagter Termin',
        'event_type' => 'event',
        'city' => 'Stuttgart',
        'public_city' => 'Stuttgart',
        'state_code' => 'BW',
        'latitude' => 48.7758,
        'longitude' => 9.1829,
        'route_distance_km' => 600,
        'route_cost' => 510,
        'participants_children' => 999,
        'participants_adults' => 999,
    ]),
];

$routing = new CountingRoutingProvider();
$weeklyPlanning = new WeeklyClusterService(new RouteOptimizationService($routing));
$confirmedTours = $weeklyPlanning->confirmedClusters($appointments);
expect_true(count($confirmedTours) === 1, 'nearby confirmed stops form one weekly regional tour');
expect_true($confirmedTours[0]['confirmed_count'] === 3, 'cancelled appointments are excluded from the confirmed tour');
expect_true(
    $confirmedTours[0]['route_distance_km'] < $confirmedTours[0]['standalone_distance_km'],
    'the confirmed tour returns to the depot only once'
);

$statistics = new StatisticsService(
    static fn (): array => $appointments,
    static fn (): array => [],
    static fn (): array => [[
        'expense_date' => '2026-08-04',
        'amount' => 50,
    ]],
    static fn (array $items): array => $weeklyPlanning->confirmedClusters($items)
);
$report = $statistics->report('week');
$row = $report['rows'][0] ?? [];
$tour = $confirmedTours[0];
$visitPersonnelCost = 3 * 6 * 30 * 2;
$travelPersonnelCost = ((float) $tour['route_duration_minutes'] / 60) * 30 * 2;
$expectedPersonnelCost = round($visitPersonnelCost + $travelPersonnelCost, 2);
$expectedTotalCost = round((float) $tour['estimated_cost'] + $expectedPersonnelCost + 50, 2);

expect_true((int) ($row['appointments'] ?? 0) === 3, 'statistics count each confirmed visit exactly once');
expect_close((float) ($row['distance_km'] ?? -1), (float) $tour['route_distance_km'], 'statistics use the optimized tour distance once');
expect_close((float) ($row['travel_minutes'] ?? -1), (float) $tour['route_duration_minutes'], 'statistics use the optimized tour driving time once');
expect_close((float) ($row['route_cost'] ?? -1), (float) $tour['estimated_cost'], 'statistics use the optimized tour cost once');
expect_close((float) ($row['personnel_cost'] ?? -1), $expectedPersonnelCost, 'personnel cost combines one tour drive with one visit duration per stop');
expect_close((float) ($row['total_cost'] ?? -1), $expectedTotalCost, 'optimized route, personnel and overnight costs produce the total');
expect_true((int) ($row['participants_total'] ?? 0) === 127, 'participant metrics remain aggregated per visit');
expect_true((float) ($row['distance_km'] ?? 0) < 1800, 'stored individual round trips are not added to the statistic');
expect_true($routing->matrixCalls === 1, 'the unchanged historical tour is restored without another routing call');

$walkInDays = [
    '2026-08-10' => [
        'public_title' => 'Offenes Meeresfest',
        'public_description' => 'Zwei Tage, ein Event',
        'public_location' => 'Hafenhalle',
        'public_url' => 'https://example.org/meeresfest',
        'public_note' => 'Eintritt frei',
        'participants_children' => 50,
        'participants_adults' => 20,
    ],
    '2026-08-11' => [
        'public_title' => 'Offenes Meeresfest',
        'public_description' => 'Zwei Tage, ein Event',
        'public_location' => 'Hafenhalle',
        'public_url' => 'https://example.org/meeresfest',
        'public_note' => 'Eintritt frei',
        'participants_children' => null,
        'participants_adults' => null,
    ],
];
$walkInStatistics = new StatisticsService(
    static fn (): array => [],
    static fn (): array => $walkInDays,
    static fn (): array => [],
    static fn (array $items): array => []
);
$walkInReport = $walkInStatistics->report('week');
$walkInRow = $walkInReport['rows'][0] ?? [];
expect_true((int) ($walkInRow['appointments'] ?? 0) === 1, 'a contiguous multi-day walk-in block counts as one event');
expect_true((int) ($walkInRow['participants_total'] ?? 0) === 70, 'multi-day walk-in attendance is counted once');
expect_true((int) ($walkInRow['participants_missing'] ?? -1) === 0, 'empty metrics on continuation days do not create missing-attendance events');
expect_close((float) ($walkInRow['personnel_cost'] ?? -1), 2 * 6 * 30 * 2, 'walk-in personnel cost remains charged per operating day');
expect_true(
    (int) ($walkInRow['event_types']['walk_in'] ?? 0) === 1,
    'a multi-day walk-in block is classified once'
);

$adjacentWalkIns = [
    '2026-08-12' => [
        'public_event_group' => 'event-one',
        'public_title' => 'Offenes Meeresfest',
        'public_description' => 'Gleiche öffentliche Angaben',
        'public_location' => 'Hafenhalle',
        'public_url' => 'https://example.org/meeresfest',
        'public_note' => 'Eintritt frei',
        'participants_children' => 10,
        'participants_adults' => 5,
    ],
    '2026-08-13' => [
        'public_event_group' => 'event-two',
        'public_title' => 'Offenes Meeresfest',
        'public_description' => 'Gleiche öffentliche Angaben',
        'public_location' => 'Hafenhalle',
        'public_url' => 'https://example.org/meeresfest',
        'public_note' => 'Eintritt frei',
        'participants_children' => 20,
        'participants_adults' => 10,
    ],
];
$adjacentReport = (new StatisticsService(
    static fn (): array => [],
    static fn (): array => $adjacentWalkIns,
    static fn (): array => [],
    static fn (array $items): array => []
))->report('week');
$adjacentRow = $adjacentReport['rows'][0] ?? [];
expect_true(
    (int) ($adjacentRow['appointments'] ?? 0) === 2,
    'adjacent walk-in events with identical public details remain separate through their stable event ids'
);
expect_true(
    (int) ($adjacentRow['participants_total'] ?? 0) === 45,
    'attendance remains separate for adjacent walk-in events'
);

$walkInAppointment = [[
    'id' => 21,
    'request_id' => 121,
    'status' => 'confirmed',
    'appointment_date' => '2026-08-11',
    'institution_name' => 'Meeresfest',
    'event_type' => 'event',
    'participants_children' => 60,
    'participants_adults' => 30,
]];
$overlapStatistics = new StatisticsService(
    static fn (): array => $walkInAppointment,
    static fn (): array => $walkInDays,
    static fn (): array => [],
    static fn (array $items): array => []
);
$overlapRow = $overlapStatistics->report('week')['rows'][0] ?? [];
expect_true((int) ($overlapRow['appointments'] ?? 0) === 1, 'walk-in on an appointment day remains visible exactly once');
expect_true((int) ($overlapRow['participants_total'] ?? 0) === 90, 'recorded appointment attendance takes precedence for the walk-in event');
expect_close((float) ($overlapRow['personnel_cost'] ?? -1), 2 * 6 * 30 * 2, 'overlapping walk-in and appointment do not duplicate daily personnel cost');

$boundaryAppointments = [
    array_merge($depot, [
        'id' => 11,
        'request_id' => 111,
        'status' => 'confirmed',
        'appointment_date' => '2026-12-31',
        'institution_name' => 'Jahresabschluss',
        'event_type' => 'event',
        'city' => 'Reutlingen',
        'public_city' => 'Reutlingen',
        'state_code' => 'BW',
        'latitude' => 48.4914,
        'longitude' => 9.2043,
        'participants_children' => 10,
        'participants_adults' => 10,
    ]),
    array_merge($depot, [
        'id' => 12,
        'request_id' => 112,
        'status' => 'confirmed',
        'appointment_date' => '2027-01-01',
        'institution_name' => 'Neujahrseinsatz',
        'event_type' => 'event',
        'city' => 'Nürtingen',
        'public_city' => 'Nürtingen',
        'state_code' => 'BW',
        'latitude' => 48.6257,
        'longitude' => 9.3420,
        'participants_children' => 15,
        'participants_adults' => 15,
    ]),
];
expect_true(
    (new DateTimeImmutable('2026-12-31'))->format('o-W') === (new DateTimeImmutable('2027-01-01'))->format('o-W'),
    'boundary fixtures belong to the same ISO planning week'
);
$boundaryTours = $weeklyPlanning->confirmedClusters($boundaryAppointments);
expect_true(count($boundaryTours) === 1, 'the cross-year stops remain one weekly tour');
$boundaryStatistics = new StatisticsService(
    static fn (): array => $boundaryAppointments,
    static fn (): array => [],
    static fn (): array => [],
    static fn (array $items): array => $weeklyPlanning->confirmedClusters($items)
);
$monthlyReport = $boundaryStatistics->report('month');
$monthlyRows = array_column($monthlyReport['rows'], null, 'key');
expect_true(isset($monthlyRows['2026-12'], $monthlyRows['2027-01']), 'a cross-month tour contributes to both stop months');
expect_true(
    (float) $monthlyRows['2026-12']['distance_km'] > 0 && (float) $monthlyRows['2027-01']['distance_km'] > 0,
    'route legs are assigned to their destination stop month'
);
expect_close(
    (float) $monthlyRows['2026-12']['distance_km'] + (float) $monthlyRows['2027-01']['distance_km'],
    (float) $boundaryTours[0]['route_distance_km'],
    'monthly leg allocations preserve the optimized route distance',
    0.02
);
expect_close(
    (float) $monthlyRows['2026-12']['route_cost'] + (float) $monthlyRows['2027-01']['route_cost'],
    (float) $boundaryTours[0]['estimated_cost'],
    'monthly leg allocations preserve the optimized route cost',
    0.02
);
$yearlyReport = $boundaryStatistics->report('year');
$yearlyRows = array_column($yearlyReport['rows'], null, 'key');
expect_true(isset($yearlyRows['2026'], $yearlyRows['2027']), 'a cross-year tour contributes to both calendar years');
expect_true(
    (float) $yearlyRows['2026']['travel_minutes'] > 0 && (float) $yearlyRows['2027']['travel_minutes'] > 0,
    'travel time is allocated by leg across the year boundary'
);
expect_close(
    (float) $yearlyRows['2026']['travel_minutes'] + (float) $yearlyRows['2027']['travel_minutes'],
    (float) $boundaryTours[0]['route_duration_minutes'],
    'yearly leg allocations preserve the optimized driving time',
    0.02
);
expect_true($routing->matrixCalls === 2, 'persistent fingerprints avoid repeat routing for both historical tours');

echo "Confirmed tour statistics checks passed.\n";
