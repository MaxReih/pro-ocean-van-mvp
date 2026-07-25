<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

use ProOceanVan\Repository\RouteCacheRepository;

final class NominatimGeocodingProvider implements GeocodingProviderInterface
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    public function geocodeAddress(array $address): array
    {
        if ($this->baseUrl === '') {
            return ['ok' => false, 'error' => 'Geocoding Basis-URL fehlt.'];
        }

        $payload = [
            'postal_code' => preg_replace('/\D+/', '', (string) ($address['postal_code'] ?? '')),
            'city' => sanitize_text_field((string) ($address['city'] ?? '')),
            'street' => trim(sanitize_text_field((string) ($address['street'] ?? '')) . ' ' . sanitize_text_field((string) ($address['house_number'] ?? ''))),
            'state_code' => strtoupper(sanitize_key((string) ($address['state_code'] ?? ''))),
            'country' => 'Deutschland',
        ];
        if ($payload['postal_code'] === '' && $payload['city'] === '') {
            return ['ok' => false, 'error' => 'Adresse unvollständig.'];
        }

        $cache = new RouteCacheRepository();
        $providerKey = 'nominatim:' . substr(hash('sha256', $this->baseUrl), 0, 12);
        $cached = $cache->get($providerKey, 'geocode', $payload);
        if ($cached) {
            return $cached;
        }

        $url = add_query_arg([
            'format' => 'jsonv2',
            'limit' => 1,
            'postalcode' => $payload['postal_code'],
            'city' => $payload['city'],
            'street' => $payload['street'],
            'country' => $payload['country'],
            'countrycodes' => 'de',
            'addressdetails' => 1,
        ], trailingslashit($this->baseUrl) . 'search');

        $response = wp_remote_get($url, [
            'timeout' => 5,
            'redirection' => 2,
            // LocalWP's bundled PHP can lack the system CA bundle. Keep TLS
            // verification active everywhere except the explicitly local setup.
            'sslverify' => ! (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local'),
            'user-agent' => 'ProOceanVan/' . POV_VERSION . '; ' . home_url(),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || ! is_array($body) || empty($body[0]['lat']) || empty($body[0]['lon'])) {
            return ['ok' => false, 'error' => 'Geocoding ohne Treffer.'];
        }

        $resultAddress = (array) ($body[0]['address'] ?? []);
        $resultPostalCode = preg_replace('/\D+/', '', (string) ($resultAddress['postcode'] ?? ''));
        if ($payload['postal_code'] !== '' && $resultPostalCode !== '' && $resultPostalCode !== $payload['postal_code']) {
            return ['ok' => false, 'error' => 'Geocoding-Treffer passt nicht zur PLZ.'];
        }

        $result = [
            'ok' => true,
            'latitude' => (float) $body[0]['lat'],
            'longitude' => (float) $body[0]['lon'],
            'label' => sanitize_text_field((string) ($body[0]['display_name'] ?? '')),
            'postal_code' => $resultPostalCode,
            'country_code' => strtolower(sanitize_key((string) ($resultAddress['country_code'] ?? 'de'))),
        ];
        $cache->set($providerKey, 'geocode', $payload, $result, (int) get_option('pov_geocoding_cache_ttl', 30 * DAY_IN_SECONDS));

        return $result;
    }
}
