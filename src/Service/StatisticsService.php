<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use Closure;
use DateTimeImmutable;
use ProOceanVan\Domain\EventType;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\TourExpenseRepository;

final class StatisticsService
{
    private Closure $appointmentProvider;
    private Closure $walkInProvider;
    private Closure $expenseProvider;
    private Closure $confirmedTourProvider;

    public function __construct(
        ?callable $appointmentProvider = null,
        ?callable $walkInProvider = null,
        ?callable $expenseProvider = null,
        ?callable $confirmedTourProvider = null
    ) {
        $this->appointmentProvider = $appointmentProvider !== null
            ? Closure::fromCallable($appointmentProvider)
            : static fn (): array => (new AppointmentRepository())->all();
        $this->walkInProvider = $walkInProvider !== null
            ? Closure::fromCallable($walkInProvider)
            : static fn (): array => (new CalendarDayRepository())->walkIns();
        $this->expenseProvider = $expenseProvider !== null
            ? Closure::fromCallable($expenseProvider)
            : static fn (): array => (new TourExpenseRepository())->all();
        $this->confirmedTourProvider = $confirmedTourProvider !== null
            ? Closure::fromCallable($confirmedTourProvider)
            : static fn (array $appointments): array => (new WeeklyClusterService())->confirmedClusters($appointments);
    }

    public function report(string $period): array
    {
        $period = in_array($period, ['week', 'month', 'year'], true) ? $period : 'week';
        $hourlyRate = max(0.0, (float) get_option('pov_personnel_hourly_rate', 30));
        $personnelCount = max(0, (int) get_option('pov_personnel_count', 2));
        $visitHours = max(0.0, (float) get_option('pov_default_visit_hours', 6));
        $groups = [];
        $eventTypes = [];
        $appointments = (array) ($this->appointmentProvider)();
        $walkIns = (array) ($this->walkInProvider)();
        $confirmedTours = (array) ($this->confirmedTourProvider)($appointments);
        $appointmentDates = [];

        foreach ($appointments as $appointment) {
            if (isset($appointment['status']) && (string) $appointment['status'] !== 'confirmed') {
                continue;
            }
            $appointmentDate = (string) $appointment['appointment_date'];
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $appointmentDate);
            if (! $date) {
                continue;
            }
            $appointmentDates[$appointmentDate] = $appointment;
            [$key, $label] = $this->period($date, $period);
            $personnelCost = $visitHours * $hourlyRate * $personnelCount;
            $walkIn = $walkIns[$appointmentDate] ?? null;
            $eventType = $walkIn ? EventType::WALK_IN : EventType::normalize((string) ($appointment['event_type'] ?? 'other'));
            $appointmentHasMetrics = $appointment['participants_children'] !== null || $appointment['participants_adults'] !== null;
            $walkInHasMetrics = $walkIn && ($walkIn['participants_children'] !== null || $walkIn['participants_adults'] !== null);
            $hasMetrics = $appointmentHasMetrics || $walkInHasMetrics;
            $children = $appointmentHasMetrics
                ? max(0, (int) ($appointment['participants_children'] ?? 0))
                : ($walkInHasMetrics ? max(0, (int) ($walkIn['participants_children'] ?? 0)) : 0);
            $adults = $appointmentHasMetrics
                ? max(0, (int) ($appointment['participants_adults'] ?? 0))
                : ($walkInHasMetrics ? max(0, (int) ($walkIn['participants_adults'] ?? 0)) : 0);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'appointments' => 0,
                    'distance_km' => 0.0,
                    'travel_minutes' => 0.0,
                    'route_cost' => 0.0,
                    'personnel_cost' => 0.0,
                    'overnight_cost' => 0.0,
                    'total_cost' => 0.0,
                    'participants_children' => 0,
                    'participants_adults' => 0,
                    'participants_total' => 0,
                    'participants_missing' => 0,
                    'event_types' => [],
                ];
            }
            $groups[$key]['personnel_cost'] += $personnelCost;
            $groups[$key]['total_cost'] += $personnelCost;
            if ($walkIn) {
                continue;
            }
            $groups[$key]['appointments']++;
            $groups[$key]['participants_children'] += $children;
            $groups[$key]['participants_adults'] += $adults;
            $groups[$key]['participants_total'] += $children + $adults;
            $groups[$key]['participants_missing'] += $hasMetrics ? 0 : 1;
            $groups[$key]['event_types'][$eventType] = (int) ($groups[$key]['event_types'][$eventType] ?? 0) + 1;
            if (! isset($eventTypes[$eventType])) {
                $eventTypes[$eventType] = [
                    'event_type' => $eventType,
                    'label' => EventType::labels()[$eventType] ?? EventType::labels()[EventType::OTHER],
                    'appointments' => 0,
                    'participants_children' => 0,
                    'participants_adults' => 0,
                    'participants_total' => 0,
                    'participants_missing' => 0,
                ];
            }
            $eventTypes[$eventType]['appointments']++;
            $eventTypes[$eventType]['participants_children'] += $children;
            $eventTypes[$eventType]['participants_adults'] += $adults;
            $eventTypes[$eventType]['participants_total'] += $children + $adults;
            $eventTypes[$eventType]['participants_missing'] += $hasMetrics ? 0 : 1;
        }

        foreach ($confirmedTours as $tour) {
            foreach ($this->tourPeriodAllocations((array) $tour, $period) as $allocation) {
                $key = (string) $allocation['key'];
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'key' => $key,
                        'label' => (string) $allocation['label'],
                        'appointments' => 0,
                        'distance_km' => 0.0,
                        'travel_minutes' => 0.0,
                        'route_cost' => 0.0,
                        'personnel_cost' => 0.0,
                        'overnight_cost' => 0.0,
                        'total_cost' => 0.0,
                        'participants_children' => 0,
                        'participants_adults' => 0,
                        'participants_total' => 0,
                        'participants_missing' => 0,
                        'event_types' => [],
                    ];
                }
                $travelPersonnelCost = ((float) $allocation['travel_minutes'] / 60) * $hourlyRate * $personnelCount;
                $groups[$key]['distance_km'] += (float) $allocation['distance_km'];
                $groups[$key]['travel_minutes'] += (float) $allocation['travel_minutes'];
                $groups[$key]['route_cost'] += (float) $allocation['route_cost'];
                $groups[$key]['personnel_cost'] += $travelPersonnelCost;
                $groups[$key]['total_cost'] += (float) $allocation['route_cost'] + $travelPersonnelCost;
            }
        }

        foreach ($this->walkInGroups($walkIns) as $walkInGroup) {
            $days = (array) ($walkInGroup['days'] ?? []);
            if (! $days) {
                continue;
            }

            $personnelCost = $visitHours * $hourlyRate * $personnelCount;
            foreach ($days as $day) {
                $dateValue = (string) ($day['date'] ?? '');
                if (isset($appointmentDates[$dateValue])) {
                    continue;
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
                if (! $date || $date->format('Y-m-d') !== $dateValue) {
                    continue;
                }
                [$key, $label] = $this->period($date, $period);
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'key' => $key,
                        'label' => $label,
                        'appointments' => 0,
                        'distance_km' => 0.0,
                        'travel_minutes' => 0.0,
                        'route_cost' => 0.0,
                        'personnel_cost' => 0.0,
                        'overnight_cost' => 0.0,
                        'total_cost' => 0.0,
                        'participants_children' => 0,
                        'participants_adults' => 0,
                        'participants_total' => 0,
                        'participants_missing' => 0,
                        'event_types' => [],
                    ];
                }
                $groups[$key]['personnel_cost'] += $personnelCost;
                $groups[$key]['total_cost'] += $personnelCost;
            }

            $eventDate = (string) ($days[0]['date'] ?? '');
            $hasMetrics = false;
            $children = 0;
            $adults = 0;
            foreach ($days as $day) {
                $dateValue = (string) ($day['date'] ?? '');
                $appointment = $appointmentDates[$dateValue] ?? null;
                if (is_array($appointment)
                    && ($appointment['participants_children'] !== null || $appointment['participants_adults'] !== null)) {
                    $eventDate = $dateValue;
                    $hasMetrics = true;
                    $children = max(0, (int) ($appointment['participants_children'] ?? 0));
                    $adults = max(0, (int) ($appointment['participants_adults'] ?? 0));
                    break;
                }
                $walkIn = (array) ($day['event'] ?? []);
                if (! $hasMetrics && ($walkIn['participants_children'] !== null || $walkIn['participants_adults'] !== null)) {
                    $eventDate = $dateValue;
                    $hasMetrics = true;
                    $children = max(0, (int) ($walkIn['participants_children'] ?? 0));
                    $adults = max(0, (int) ($walkIn['participants_adults'] ?? 0));
                }
            }

            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
            if (! $date || $date->format('Y-m-d') !== $eventDate) {
                continue;
            }
            [$key, $label] = $this->period($date, $period);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'appointments' => 0,
                    'distance_km' => 0.0,
                    'travel_minutes' => 0.0,
                    'route_cost' => 0.0,
                    'personnel_cost' => 0.0,
                    'overnight_cost' => 0.0,
                    'total_cost' => 0.0,
                    'participants_children' => 0,
                    'participants_adults' => 0,
                    'participants_total' => 0,
                    'participants_missing' => 0,
                    'event_types' => [],
                ];
            }
            $groups[$key]['appointments']++;
            $groups[$key]['participants_children'] += $children;
            $groups[$key]['participants_adults'] += $adults;
            $groups[$key]['participants_total'] += $children + $adults;
            $groups[$key]['participants_missing'] += $hasMetrics ? 0 : 1;
            $groups[$key]['event_types'][EventType::WALK_IN] = (int) ($groups[$key]['event_types'][EventType::WALK_IN] ?? 0) + 1;
            if (! isset($eventTypes[EventType::WALK_IN])) {
                $eventTypes[EventType::WALK_IN] = [
                    'event_type' => EventType::WALK_IN,
                    'label' => EventType::labels()[EventType::WALK_IN],
                    'appointments' => 0,
                    'participants_children' => 0,
                    'participants_adults' => 0,
                    'participants_total' => 0,
                    'participants_missing' => 0,
                ];
            }
            $eventTypes[EventType::WALK_IN]['appointments']++;
            $eventTypes[EventType::WALK_IN]['participants_children'] += $children;
            $eventTypes[EventType::WALK_IN]['participants_adults'] += $adults;
            $eventTypes[EventType::WALK_IN]['participants_total'] += $children + $adults;
            $eventTypes[EventType::WALK_IN]['participants_missing'] += $hasMetrics ? 0 : 1;
        }

        foreach ((array) ($this->expenseProvider)() as $expense) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $expense['expense_date']);
            if (! $date) {
                continue;
            }
            [$key, $label] = $this->period($date, $period);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'appointments' => 0,
                    'distance_km' => 0.0,
                    'travel_minutes' => 0.0,
                    'route_cost' => 0.0,
                    'personnel_cost' => 0.0,
                    'overnight_cost' => 0.0,
                    'total_cost' => 0.0,
                    'participants_children' => 0,
                    'participants_adults' => 0,
                    'participants_total' => 0,
                    'participants_missing' => 0,
                    'event_types' => [],
                ];
            }
            $amount = max(0.0, (float) ($expense['amount'] ?? 0));
            $groups[$key]['overnight_cost'] += $amount;
            $groups[$key]['total_cost'] += $amount;
        }

        ksort($groups);
        $rows = array_values(array_map(static function (array $row): array {
            foreach (['distance_km', 'travel_minutes', 'route_cost', 'personnel_cost', 'overnight_cost', 'total_cost'] as $field) {
                $row[$field] = round((float) $row[$field], 2);
            }
            return $row;
        }, $groups));
        $totals = [
            'appointments' => array_sum(array_column($rows, 'appointments')),
            'distance_km' => array_sum(array_column($rows, 'distance_km')),
            'travel_minutes' => array_sum(array_column($rows, 'travel_minutes')),
            'route_cost' => array_sum(array_column($rows, 'route_cost')),
            'personnel_cost' => array_sum(array_column($rows, 'personnel_cost')),
            'overnight_cost' => array_sum(array_column($rows, 'overnight_cost')),
            'total_cost' => array_sum(array_column($rows, 'total_cost')),
            'participants_children' => array_sum(array_column($rows, 'participants_children')),
            'participants_adults' => array_sum(array_column($rows, 'participants_adults')),
            'participants_total' => array_sum(array_column($rows, 'participants_total')),
            'participants_missing' => array_sum(array_column($rows, 'participants_missing')),
        ];

        return ['period' => $period, 'rows' => $rows, 'totals' => $totals, 'event_types' => array_values($eventTypes)];
    }

    private function walkInGroups(array $walkIns): array
    {
        ksort($walkIns);
        $groups = [];
        $previousDate = null;
        $previousFingerprint = null;
        foreach ($walkIns as $dateValue => $walkIn) {
            $dateValue = (string) $dateValue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
            if (! $date || $date->format('Y-m-d') !== $dateValue) {
                continue;
            }
            $walkIn = (array) $walkIn;
            $eventGroup = trim((string) ($walkIn['public_event_group'] ?? ''));
            $fingerprint = $eventGroup !== ''
                ? 'group:' . $eventGroup
                : hash('sha256', (string) json_encode(array_map(
                    static fn (string $field): string => trim((string) ($walkIn[$field] ?? '')),
                    ['public_title', 'public_description', 'public_location', 'public_url', 'public_note']
                )));
            $contiguous = $previousDate instanceof DateTimeImmutable
                && $previousDate->modify('+1 day')->format('Y-m-d') === $dateValue;
            if (! $groups || ! $contiguous || $fingerprint !== $previousFingerprint) {
                $groups[] = ['fingerprint' => $fingerprint, 'days' => []];
            }
            $groups[array_key_last($groups)]['days'][] = ['date' => $dateValue, 'event' => $walkIn];
            $previousDate = $date;
            $previousFingerprint = $fingerprint;
        }
        return $groups;
    }

    private function tourPeriodAllocations(array $tour, string $period): array
    {
        $stops = array_values((array) ($tour['stops'] ?? []));
        $datedStops = array_values(array_filter(array_map([$this, 'stopDate'], $stops)));
        if (! $datedStops) {
            return [];
        }

        $legs = array_values((array) ($tour['legs'] ?? []));
        $routeDistance = max(0.0, (float) ($tour['route_distance_km'] ?? 0));
        $routeDuration = max(0.0, (float) ($tour['route_duration_minutes'] ?? 0));
        $routeCost = max(0.0, (float) ($tour['estimated_cost'] ?? 0));
        if (! $legs) {
            [$key, $label] = $this->period($datedStops[0], $period);
            return [[
                'key' => $key,
                'label' => $label,
                'distance_km' => $routeDistance,
                'travel_minutes' => $routeDuration,
                'route_cost' => $routeCost,
            ]];
        }

        $legRows = [];
        $lastDate = $datedStops[0];
        foreach ($legs as $index => $leg) {
            $stopIndex = min($index, count($stops) - 1);
            $date = $this->stopDate((array) ($stops[$stopIndex] ?? [])) ?? $lastDate;
            $lastDate = $date;
            $legRows[] = [
                'date' => $date,
                'distance_km' => max(0.0, (float) ($leg['distance_km'] ?? 0)),
                'travel_minutes' => max(0.0, (float) ($leg['duration_minutes'] ?? 0)),
            ];
        }

        $legDistance = array_sum(array_column($legRows, 'distance_km'));
        $legDuration = array_sum(array_column($legRows, 'travel_minutes'));
        $count = count($legRows);
        $allocations = [];
        foreach ($legRows as $leg) {
            $distanceWeight = $legDistance > 0
                ? (float) $leg['distance_km'] / $legDistance
                : ($legDuration > 0 ? (float) $leg['travel_minutes'] / $legDuration : 1 / $count);
            $durationWeight = $legDuration > 0
                ? (float) $leg['travel_minutes'] / $legDuration
                : $distanceWeight;
            [$key, $label] = $this->period($leg['date'], $period);
            if (! isset($allocations[$key])) {
                $allocations[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'distance_km' => 0.0,
                    'travel_minutes' => 0.0,
                    'route_cost' => 0.0,
                ];
            }
            $allocations[$key]['distance_km'] += $routeDistance * $distanceWeight;
            $allocations[$key]['travel_minutes'] += $routeDuration * $durationWeight;
            $allocations[$key]['route_cost'] += $routeCost * $distanceWeight;
        }
        return array_values($allocations);
    }

    private function stopDate(array $stop): ?DateTimeImmutable
    {
        $date = (string) ($stop['_planning_date'] ?? $stop['appointment_date'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    private function period(DateTimeImmutable $date, string $period): array
    {
        if ($period === 'year') {
            return [$date->format('Y'), $date->format('Y')];
        }
        if ($period === 'month') {
            return [$date->format('Y-m'), GermanDateFormatter::monthYear($date->format('Y-m-d'))];
        }
        return [$date->format('o-W'), 'KW ' . $date->format('W') . ' · ' . $date->format('o')];
    }
}
