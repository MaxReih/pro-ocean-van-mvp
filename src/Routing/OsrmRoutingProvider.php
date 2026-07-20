<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

use ProOceanVan\Repository\RouteCacheRepository;

final class OsrmRoutingProvider implements RoutingProviderInterface
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    public function calculateRoute(array $coordinates): RouteResult
    {
        if ($this->baseUrl === '' || count($coordinates) < 2) {
            return RouteResult::failure('OSRM Basis-URL oder Koordinaten fehlen.');
        }
        foreach ($coordinates as $point) {
            if (! $this->validPoint($point)) {
                return RouteResult::failure('Ungültige Routing-Koordinaten.');
            }
        }

        $cache = new RouteCacheRepository();
        $providerKey = 'osrm:' . substr(hash('sha256', $this->baseUrl), 0, 12);
        $payload = ['coordinates' => $coordinates];
        $cached = $cache->get($providerKey, 'route', $payload);
        if ($cached) {
            return new RouteResult((bool) $cached['ok'], (float) $cached['distance_km'], $cached['duration_minutes'] ?? null, (string) ($cached['error'] ?? ''));
        }

        $path = implode(';', array_map([$this, 'coordinatePathPart'], $coordinates));
        $url = trailingslashit($this->baseUrl) . 'route/v1/driving/' . $path . '?overview=false';
        $response = wp_remote_get($url, $this->httpArgs());
        if (is_wp_error($response)) {
            return RouteResult::failure($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || ! is_array($body) || empty($body['routes'][0]['distance'])) {
            return RouteResult::failure('OSRM-Antwort ungültig.');
        }

        $result = new RouteResult(true, round(((float) $body['routes'][0]['distance']) / 1000, 2), isset($body['routes'][0]['duration']) ? round(((float) $body['routes'][0]['duration']) / 60, 1) : null);
        $cache->set($providerKey, 'route', $payload, [
            'ok' => true,
            'distance_km' => $result->distanceKm,
            'duration_minutes' => $result->durationMinutes,
        ], (int) get_option('pov_route_cache_ttl', WEEK_IN_SECONDS));

        return $result;
    }

    public function calculateMatrix(array $sources, array $destinations): array
    {
        if ($this->baseUrl === '' || ! $sources || ! $destinations) {
            return ['ok' => false, 'distances' => [], 'error' => 'OSRM Basis-URL oder Koordinaten fehlen.'];
        }

        $points = array_values(array_merge($sources, $destinations));
        foreach ($points as $point) {
            if (! $this->validPoint($point)) {
                return ['ok' => false, 'distances' => [], 'error' => 'Ungültige Matrix-Koordinaten.'];
            }
        }

        $payload = ['sources' => $sources, 'destinations' => $destinations];
        $cache = new RouteCacheRepository();
        $providerKey = 'osrm:' . substr(hash('sha256', $this->baseUrl), 0, 12);
        $cached = $cache->get($providerKey, 'matrix', $payload);
        if ($cached) {
            return $cached;
        }

        $sourceIndexes = range(0, count($sources) - 1);
        $destinationIndexes = range(count($sources), count($points) - 1);
        $path = implode(';', array_map([$this, 'coordinatePathPart'], $points));
        $url = add_query_arg([
            'annotations' => 'distance,duration',
            'sources' => implode(';', $sourceIndexes),
            'destinations' => implode(';', $destinationIndexes),
        ], trailingslashit($this->baseUrl) . 'table/v1/driving/' . $path);

        $response = wp_remote_get($url, $this->httpArgs());
        if (is_wp_error($response)) {
            return ['ok' => false, 'distances' => [], 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || ! is_array($body) || ! isset($body['distances']) || ! is_array($body['distances'])) {
            return ['ok' => false, 'distances' => [], 'error' => 'OSRM-Matrixantwort ungültig.'];
        }

        $distances = [];
        foreach ($body['distances'] as $row) {
            $distances[] = array_map(static fn ($meters): ?float => is_numeric($meters) ? round(((float) $meters) / 1000, 2) : null, (array) $row);
        }

        $durations = [];
        foreach ((array) ($body['durations'] ?? []) as $row) {
            $durations[] = array_map(static fn ($seconds): ?float => is_numeric($seconds) ? round(((float) $seconds) / 60, 1) : null, (array) $row);
        }

        $result = [
            'ok' => true,
            'distances' => $distances,
            'durations' => $durations,
            'unit' => 'km',
        ];
        $cache->set($providerKey, 'matrix', $payload, $result, (int) get_option('pov_route_cache_ttl', WEEK_IN_SECONDS));

        return $result;
    }

    private function httpArgs(): array
    {
        return [
            'timeout' => 8,
            'redirection' => 2,
            'user-agent' => 'ProOceanVan/' . POV_VERSION . '; ' . home_url(),
        ];
    }

    private function validPoint(array $point): bool
    {
        return isset($point['lat'], $point['lon'])
            && is_numeric($point['lat'])
            && is_numeric($point['lon'])
            && (float) $point['lat'] >= -90
            && (float) $point['lat'] <= 90
            && (float) $point['lon'] >= -180
            && (float) $point['lon'] <= 180;
    }

    private function coordinatePathPart(array $point): string
    {
        return rtrim(rtrim(number_format((float) $point['lon'], 7, '.', ''), '0'), '.')
            . ','
            . rtrim(rtrim(number_format((float) $point['lat'], 7, '.', ''), '0'), '.');
    }
}
