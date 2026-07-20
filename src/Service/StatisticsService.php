<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateTimeImmutable;
use ProOceanVan\Repository\AppointmentRepository;

final class StatisticsService
{
    public function report(string $period): array
    {
        $period = in_array($period, ['week', 'month', 'year'], true) ? $period : 'week';
        $hourlyRate = max(0.0, (float) get_option('pov_personnel_hourly_rate', 30));
        $personnelCount = max(0, (int) get_option('pov_personnel_count', 2));
        $visitHours = max(0.0, (float) get_option('pov_default_visit_hours', 6));
        $averageSpeed = max(20.0, (float) get_option('pov_average_driving_speed_kmh', 70));
        $groups = [];

        foreach ((new AppointmentRepository())->all() as $appointment) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $appointment['appointment_date']);
            if (! $date) {
                continue;
            }
            [$key, $label] = $this->period($date, $period);
            $distance = max(0.0, (float) ($appointment['route_distance_km'] ?? 0));
            $travelHours = $distance / $averageSpeed;
            $routeCost = is_numeric($appointment['route_cost'] ?? null)
                ? max(0.0, (float) $appointment['route_cost'])
                : (new CostService())->cost($distance);
            $personnelCost = ($visitHours + $travelHours) * $hourlyRate * $personnelCount;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'appointments' => 0,
                    'distance_km' => 0.0,
                    'travel_minutes' => 0.0,
                    'route_cost' => 0.0,
                    'personnel_cost' => 0.0,
                    'total_cost' => 0.0,
                ];
            }
            $groups[$key]['appointments']++;
            $groups[$key]['distance_km'] += $distance;
            $groups[$key]['travel_minutes'] += $travelHours * 60;
            $groups[$key]['route_cost'] += $routeCost;
            $groups[$key]['personnel_cost'] += $personnelCost;
            $groups[$key]['total_cost'] += $routeCost + $personnelCost;
        }

        ksort($groups);
        $rows = array_values(array_map(static function (array $row): array {
            foreach (['distance_km', 'travel_minutes', 'route_cost', 'personnel_cost', 'total_cost'] as $field) {
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
            'total_cost' => array_sum(array_column($rows, 'total_cost')),
        ];

        return ['period' => $period, 'rows' => $rows, 'totals' => $totals];
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
