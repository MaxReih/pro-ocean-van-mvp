<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

final class NullRoutingProvider implements RoutingProviderInterface, GeocodingProviderInterface
{
    public function calculateRoute(array $coordinates): RouteResult
    {
        return RouteResult::failure('Routing nicht konfiguriert.');
    }

    public function calculateMatrix(array $sources, array $destinations): array
    {
        return ['ok' => false, 'distances' => [], 'durations' => [], 'error' => 'Routing nicht konfiguriert.'];
    }

    public function geocodeAddress(array $address): array
    {
        return ['ok' => false, 'error' => 'Geocoding nicht konfiguriert.'];
    }
}
