<?php

declare(strict_types=1);

use ProOceanVan\Routing\FallbackGeocodingProvider;
use ProOceanVan\Routing\FallbackRoutingProvider;
use ProOceanVan\Routing\GeocodingProviderInterface;
use ProOceanVan\Routing\RouteResult;
use ProOceanVan\Routing\RoutingProviderInterface;

$transients = [];

function get_transient(string $key): mixed
{
    global $transients;
    return $transients[$key] ?? false;
}

function set_transient(string $key, mixed $value, int $expiration): bool
{
    global $transients;
    $transients[$key] = $value;
    return true;
}

function delete_transient(string $key): bool
{
    global $transients;
    unset($transients[$key]);
    return true;
}

function expect_true(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "OK: {$message}\n";
}

require_once dirname(__DIR__) . '/src/Routing/RoutingProviderInterface.php';
require_once dirname(__DIR__) . '/src/Routing/GeocodingProviderInterface.php';
require_once dirname(__DIR__) . '/src/Routing/RouteResult.php';
require_once dirname(__DIR__) . '/src/Routing/FallbackRoutingProvider.php';
require_once dirname(__DIR__) . '/src/Routing/FallbackGeocodingProvider.php';

$primaryRoutingCalls = (object) ['count' => 0];
$fallbackRoutingCalls = (object) ['count' => 0];
$primaryRouting = new class ($primaryRoutingCalls) implements RoutingProviderInterface {
    public function __construct(private readonly object $calls) {}
    public function calculateRoute(array $coordinates): RouteResult
    {
        $this->calls->count++;
        return RouteResult::failure('Zeitüberschreitung');
    }
    public function calculateMatrix(array $sources, array $destinations): array
    {
        $this->calls->count++;
        return ['ok' => false, 'distances' => [], 'error' => 'Zeitüberschreitung'];
    }
};
$fallbackRouting = new class ($fallbackRoutingCalls) implements RoutingProviderInterface {
    public function __construct(private readonly object $calls) {}
    public function calculateRoute(array $coordinates): RouteResult
    {
        $this->calls->count++;
        return new RouteResult(true, 42.0, 45.0);
    }
    public function calculateMatrix(array $sources, array $destinations): array
    {
        $this->calls->count++;
        return ['ok' => true, 'distances' => [[0.0, 42.0]], 'durations' => [[0.0, 45.0]]];
    }
};

$routing = new FallbackRoutingProvider($primaryRouting, $fallbackRouting, 'test_routing_circuit', 300);
$matrix = $routing->calculateMatrix([['lat' => 1, 'lon' => 1]], [['lat' => 2, 'lon' => 2]]);
expect_true(! empty($matrix['ok']) && ! empty($matrix['fallback']), 'routing falls back after a primary failure');
expect_true($primaryRoutingCalls->count === 1 && $fallbackRoutingCalls->count === 1, 'first routing request tries both providers');
$routing->calculateMatrix([['lat' => 1, 'lon' => 1]], [['lat' => 2, 'lon' => 2]]);
expect_true($primaryRoutingCalls->count === 1 && $fallbackRoutingCalls->count === 2, 'open circuit skips repeated primary routing calls');

$primaryGeocodingCalls = (object) ['count' => 0];
$fallbackGeocodingCalls = (object) ['count' => 0];
$primaryGeocoding = new class ($primaryGeocodingCalls) implements GeocodingProviderInterface {
    public function __construct(private readonly object $calls) {}
    public function geocodeAddress(array $address): array
    {
        $this->calls->count++;
        return ['ok' => false, 'error' => 'Zeitüberschreitung'];
    }
};
$fallbackGeocoding = new class ($fallbackGeocodingCalls) implements GeocodingProviderInterface {
    public function __construct(private readonly object $calls) {}
    public function geocodeAddress(array $address): array
    {
        $this->calls->count++;
        return ['ok' => true, 'latitude' => 52.52, 'longitude' => 13.405, 'postal_code' => '10721'];
    }
};

$geocoding = new FallbackGeocodingProvider($primaryGeocoding, $fallbackGeocoding, 'test_geocoding_circuit', 300);
$geo = $geocoding->geocodeAddress(['postal_code' => '10721']);
expect_true(! empty($geo['ok']) && ! empty($geo['fallback']), 'geocoding falls back after a primary failure');
$geocoding->geocodeAddress(['postal_code' => '10721']);
expect_true($primaryGeocodingCalls->count === 1 && $fallbackGeocodingCalls->count === 2, 'open circuit skips repeated primary geocoding calls');

echo "Provider fallback checks passed.\n";
