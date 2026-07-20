<?php

declare(strict_types=1);

function get_option(string $name, mixed $default = false): mixed
{
    return $name === 'pov_kilometer_rate' ? '0.85' : $default;
}

require_once dirname(__DIR__) . '/src/Routing/RoutingProviderInterface.php';
require_once dirname(__DIR__) . '/src/Routing/GeocodingProviderInterface.php';
require_once dirname(__DIR__) . '/src/Routing/RouteResult.php';
require_once dirname(__DIR__) . '/src/Routing/NullRoutingProvider.php';
require_once dirname(__DIR__) . '/src/Service/CostService.php';
require_once dirname(__DIR__) . '/src/Service/RouteOptimizationService.php';

use ProOceanVan\Routing\NullRoutingProvider;
use ProOceanVan\Service\RouteOptimizationService;

function expect_true(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "OK: {$message}\n";
}

$depot = ['latitude' => 48.5216, 'longitude' => 9.0576];
$stops = [
    ['id' => 1, 'latitude' => 48.5900, 'longitude' => 9.1400, 'city' => 'Stop A'],
    ['id' => 2, 'latitude' => 48.6400, 'longitude' => 9.2200, 'city' => 'Stop B'],
    ['id' => 3, 'latitude' => 48.7000, 'longitude' => 9.3000, 'city' => 'Stop C'],
];

$service = new RouteOptimizationService(new NullRoutingProvider());
$plan = $service->optimize($stops, $depot);
expect_true(count($plan['ordered_stops']) === 3, 'all routable stops are retained');
expect_true($plan['route_distance_km'] < $plan['standalone_distance_km'], 'a bundled route is shorter than individual return trips');
expect_true($plan['cost_saved'] > 0, 'distance savings become cost savings');
expect_true($plan['matrix_source'] === 'estimated', 'routing outage has a deterministic geographic fallback');
expect_true(count($plan['legs']) === 4, 'each route section including the return is exposed');

$fixedPlan = $service->optimizeWithFixedOrder([$stops[0], $stops[2]], [$stops[1]], $depot);
$fixedIds = array_column($fixedPlan['ordered_stops'], 'id');
expect_true(array_search(1, $fixedIds, true) < array_search(3, $fixedIds, true), 'confirmed appointments keep their chronological order');
expect_true(count($fixedPlan['ordered_stops']) === 3, 'flexible requests are inserted into the fixed route');

$comparison = $service->compareInsertion([$stops[0]], $stops[1], $depot);
expect_true($comparison['incremental_distance_km'] < $comparison['standalone_distance_km'], 'insertion cost is lower near an existing tour stop');
expect_true($comparison['cost_saved'] > 0, 'insertion comparison exposes saved variable cost');

$datedStops = [
    array_merge($stops[0], ['appointment_date' => '2026-08-03']),
    array_merge($stops[1], ['appointment_date' => '2026-08-05']),
];
$returnInsertion = $service->compareDatedInsertion($datedStops, $stops[2], '2026-08-06', $depot);
expect_true(array_column($returnInsertion['ordered_stops'], 'id') === [1, 2, 3], 'a proposal keeps confirmed stops in chronological order');
expect_true($returnInsertion['insertion_context'] === 'return_route', 'a proposal after the last stop extends the return route');
expect_true($returnInsertion['insertion_position'] === 2, 'the proposal is inserted after the final confirmed appointment');
expect_true(
    abs(($returnInsertion['new_legs_distance_km'] - $returnInsertion['replaced_leg_distance_km']) - $returnInsertion['incremental_distance_km']) < 0.2,
    'incremental distance replaces the old final return leg'
);

$betweenInsertion = $service->compareDatedInsertion($datedStops, $stops[2], '2026-08-04', $depot);
expect_true(array_column($betweenInsertion['ordered_stops'], 'id') === [1, 3, 2], 'a proposal between confirmed dates is evaluated between those stops');
expect_true($betweenInsertion['insertion_context'] === 'between_stops', 'the optimizer reports an insertion between tour stops');

echo "Route optimization checks passed.\n";
