<?php

declare(strict_types=1);

namespace ProOceanVan\Repository {
    final class RouteCacheRepository
    {
        public function get(string $provider, string $type, array $payload): ?array { return null; }
        public function set(string $provider, string $type, array $payload, array $response, int $ttl): void {}
    }
}

namespace {
    define('POV_VERSION', 'test');
    define('WEEK_IN_SECONDS', 604800);
    define('DAY_IN_SECONDS', 86400);
    $lastHttpRequest = [];

    function get_option(string $name, mixed $default = false): mixed { return $default; }
    function home_url(): string { return 'https://example.test'; }
    function trailingslashit(string $value): string { return rtrim($value, '/') . '/'; }
    function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    function is_wp_error(mixed $value): bool { return false; }
    function wp_remote_retrieve_response_code(array $response): int { return (int) $response['response']['code']; }
    function wp_remote_retrieve_body(array $response): string { return (string) $response['body']; }
    function sanitize_text_field(string $value): string { return trim($value); }
    function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $value)); }
    function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
    function wp_remote_post(string $url, array $args): array
    {
        global $lastHttpRequest;
        $lastHttpRequest = compact('url', 'args');
        if (str_contains($url, '/matrix/')) {
            return ['response' => ['code' => 200], 'body' => json_encode([
                'distances' => [[0, 12.5], [12.7, 0]],
                'durations' => [[0, 900], [960, 0]],
            ])];
        }
        return ['response' => ['code' => 200], 'body' => json_encode([
            'routes' => [['summary' => ['distance' => 12500, 'duration' => 900]]],
        ])];
    }
    function wp_remote_get(string $url, array $args): array
    {
        global $lastHttpRequest;
        $lastHttpRequest = compact('url', 'args');
        if (str_contains($url, 'postalcode=73312') && ! str_contains($url, 'locality=')) {
            return ['response' => ['code' => 200], 'body' => json_encode([
                'features' => [[
                    'geometry' => ['coordinates' => [10.5, 51.5]],
                    'properties' => ['label' => 'Germany', 'postalcode' => '', 'layer' => 'country'],
                ]],
            ])];
        }
        return ['response' => ['code' => 200], 'body' => json_encode([
            'features' => [[
                'geometry' => ['coordinates' => [9.18, 48.78]],
                'properties' => ['label' => '70173 Stuttgart, Deutschland', 'postalcode' => '70173', 'layer' => 'address'],
            ]],
        ])];
    }

    function expect_true(bool $condition, string $message): void
    {
        if (! $condition) throw new RuntimeException($message);
        echo "OK: {$message}\n";
    }

    require_once dirname(__DIR__) . '/src/Routing/RoutingProviderInterface.php';
    require_once dirname(__DIR__) . '/src/Routing/GeocodingProviderInterface.php';
    require_once dirname(__DIR__) . '/src/Routing/RouteResult.php';
    require_once dirname(__DIR__) . '/src/Routing/OpenRouteServiceRoutingProvider.php';
    require_once dirname(__DIR__) . '/src/Routing/PeliasGeocodingProvider.php';

    $points = [
        ['lat' => 48.52, 'lon' => 9.05],
        ['lat' => 48.78, 'lon' => 9.18],
    ];
    $routing = new ProOceanVan\Routing\OpenRouteServiceRoutingProvider('https://api.heigit.org/openrouteservice/', 'secret-key');
    $matrix = $routing->calculateMatrix($points, $points);
    expect_true($matrix['ok'] && $matrix['distances'][0][1] === 12.5, 'matrix distances stay in kilometres');
    expect_true($matrix['durations'][0][1] === 15.0, 'matrix durations become minutes');
    expect_true(str_contains($lastHttpRequest['url'], 'api.heigit.org/openrouteservice/v2/matrix/driving-car'), 'matrix uses the current HeiGIT endpoint');
    expect_true($lastHttpRequest['args']['headers']['Authorization'] === 'secret-key', 'API key stays in the authorization header');

    $route = $routing->calculateRoute($points);
    expect_true($route->ok && $route->distanceKm === 12.5 && $route->durationMinutes === 15.0, 'route summary units are normalized');

    $geocoding = new ProOceanVan\Routing\PeliasGeocodingProvider('https://api.heigit.org/pelias/v1/', 'secret-key');
    $result = $geocoding->geocodeAddress(['postal_code' => '70173', 'city' => 'Stuttgart']);
    expect_true($result['ok'] && $result['latitude'] === 48.78 && $result['longitude'] === 9.18, 'Pelias coordinates are mapped to latitude and longitude');
    expect_true(str_contains($lastHttpRequest['url'], 'api.heigit.org/pelias/v1/search/structured'), 'geocoding uses the current HeiGIT Pelias endpoint');
    expect_true(! str_contains($lastHttpRequest['url'], 'secret-key'), 'API key is never placed in the URL');

    $ambiguous = $geocoding->geocodeAddress(['postal_code' => '73312']);
    expect_true(empty($ambiguous['ok']), 'country centroid is rejected for a postal-code-only lookup');

    echo "HeiGIT provider checks passed.\n";
}
