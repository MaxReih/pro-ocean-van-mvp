<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 4) . '/wp-load.php';

$factory = new \ProOceanVan\Routing\ProviderFactory();
$geocode = $factory->geocoding()->geocodeAddress([
    'postal_code' => '70173',
    'city' => 'Stuttgart',
    'country' => 'Deutschland',
]);
$matrix = $factory->routing()->calculateMatrix(
    [['lat' => 48.5216, 'lon' => 9.0576], ['lat' => 48.7758, 'lon' => 9.1829]],
    [['lat' => 48.5216, 'lon' => 9.0576], ['lat' => 48.7758, 'lon' => 9.1829]]
);
$heigitMatrix = (new \ProOceanVan\Routing\OpenRouteServiceRoutingProvider(
    'https://api.heigit.org/openrouteservice/',
    (string) get_option('pov_heigit_api_key', '')
))->calculateMatrix(
    [['lat' => 48.5216, 'lon' => 9.0576], ['lat' => 48.7758, 'lon' => 9.1829]],
    [['lat' => 48.5216, 'lon' => 9.0576], ['lat' => 48.7758, 'lon' => 9.1829]]
);
$summary = [
    'routing_provider' => (string) get_option('pov_routing_provider'),
    'geocoding_provider' => (string) get_option('pov_geocoding_provider'),
    'routing_ok' => ! empty($matrix['ok']) && is_numeric($matrix['distances'][0][1] ?? null) && is_numeric($matrix['durations'][0][1] ?? null),
    'geocoding_ok' => ! empty($geocode['ok']),
    'routing_error' => (string) ($matrix['error'] ?? ''),
    'geocoding_error' => (string) ($geocode['error'] ?? ''),
    'distance' => $matrix['distances'][0][1] ?? null,
    'duration' => $matrix['durations'][0][1] ?? null,
    'checked_at' => current_time('mysql'),
];
if (in_array('--store', $argv, true)) {
    $users = get_users(['role' => 'administrator', 'number' => 1]);
    if ($users) {
        set_transient('pov_geo_test_' . (int) $users[0]->ID, $summary, 10 * MINUTE_IN_SECONDS);
    }
}

echo wp_json_encode([
    'routing_provider' => (string) get_option('pov_routing_provider'),
    'geocoding_provider' => (string) get_option('pov_geocoding_provider'),
    'api_key_present' => trim((string) get_option('pov_heigit_api_key', '')) !== '',
    'geocoding' => $geocode,
    'matrix' => [
        'ok' => ! empty($matrix['ok']),
        'distance' => $matrix['distances'][0][1] ?? null,
        'duration' => $matrix['durations'][0][1] ?? null,
        'error' => (string) ($matrix['error'] ?? ''),
    ],
    'heigit_matrix' => [
        'ok' => ! empty($heigitMatrix['ok']),
        'distance' => $heigitMatrix['distances'][0][1] ?? null,
        'duration' => $heigitMatrix['durations'][0][1] ?? null,
        'error' => (string) ($heigitMatrix['error'] ?? ''),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
