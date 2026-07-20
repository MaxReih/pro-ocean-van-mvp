<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

interface GeocodingProviderInterface
{
    public function geocodeAddress(array $address): array;
}
