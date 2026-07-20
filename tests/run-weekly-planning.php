<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/wp-load.php';

add_filter('pre_http_request', static fn () => new WP_Error('offline', 'Offline test'));

$clusters = (new ProOceanVan\Service\WeeklyClusterService())->clusters();
$summary = array_map(static fn (array $cluster): array => [
    'title' => $cluster['title'],
    'requests' => $cluster['request_count'],
    'confirmed' => $cluster['confirmed_count'],
    'distance_km' => $cluster['route_distance_km'],
    'saved_km' => $cluster['distance_saved_km'],
    'stops' => array_map(static fn (array $stop): array => [
        'name' => $stop['institution_name'],
        'date' => $stop['_planning_date'] ?? '',
    ], $cluster['stops']),
    'legs' => $cluster['legs'],
], $clusters);

echo wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

