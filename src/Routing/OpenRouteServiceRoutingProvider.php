<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

use ProOceanVan\Repository\RouteCacheRepository;

final class OpenRouteServiceRoutingProvider implements RoutingProviderInterface
{
    public function __construct(private readonly string $baseUrl, private readonly string $apiKey)
    {
    }

    public function calculateRoute(array $coordinates): RouteResult
    {
        if (! $this->configured() || count($coordinates) < 2 || ! $this->validPoints($coordinates)) {
            return RouteResult::failure('HeiGIT-Routing ist nicht vollständig konfiguriert.');
        }

        $payload = ['coordinates' => $coordinates];
        $cache = new RouteCacheRepository();
        $providerKey = $this->providerKey();
        $cached = $cache->get($providerKey, 'route', $payload);
        if ($cached) {
            return new RouteResult((bool) $cached['ok'], (float) $cached['distance_km'], $cached['duration_minutes'] ?? null, (string) ($cached['error'] ?? ''));
        }

        $response = wp_remote_post(trailingslashit($this->baseUrl) . 'v2/directions/driving-car', $this->httpArgs([
            'coordinates' => array_map(static fn (array $point): array => [(float) $point['lon'], (float) $point['lat']], $coordinates),
        ]));
        if (is_wp_error($response)) {
            return RouteResult::failure($response->get_error_message());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $summary = (array) ($body['routes'][0]['summary'] ?? []);
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200 || ! isset($summary['distance'])) {
            return RouteResult::failure($this->httpError($code, 'Routing'));
        }

        $result = new RouteResult(
            true,
            round((float) $summary['distance'] / 1000, 2),
            isset($summary['duration']) ? round((float) $summary['duration'] / 60, 1) : null
        );
        $cache->set($providerKey, 'route', $payload, [
            'ok' => true,
            'distance_km' => $result->distanceKm,
            'duration_minutes' => $result->durationMinutes,
        ], (int) get_option('pov_route_cache_ttl', WEEK_IN_SECONDS));
        return $result;
    }

    public function calculateMatrix(array $sources, array $destinations): array
    {
        if (! $this->configured() || ! $sources || ! $destinations || ! $this->validPoints(array_merge($sources, $destinations))) {
            return ['ok' => false, 'distances' => [], 'error' => 'HeiGIT-Matrix ist nicht vollständig konfiguriert.'];
        }

        $payload = ['sources' => $sources, 'destinations' => $destinations];
        $cache = new RouteCacheRepository();
        $providerKey = $this->providerKey();
        $cached = $cache->get($providerKey, 'matrix', $payload);
        if ($cached) {
            return $cached;
        }

        $samePoints = wp_json_encode($sources) === wp_json_encode($destinations);
        $points = $samePoints ? array_values($sources) : array_values(array_merge($sources, $destinations));
        $body = [
            'locations' => array_map(static fn (array $point): array => [(float) $point['lon'], (float) $point['lat']], $points),
            'metrics' => ['distance', 'duration'],
            'units' => 'km',
        ];
        if (! $samePoints) {
            $body['sources'] = array_map('strval', range(0, count($sources) - 1));
            $body['destinations'] = array_map('strval', range(count($sources), count($points) - 1));
        }

        $response = wp_remote_post(trailingslashit($this->baseUrl) . 'v2/matrix/driving-car', $this->httpArgs($body));
        if (is_wp_error($response)) {
            return ['ok' => false, 'distances' => [], 'error' => $response->get_error_message()];
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200 || ! is_array($decoded['distances'] ?? null)) {
            return ['ok' => false, 'distances' => [], 'error' => $this->httpError($code, 'Fahrzeitmatrix')];
        }

        $distances = array_map(static fn (array $row): array => array_map(
            static fn (mixed $kilometers): ?float => is_numeric($kilometers) ? round((float) $kilometers, 2) : null,
            $row
        ), $decoded['distances']);
        $durations = array_map(static fn (array $row): array => array_map(
            static fn (mixed $seconds): ?float => is_numeric($seconds) ? round((float) $seconds / 60, 1) : null,
            $row
        ), (array) ($decoded['durations'] ?? []));
        $result = ['ok' => true, 'distances' => $distances, 'durations' => $durations, 'unit' => 'km'];
        $cache->set($providerKey, 'matrix', $payload, $result, (int) get_option('pov_route_cache_ttl', WEEK_IN_SECONDS));
        return $result;
    }

    private function httpArgs(array $body): array
    {
        return [
            'timeout' => 6,
            'redirection' => 2,
            'sslverify' => ! (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local'),
            'headers' => [
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'ProOceanVan/' . POV_VERSION . '; ' . home_url(),
            ],
            'body' => wp_json_encode($body),
        ];
    }

    private function configured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    private function validPoints(array $points): bool
    {
        foreach ($points as $point) {
            if (! is_array($point)
                || ! is_numeric($point['lat'] ?? null)
                || ! is_numeric($point['lon'] ?? null)
                || (float) $point['lat'] < -90 || (float) $point['lat'] > 90
                || (float) $point['lon'] < -180 || (float) $point['lon'] > 180) {
                return false;
            }
        }
        return true;
    }

    private function providerKey(): string
    {
        return 'openrouteservice:' . substr(hash('sha256', $this->baseUrl), 0, 12);
    }

    private function httpError(int $code, string $service): string
    {
        return match ($code) {
            401, 403 => 'HeiGIT hat den API-Schlüssel abgelehnt oder nicht für ' . $service . ' freigeschaltet.',
            429 => 'Das HeiGIT-Tages- oder Minutenkontingent ist erreicht.',
            default => 'HeiGIT-' . $service . ' antwortet mit HTTP ' . $code . '.',
        };
    }
}
