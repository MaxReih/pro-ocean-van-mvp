<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use ProOceanVan\Routing\ProviderFactory;
use ProOceanVan\Routing\RoutingProviderInterface;

final class RouteOptimizationService
{
    private const ROAD_DISTANCE_FACTOR = 1.22;

    public function __construct(private readonly ?RoutingProviderInterface $routing = null)
    {
    }

    public function optimize(array $stops, ?array $depot = null): array
    {
        $depot = $this->normalizePoint($depot ?? $this->defaultDepot());
        $stops = array_values(array_filter(array_map([$this, 'normalizeStop'], $stops)));
        if (! $depot || ! $stops) {
            return $this->emptyResult($stops);
        }

        [$matrix, $durations, $source] = $this->distanceMatrix(array_merge([$depot], $stops));
        $indexes = range(1, count($stops));
        $order = $this->improveOrder($this->nearestNeighbour($indexes, $matrix), $matrix);
        return $this->resultForOrder($order, $matrix, $durations, $depot, $stops, $source);
    }

    public function optimizeWithFixedOrder(array $fixedStops, array $flexibleStops, ?array $depot = null): array
    {
        if (! $fixedStops) {
            return $this->optimize($flexibleStops, $depot);
        }

        $depot = $this->normalizePoint($depot ?? $this->defaultDepot());
        $fixedStops = array_values(array_filter(array_map([$this, 'normalizeStop'], $fixedStops)));
        $flexibleStops = array_values(array_filter(array_map([$this, 'normalizeStop'], $flexibleStops)));
        if (! $fixedStops) {
            return $this->optimize($flexibleStops, $depot);
        }
        $stops = array_merge($fixedStops, $flexibleStops);
        if (! $depot || ! $stops) {
            return $this->emptyResult($stops);
        }

        [$matrix, $durations, $source] = $this->distanceMatrix(array_merge([$depot], $stops));
        $order = range(1, count($fixedStops));
        $flexibleIndexes = $flexibleStops ? range(count($fixedStops) + 1, count($stops)) : [];
        foreach ($flexibleIndexes as $flexibleIndex) {
            $bestOrder = null;
            $bestDistance = INF;
            for ($position = 0; $position <= count($order); $position++) {
                $candidate = $order;
                array_splice($candidate, $position, 0, [$flexibleIndex]);
                $distance = $this->routeDistance($candidate, $matrix);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestOrder = $candidate;
                }
            }
            $order = $bestOrder ?? $order;
        }

        return $this->resultForOrder($order, $matrix, $durations, $depot, $stops, $source);
    }

    public function compareInsertion(array $existingStops, array $candidate, ?array $depot = null): array
    {
        $depot = $this->normalizePoint($depot ?? $this->defaultDepot());
        $existingStops = array_values(array_filter(array_map([$this, 'normalizeStop'], $existingStops)));
        $candidate = $this->normalizeStop($candidate);
        if (! $depot || ! $candidate) {
            return [];
        }

        $points = array_merge([$depot], $existingStops, [$candidate]);
        [$matrix, , $source] = $this->distanceMatrix($points);
        $existingIndexes = $existingStops ? range(1, count($existingStops)) : [];
        $candidateIndex = count($points) - 1;
        $beforeOrder = $existingIndexes ? $this->improveOrder($this->nearestNeighbour($existingIndexes, $matrix), $matrix) : [];
        $afterIndexes = array_merge($existingIndexes, [$candidateIndex]);
        $afterOrder = $this->improveOrder($this->nearestNeighbour($afterIndexes, $matrix), $matrix);
        $beforeDistance = $this->routeDistance($beforeOrder, $matrix);
        $afterDistance = $this->routeDistance($afterOrder, $matrix);
        $incrementalDistance = max(0.0, $afterDistance - $beforeDistance);
        $standaloneDistance = $this->edge($matrix, 0, $candidateIndex) + $this->edge($matrix, $candidateIndex, 0);
        $savedDistance = max(0.0, $standaloneDistance - $incrementalDistance);
        $costService = new CostService();

        return [
            'ordered_stops' => array_map(function (int $index) use ($existingStops, $candidate, $candidateIndex): array {
                return $index === $candidateIndex ? $candidate : $existingStops[$index - 1];
            }, $afterOrder),
            'route_distance_before_km' => round($beforeDistance, 1),
            'route_distance_after_km' => round($afterDistance, 1),
            'incremental_distance_km' => round($incrementalDistance, 1),
            'standalone_distance_km' => round($standaloneDistance, 1),
            'distance_saved_km' => round($savedDistance, 1),
            'incremental_cost' => $costService->cost($incrementalDistance),
            'standalone_cost' => $costService->cost($standaloneDistance),
            'cost_saved' => $costService->cost($savedDistance),
            'matrix_source' => $source,
            'anchor_count' => count($existingStops),
        ];
    }

    /**
     * Compares a candidate at its real position in an existing dated tour.
     * Confirmed stops keep their chronological order and the tour returns to
     * the depot only once, after the final stop.
     */
    public function compareDatedInsertion(array $existingStops, array $candidate, string $candidateDate, ?array $depot = null): array
    {
        $depot = $this->normalizePoint($depot ?? $this->defaultDepot());
        $existingStops = array_values(array_filter(array_map([$this, 'normalizeStop'], $existingStops)));
        $candidate = $this->normalizeStop($candidate);
        if (! $depot || ! $candidate || ! $this->validPlanningDate($candidateDate)) {
            return [];
        }

        usort($existingStops, fn (array $left, array $right): int => $this->planningDate($left) <=> $this->planningDate($right));
        $candidate['appointment_date'] = $candidateDate;
        $points = array_merge([$depot], $existingStops, [$candidate]);
        [$matrix, , $source] = $this->distanceMatrix($points);
        $existingIndexes = $existingStops ? range(1, count($existingStops)) : [];
        $candidateIndex = count($points) - 1;
        $insertionPosition = 0;
        foreach ($existingStops as $stop) {
            if ($this->planningDate($stop) <= $candidateDate) {
                $insertionPosition++;
            }
        }

        $beforeOrder = $existingIndexes;
        $afterOrder = $existingIndexes;
        array_splice($afterOrder, $insertionPosition, 0, [$candidateIndex]);
        $beforeDistance = $this->routeDistance($beforeOrder, $matrix);
        $afterDistance = $this->routeDistance($afterOrder, $matrix);
        $incrementalDistance = max(0.0, $afterDistance - $beforeDistance);
        $standaloneDistance = $this->edge($matrix, 0, $candidateIndex) + $this->edge($matrix, $candidateIndex, 0);
        $savedDistance = max(0.0, $standaloneDistance - $incrementalDistance);
        $previousIndex = $insertionPosition === 0 ? 0 : $existingIndexes[$insertionPosition - 1];
        $nextIndex = $insertionPosition === count($existingIndexes) ? 0 : $existingIndexes[$insertionPosition];
        $replacedLegDistance = $this->edge($matrix, $previousIndex, $nextIndex);
        $newLegsDistance = $this->edge($matrix, $previousIndex, $candidateIndex)
            + $this->edge($matrix, $candidateIndex, $nextIndex);
        $context = ! $existingStops
            ? 'direct_trip'
            : ($insertionPosition === 0
                ? 'tour_start'
                : ($insertionPosition === count($existingStops) ? 'return_route' : 'between_stops'));
        $costService = new CostService();

        return [
            'ordered_stops' => array_map(function (int $index) use ($existingStops, $candidate, $candidateIndex): array {
                return $index === $candidateIndex ? $candidate : $existingStops[$index - 1];
            }, $afterOrder),
            'route_distance_before_km' => round($beforeDistance, 1),
            'route_distance_after_km' => round($afterDistance, 1),
            'incremental_distance_km' => round($incrementalDistance, 1),
            'standalone_distance_km' => round($standaloneDistance, 1),
            'distance_saved_km' => round($savedDistance, 1),
            'replaced_leg_distance_km' => round($replacedLegDistance, 1),
            'new_legs_distance_km' => round($newLegsDistance, 1),
            'incremental_cost' => $costService->cost($incrementalDistance),
            'standalone_cost' => $costService->cost($standaloneDistance),
            'cost_saved' => $costService->cost($savedDistance),
            'matrix_source' => $source,
            'anchor_count' => count($existingStops),
            'insertion_position' => $insertionPosition,
            'insertion_context' => $context,
        ];
    }

    private function nearestNeighbour(array $indexes, array $matrix): array
    {
        $remaining = array_values($indexes);
        $order = [];
        $current = 0;
        while ($remaining) {
            usort($remaining, function (int $left, int $right) use ($matrix, $current): int {
                $comparison = $this->edge($matrix, $current, $left) <=> $this->edge($matrix, $current, $right);
                return $comparison !== 0 ? $comparison : $left <=> $right;
            });
            $next = array_shift($remaining);
            $order[] = $next;
            $current = $next;
        }
        return $order;
    }

    private function improveOrder(array $order, array $matrix): array
    {
        if (count($order) < 3) {
            return $order;
        }

        $best = $order;
        $bestDistance = $this->routeDistance($best, $matrix);
        $improved = true;
        $iterations = 0;
        while ($improved && $iterations < 40) {
            $improved = false;
            $iterations++;
            for ($left = 0; $left < count($best) - 1; $left++) {
                for ($right = $left + 1; $right < count($best); $right++) {
                    $candidate = array_merge(
                        array_slice($best, 0, $left),
                        array_reverse(array_slice($best, $left, $right - $left + 1)),
                        array_slice($best, $right + 1)
                    );
                    $distance = $this->routeDistance($candidate, $matrix);
                    if ($distance + 0.01 < $bestDistance) {
                        $best = $candidate;
                        $bestDistance = $distance;
                        $improved = true;
                    }
                }
            }
        }
        return $best;
    }

    private function routeDistance(array $order, array $matrix): float
    {
        if (! $order) {
            return 0.0;
        }
        $distance = 0.0;
        $previous = 0;
        foreach ($order as $index) {
            $distance += $this->edge($matrix, $previous, $index);
            $previous = $index;
        }
        return $distance + $this->edge($matrix, $previous, 0);
    }

    private function resultForOrder(array $order, array $matrix, array $durations, array $depot, array $stops, string $source): array
    {
        $routeDistance = $this->routeDistance($order, $matrix);
        $standaloneDistance = 0.0;
        foreach (range(1, count($stops)) as $index) {
            $standaloneDistance += $this->edge($matrix, 0, $index) + $this->edge($matrix, $index, 0);
        }
        $orderedStops = array_map(static fn (int $index): array => $stops[$index - 1], $order);
        $savedDistance = max(0.0, $standaloneDistance - $routeDistance);
        $costService = new CostService();

        return [
            'ordered_stops' => $orderedStops,
            'legs' => $this->routeLegs($order, $matrix, $durations, $depot, $stops),
            'route_distance_km' => round($routeDistance, 1),
            'route_duration_minutes' => round($this->routeDuration($order, $durations), 1),
            'standalone_distance_km' => round($standaloneDistance, 1),
            'distance_saved_km' => round($savedDistance, 1),
            'estimated_cost' => $costService->cost($routeDistance),
            'standalone_cost' => $costService->cost($standaloneDistance),
            'cost_saved' => $costService->cost($savedDistance),
            'savings_percent' => $standaloneDistance > 0 ? round(($savedDistance / $standaloneDistance) * 100, 1) : 0.0,
            'matrix_source' => $source,
            'stop_count' => count($orderedStops),
        ];
    }

    private function routeLegs(array $order, array $matrix, array $durations, array $depot, array $stops): array
    {
        if (! $order) {
            return [];
        }
        $sequence = array_merge([0], $order, [0]);
        $legs = [];
        for ($index = 0; $index < count($sequence) - 1; $index++) {
            $from = $sequence[$index];
            $to = $sequence[$index + 1];
            $legs[] = [
                'from' => $this->pointLabel($from === 0 ? $depot : $stops[$from - 1], $from === 0 ? 'Start' : 'Stopp ' . $from),
                'to' => $this->pointLabel($to === 0 ? $depot : $stops[$to - 1], $to === 0 ? 'Start' : 'Stopp ' . $to),
                'distance_km' => round($this->edge($matrix, $from, $to), 1),
                'duration_minutes' => round($this->edge($durations, $from, $to), 1),
            ];
        }
        return $legs;
    }

    private function pointLabel(array $point, string $fallback): string
    {
        foreach (['institution_name', 'public_city', 'city', 'label', 'start_label'] as $field) {
            if (trim((string) ($point[$field] ?? '')) !== '') {
                return trim((string) $point[$field]);
            }
        }
        return $fallback;
    }

    private function distanceMatrix(array $points): array
    {
        $coordinates = array_map(static fn (array $point): array => ['lat' => $point['latitude'], 'lon' => $point['longitude']], $points);
        $routing = $this->routing ?? (new ProviderFactory())->routing();
        $result = $routing->calculateMatrix($coordinates, $coordinates);
        if (! empty($result['ok']) && $this->validMatrix((array) ($result['distances'] ?? []), count($points))) {
            $distances = (array) $result['distances'];
            $durations = $this->validMatrix((array) ($result['durations'] ?? []), count($points))
                ? (array) $result['durations']
                : $this->durationMatrix($distances);
            $source = $routing instanceof \ProOceanVan\Routing\OpenRouteServiceRoutingProvider ? 'heigit' : 'osrm';
            return [$distances, $durations, $source];
        }

        $matrix = [];
        foreach ($points as $from) {
            $row = [];
            foreach ($points as $to) {
                $row[] = $this->haversine($from, $to) * self::ROAD_DISTANCE_FACTOR;
            }
            $matrix[] = $row;
        }
        return [$matrix, $this->durationMatrix($matrix), 'estimated'];
    }

    private function routeDuration(array $order, array $durations): float
    {
        if (! $order) {
            return 0.0;
        }
        $minutes = 0.0;
        $previous = 0;
        foreach ($order as $index) {
            $minutes += $this->edge($durations, $previous, $index);
            $previous = $index;
        }
        return $minutes + $this->edge($durations, $previous, 0);
    }

    private function durationMatrix(array $distances): array
    {
        $speed = max(20.0, (float) get_option('pov_average_driving_speed_kmh', 70));
        return array_map(static fn (array $row): array => array_map(
            static fn (mixed $distance): float => is_numeric($distance) ? ((float) $distance / $speed) * 60 : 0.0,
            $row
        ), $distances);
    }

    private function validMatrix(array $matrix, int $size): bool
    {
        if (count($matrix) !== $size) {
            return false;
        }
        foreach ($matrix as $row) {
            if (count((array) $row) !== $size) {
                return false;
            }
            foreach ((array) $row as $distance) {
                if (! is_numeric($distance)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function edge(array $matrix, int $from, int $to): float
    {
        $distance = $matrix[$from][$to] ?? null;
        return is_numeric($distance) ? max(0.0, (float) $distance) : 1000000.0;
    }

    private function planningDate(array $stop): string
    {
        foreach (['_planning_date', 'appointment_date', 'specific_requested_date'] as $field) {
            $date = (string) ($stop[$field] ?? '');
            if ($this->validPlanningDate($date)) {
                return $date;
            }
        }
        return '9999-12-31';
    }

    private function validPlanningDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function normalizeStop(mixed $stop): ?array
    {
        if (! is_array($stop)) {
            return null;
        }
        $point = $this->normalizePoint($stop);
        return $point ? array_merge($stop, $point) : null;
    }

    private function normalizePoint(mixed $point): ?array
    {
        if (! is_array($point)) {
            return null;
        }
        $latitude = $point['latitude'] ?? $point['lat'] ?? null;
        $longitude = $point['longitude'] ?? $point['lon'] ?? null;
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }
        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }
        $normalized = ['latitude' => $latitude, 'longitude' => $longitude];
        foreach (['label', 'start_label', 'institution_name', 'city', 'public_city'] as $field) {
            if (trim((string) ($point[$field] ?? '')) !== '') {
                $normalized[$field] = trim((string) $point[$field]);
            }
        }
        return $normalized;
    }

    private function defaultDepot(): array
    {
        return [
            'latitude' => get_option('pov_default_start_latitude'),
            'longitude' => get_option('pov_default_start_longitude'),
            'label' => (string) get_option('pov_default_start_label', 'Depot'),
        ];
    }

    private function haversine(array $from, array $to): float
    {
        $earthKm = 6371.0;
        $latitudeDelta = deg2rad($to['latitude'] - $from['latitude']);
        $longitudeDelta = deg2rad($to['longitude'] - $from['longitude']);
        $value = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($from['latitude'])) * cos(deg2rad($to['latitude'])) * sin($longitudeDelta / 2) ** 2;
        return $earthKm * 2 * atan2(sqrt($value), sqrt(1 - $value));
    }

    private function emptyResult(array $stops): array
    {
        return [
            'ordered_stops' => $stops,
            'legs' => [],
            'route_distance_km' => 0.0,
            'route_duration_minutes' => 0.0,
            'standalone_distance_km' => 0.0,
            'distance_saved_km' => 0.0,
            'estimated_cost' => 0.0,
            'standalone_cost' => 0.0,
            'cost_saved' => 0.0,
            'savings_percent' => 0.0,
            'matrix_source' => 'unavailable',
            'stop_count' => count($stops),
        ];
    }
}
