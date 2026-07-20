<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateTimeImmutable;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\SuggestionRepository;

final class WeeklyClusterService
{
    private const MAX_OPEN_STOPS_PER_CLUSTER = 5;

    public function clusters(): array
    {
        $requests = array_values(array_filter(
            (new RequestRepository())->list([], 200),
            static fn (array $request): bool => ! in_array(
                (string) ($request['work_state'] ?? ''),
                [WorkState::ACCEPTED, WorkState::REJECTED, WorkState::CANCELLED],
                true
            )
        ));
        $weeks = [];
        $dates = [];
        foreach ($requests as $request) {
            $date = $this->planningDate($request);
            if (! $this->validDate($date)) {
                continue;
            }
            $week = (new DateTimeImmutable($date))->format('o-W');
            $request['_planning_date'] = $date;
            $request['_stop_type'] = 'request';
            $weeks[$week]['requests'][] = $request;
            $dates[] = $date;
        }
        $today = current_time('Y-m-d');
        $horizon = (new DateTimeImmutable($today))->modify('+90 days')->format('Y-m-d');
        $appointments = (new AppointmentRepository())->forRange(
            $dates ? min(array_merge([$today], $dates)) : $today,
            $dates ? max(array_merge([$horizon], $dates)) : $horizon
        );
        foreach ($appointments as $date => $appointment) {
            $week = (new DateTimeImmutable($date))->format('o-W');
            $appointment['_planning_date'] = $date;
            $appointment['_stop_type'] = 'confirmed';
            $weeks[$week]['appointments'][] = $appointment;
        }

        if (! $weeks) {
            return [];
        }

        $result = [];
        $radiusKm = max(20.0, (float) get_option('pov_cluster_radius_km', 120));
        foreach ($weeks as $week => $rows) {
            foreach ($this->spatialClusters((array) ($rows['requests'] ?? []), (array) ($rows['appointments'] ?? []), $radiusKm) as $index => $cluster) {
                if (! $cluster['requests'] && ! $cluster['anchors']) {
                    continue;
                }
                $result[] = $this->buildCluster($week, $index, $cluster);
            }
        }

        usort($result, static function (array $left, array $right): int {
            $weekComparison = $left['week_start'] <=> $right['week_start'];
            return $weekComparison !== 0 ? $weekComparison : $right['cost_saved'] <=> $left['cost_saved'];
        });
        return array_values($result);
    }

    private function spatialClusters(array $requests, array $appointments, float $radiusKm): array
    {
        $clusters = [];
        $requestStates = array_values(array_unique(array_filter(array_map(
            static fn (array $request): string => strtoupper((string) ($request['state_code'] ?? '')),
            $requests
        ))));
        $separateRequestStates = count($requestStates) > 1;
        foreach ($appointments as $appointment) {
            if (! $this->hasCoordinates($appointment)) {
                $clusters[] = ['requests' => [], 'anchors' => [$appointment], 'center' => null];
                continue;
            }

            $selected = null;
            $selectedDistance = null;
            foreach ($clusters as $index => $cluster) {
                if ($cluster['center'] === null) {
                    continue;
                }
                $distance = $this->distanceKm($this->point($appointment), $cluster['center']);
                if ($distance <= $radiusKm && ($selectedDistance === null || $distance < $selectedDistance)) {
                    $selected = $index;
                    $selectedDistance = $distance;
                }
            }
            if ($selected === null) {
                $clusters[] = ['requests' => [], 'anchors' => [$appointment], 'center' => $this->point($appointment)];
                continue;
            }
            $clusters[$selected]['anchors'][] = $appointment;
            $clusters[$selected]['center'] = $this->centroid(array_merge($clusters[$selected]['anchors'], $clusters[$selected]['requests']));
        }

        foreach ($requests as $request) {
            $selected = null;
            $selectedDistance = null;
            if ($this->hasCoordinates($request)) {
                foreach ($clusters as $index => $cluster) {
                    if (count($cluster['requests']) >= self::MAX_OPEN_STOPS_PER_CLUSTER) {
                        continue;
                    }
                    if ($separateRequestStates && $cluster['requests']) {
                        $clusterRequestStates = array_values(array_unique(array_filter(array_map(
                            static fn (array $item): string => strtoupper((string) ($item['state_code'] ?? '')),
                            $cluster['requests']
                        ))));
                        if ($clusterRequestStates && ! in_array(strtoupper((string) ($request['state_code'] ?? '')), $clusterRequestStates, true)) {
                            continue;
                        }
                    }
                    if ($cluster['center'] === null) {
                        continue;
                    }
                    $distance = $this->distanceKm($this->point($request), $cluster['center']);
                    if ($distance <= $radiusKm && ($selectedDistance === null || $distance < $selectedDistance)) {
                        $selected = $index;
                        $selectedDistance = $distance;
                    }
                }
            }

            if ($selected === null) {
                $clusters[] = [
                    'requests' => [$request],
                    'anchors' => [],
                    'center' => $this->hasCoordinates($request) ? $this->point($request) : null,
                ];
                continue;
            }

            $clusters[$selected]['requests'][] = $request;
            $clusters[$selected]['center'] = $this->centroid(array_merge($clusters[$selected]['anchors'], $clusters[$selected]['requests']));
        }
        return $clusters;
    }

    private function buildCluster(string $week, int $index, array $cluster): array
    {
        $anchors = $cluster['anchors'];
        usort($anchors, static fn (array $left, array $right): int => (string) $left['_planning_date'] <=> (string) $right['_planning_date']);
        $fixedRequests = array_values(array_filter($cluster['requests'], static fn (array $request): bool => ($request['request_mode'] ?? '') === 'specific_date'));
        $flexibleRequests = array_values(array_filter($cluster['requests'], static fn (array $request): bool => ($request['request_mode'] ?? '') !== 'specific_date'));
        $fixedStops = array_merge($anchors, $fixedRequests);
        usort($fixedStops, static fn (array $left, array $right): int => (string) $left['_planning_date'] <=> (string) $right['_planning_date']);
        $stops = array_merge($fixedStops, $flexibleRequests);
        $firstDate = new DateTimeImmutable((string) $stops[0]['_planning_date']);
        $monday = $firstDate->modify('monday this week');
        $depot = $this->depotForCluster($anchors);
        $plan = (new RouteOptimizationService())->optimizeWithFixedOrder($fixedStops, $flexibleRequests, $depot);

        $orderedKeys = [];
        foreach ($plan['ordered_stops'] as $stop) {
            $orderedKeys[$this->stopKey($stop)] = $stop;
        }
        foreach ($stops as $stop) {
            $key = $this->stopKey($stop);
            if (! isset($orderedKeys[$key])) {
                $orderedKeys[$key] = $stop;
            }
        }
        $orderedStops = $this->assignOptimizedDates(array_values($orderedKeys));
        $overnights = $this->overnightRecommendations($orderedStops, (array) $plan['legs']);
        $orderedRequests = array_values(array_filter($orderedStops, static fn (array $stop): bool => ($stop['_stop_type'] ?? '') === 'request'));
        $places = array_values(array_unique(array_filter(array_map(static fn (array $stop): string => (string) ($stop['city'] ?? $stop['public_city'] ?? ''), $orderedStops))));
        $titlePlaces = implode(' / ', array_slice($places, 0, 2));
        $stateCodes = array_values(array_unique(array_filter(array_map(static fn (array $stop): string => strtoupper((string) ($stop['state_code'] ?? '')), $orderedStops))));
        $titleStates = implode(' / ', $stateCodes);
        $titleParts = array_filter([$titleStates, $titlePlaces]);

        return [
            'week' => $week,
            'week_number' => $firstDate->format('W'),
            'week_start' => $monday->format('Y-m-d'),
            'week_end' => $monday->modify('+4 days')->format('Y-m-d'),
            'region' => sanitize_key(($titlePlaces ?: 'offen') . '-' . $index),
            'title' => 'KW ' . $firstDate->format('W') . ($titleParts ? ' · ' . implode(' · ', $titleParts) : ''),
            'state_codes' => $stateCodes,
            'requests' => $orderedRequests,
            'stops' => $orderedStops,
            'map_points' => $orderedStops,
            'legs' => $plan['legs'],
            'request_count' => count($cluster['requests']),
            'confirmed_count' => count($cluster['anchors']),
            'routable_count' => (int) $plan['stop_count'],
            'missing_coordinates' => count($stops) - (int) $plan['stop_count'],
            'route_distance_km' => $plan['route_distance_km'],
            'route_duration_minutes' => $plan['route_duration_minutes'],
            'standalone_distance_km' => $plan['standalone_distance_km'],
            'distance_saved_km' => $plan['distance_saved_km'],
            'estimated_cost' => $plan['estimated_cost'],
            'standalone_cost' => $plan['standalone_cost'],
            'cost_saved' => $plan['cost_saved'],
            'savings_percent' => $plan['savings_percent'],
            'matrix_source' => $plan['matrix_source'],
            'overnights' => $overnights,
            'start_label' => (string) ($depot['label'] ?? get_option('pov_default_start_label', 'Start')),
            'priority' => $plan['savings_percent'] >= 15 ? 'recommended' : 'standard',
        ];
    }

    private function planningDate(array $request): string
    {
        if (($request['request_mode'] ?? '') === 'specific_date') {
            return (string) ($request['specific_requested_date'] ?? '');
        }

        $from = (string) ($request['desired_date_from'] ?? '');
        $to = (string) ($request['desired_date_to'] ?? '');
        foreach ((new SuggestionRepository())->forRequest((int) $request['id']) as $suggestion) {
            $date = (string) ($suggestion['suggestion_date'] ?? '');
            if ($this->validDate($date) && $date >= $from && $date <= $to && ! in_array((string) $suggestion['state'], ['rejected', 'expired'], true)) {
                return $date;
            }
        }
        return $from;
    }

    private function overnightRecommendations(array $stops, array $legs): array
    {
        $recommendations = [];
        foreach ($stops as $index => $stop) {
            $date = (string) ($stop['_planning_date'] ?? $stop['appointment_date'] ?? '');
            $next = $stops[$index + 1] ?? null;
            if ($next) {
                $nextDate = (string) ($next['_planning_date'] ?? $next['appointment_date'] ?? '');
                if ($this->validDate($date) && $this->validDate($nextDate) && $nextDate > $date) {
                    $recommendations[] = [
                        'after_stop' => $index,
                        'date' => $date,
                        'place' => (string) ($stop['city'] ?? $stop['public_city'] ?? ''),
                        'next_place' => (string) ($next['city'] ?? $next['public_city'] ?? ''),
                        'next_leg_duration_minutes' => (float) ($legs[$index + 1]['duration_minutes'] ?? 0),
                        'reason' => 'Zwischen zwei Einsatztagen',
                    ];
                }
                continue;
            }

            $returnLeg = (array) ($legs[$index + 1] ?? []);
            if ((float) ($returnLeg['duration_minutes'] ?? 0) >= 180) {
                $recommendations[] = [
                    'after_stop' => $index,
                    'date' => $date,
                    'place' => (string) ($stop['city'] ?? $stop['public_city'] ?? ''),
                    'next_place' => (string) ($returnLeg['to'] ?? ''),
                    'next_leg_duration_minutes' => (float) ($returnLeg['duration_minutes'] ?? 0),
                    'reason' => 'Vor der Rückfahrt',
                ];
            }
        }
        return $recommendations;
    }

    private function assignOptimizedDates(array $stops): array
    {
        $occupied = [];
        foreach ($stops as $stop) {
            if ($this->isFixedStop($stop) && $this->validDate((string) ($stop['_planning_date'] ?? ''))) {
                $occupied[] = (string) $stop['_planning_date'];
            }
        }

        foreach ($stops as $index => &$stop) {
            if ($this->isFixedStop($stop)) {
                continue;
            }
            $previous = null;
            for ($left = $index - 1; $left >= 0; $left--) {
                if ($this->validDate((string) ($stops[$left]['_planning_date'] ?? ''))) {
                    $previous = (string) $stops[$left]['_planning_date'];
                    break;
                }
            }
            $next = null;
            for ($right = $index + 1; $right < count($stops); $right++) {
                if ($this->isFixedStop($stops[$right]) && $this->validDate((string) ($stops[$right]['_planning_date'] ?? ''))) {
                    $next = (string) $stops[$right]['_planning_date'];
                    break;
                }
            }

            $candidates = array_values(array_filter($this->requestDates($stop), static function (string $date) use ($previous, $next, $occupied): bool {
                return ! in_array($date, $occupied, true)
                    && ($previous === null || $date > $previous)
                    && ($next === null || $date < $next);
            }));
            if (! $candidates) {
                $candidates = array_values(array_filter($this->requestDates($stop), static fn (string $date): bool => ! in_array($date, $occupied, true)));
            }
            if ($candidates) {
                $stop['_planning_date'] = $candidates[0];
                $stop['_optimized_date'] = true;
                $occupied[] = $candidates[0];
            }
        }
        unset($stop);
        return $stops;
    }

    private function isFixedStop(array $stop): bool
    {
        return ($stop['_stop_type'] ?? '') === 'confirmed' || ($stop['request_mode'] ?? '') === 'specific_date';
    }

    private function requestDates(array $request): array
    {
        $from = (string) ($request['desired_date_from'] ?? '');
        $to = (string) ($request['desired_date_to'] ?? '');
        if (! $this->validDate($from) || ! $this->validDate($to) || $from > $to) {
            return [];
        }
        $weekdays = array_filter(explode(',', (string) ($request['possible_weekdays'] ?? '')));
        $dates = [];
        for ($day = new DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') >= 6) {
                continue;
            }
            if ($weekdays && ! in_array(strtolower($day->format('D')), $weekdays, true)) {
                continue;
            }
            $dates[] = $day->format('Y-m-d');
        }
        return $dates;
    }

    private function depotForCluster(array $anchors): ?array
    {
        foreach ($anchors as $anchor) {
            if (is_numeric($anchor['start_latitude'] ?? null) && is_numeric($anchor['start_longitude'] ?? null)) {
                return [
                    'latitude' => (float) $anchor['start_latitude'],
                    'longitude' => (float) $anchor['start_longitude'],
                    'label' => (string) ($anchor['start_label'] ?? get_option('pov_default_start_label', 'Start')),
                ];
            }
        }
        return null;
    }

    private function centroid(array $stops): ?array
    {
        $points = array_values(array_filter(array_map(fn (array $stop): ?array => $this->hasCoordinates($stop) ? $this->point($stop) : null, $stops)));
        if (! $points) {
            return null;
        }
        return [
            'latitude' => array_sum(array_column($points, 'latitude')) / count($points),
            'longitude' => array_sum(array_column($points, 'longitude')) / count($points),
        ];
    }

    private function point(array $stop): array
    {
        return ['latitude' => (float) $stop['latitude'], 'longitude' => (float) $stop['longitude']];
    }

    private function hasCoordinates(array $stop): bool
    {
        return is_numeric($stop['latitude'] ?? null) && is_numeric($stop['longitude'] ?? null);
    }

    private function stopKey(array $stop): string
    {
        return (string) ($stop['_stop_type'] ?? 'stop') . ':' . (string) ($stop['id'] ?? md5(wp_json_encode($stop)));
    }

    private function distanceKm(array $left, array $right): float
    {
        $earthKm = 6371.0;
        $latitudeDelta = deg2rad($right['latitude'] - $left['latitude']);
        $longitudeDelta = deg2rad($right['longitude'] - $left['longitude']);
        $value = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($left['latitude'])) * cos(deg2rad($right['latitude'])) * sin($longitudeDelta / 2) ** 2;
        return $earthKm * 2 * atan2(sqrt($value), sqrt(1 - $value));
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
