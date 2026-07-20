<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

final class ProviderFactory
{
    public function routing(): RoutingProviderInterface
    {
        if (get_option('pov_routing_provider') === 'heigit' && (string) get_option('pov_heigit_api_key') !== '') {
            return new OpenRouteServiceRoutingProvider(
                'https://api.heigit.org/openrouteservice/',
                (string) get_option('pov_heigit_api_key')
            );
        }
        if (get_option('pov_routing_provider') === 'osrm' && (string) get_option('pov_routing_base_url') !== '') {
            return new OsrmRoutingProvider((string) get_option('pov_routing_base_url'));
        }

        return new NullRoutingProvider();
    }

    public function geocoding(): GeocodingProviderInterface
    {
        if (get_option('pov_geocoding_provider') === 'heigit' && (string) get_option('pov_heigit_api_key') !== '') {
            return new PeliasGeocodingProvider(
                'https://api.heigit.org/pelias/v1/',
                (string) get_option('pov_heigit_api_key')
            );
        }
        if (get_option('pov_geocoding_provider') === 'nominatim' && (string) get_option('pov_geocoding_base_url') !== '') {
            return new NominatimGeocodingProvider((string) get_option('pov_geocoding_base_url'));
        }

        return new NullRoutingProvider();
    }
}
