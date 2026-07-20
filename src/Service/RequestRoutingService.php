<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Routing\ProviderFactory;

final class RequestRoutingService
{
    private string $lastError = '';

    public function enrich(array $payload): array
    {
        $this->lastError = '';
        unset(
            $payload['latitude'],
            $payload['longitude'],
            $payload['route_distance_km'],
            $payload['route_cost'],
            $payload['_server_latitude'],
            $payload['_server_longitude'],
            $payload['_server_route_distance_km'],
            $payload['_server_route_cost']
        );

        $factory = new ProviderFactory();
        $geocoded = $factory->geocoding()->geocodeAddress([
            'street' => (string) ($payload['street'] ?? ''),
            'house_number' => (string) ($payload['house_number'] ?? ''),
            'postal_code' => (string) ($payload['postal_code'] ?? ''),
            'city' => (string) ($payload['city'] ?? ''),
            'state_code' => (string) ($payload['state_code'] ?? ''),
        ]);
        if (empty($geocoded['ok']) || ! is_numeric($geocoded['latitude'] ?? null) || ! is_numeric($geocoded['longitude'] ?? null)) {
            $this->lastError = (string) ($geocoded['error'] ?? 'Adresse konnte nicht geprüft werden.');
            return $payload;
        }

        $payload['_server_latitude'] = (float) $geocoded['latitude'];
        $payload['_server_longitude'] = (float) $geocoded['longitude'];
        $start = $this->startPoint($payload);
        if (! $start) {
            $this->lastError = 'Der Standardstartpunkt hat noch keine Koordinaten.';
            return $payload;
        }

        $route = $factory->routing()->calculateRoute([
            ['lat' => $start['lat'], 'lon' => $start['lon']],
            ['lat' => $payload['_server_latitude'], 'lon' => $payload['_server_longitude']],
            ['lat' => $start['lat'], 'lon' => $start['lon']],
        ]);
        if (! $route->ok) {
            $this->lastError = $route->error ?: 'Die Fahrstrecke konnte nicht berechnet werden.';
            return $payload;
        }

        $payload['_server_route_distance_km'] = $route->distanceKm;
        $payload['_server_route_cost'] = (new CostService())->cost($route->distanceKm);
        return $payload;
    }

    public function refreshRequest(int $requestId): ?array
    {
        $repository = new RequestRepository();
        $request = $repository->find($requestId);
        if (! $request) {
            return null;
        }

        $enriched = $this->enrich($request);
        if (! isset($enriched['_server_latitude'], $enriched['_server_longitude'])) {
            return $request;
        }

        $repository->updateRoutingData($requestId, [
            'latitude' => $enriched['_server_latitude'],
            'longitude' => $enriched['_server_longitude'],
            'distance_km' => $enriched['_server_route_distance_km'] ?? null,
            'cost' => $enriched['_server_route_cost'] ?? null,
        ]);
        return $repository->find($requestId);
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    private function startPoint(array $payload): ?array
    {
        $date = ($payload['request_mode'] ?? '') === 'specific_date' ? (string) ($payload['specific_requested_date'] ?? '') : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $days = (new CalendarDayRepository())->forRange($date, $date);
            $day = $days[$date] ?? null;
            if ($day && is_numeric($day['custom_start_latitude'] ?? null) && is_numeric($day['custom_start_longitude'] ?? null)) {
                return ['lat' => (float) $day['custom_start_latitude'], 'lon' => (float) $day['custom_start_longitude']];
            }
        }

        if (! is_numeric(get_option('pov_default_start_latitude')) || ! is_numeric(get_option('pov_default_start_longitude'))) {
            return null;
        }
        return ['lat' => (float) get_option('pov_default_start_latitude'), 'lon' => (float) get_option('pov_default_start_longitude')];
    }
}
