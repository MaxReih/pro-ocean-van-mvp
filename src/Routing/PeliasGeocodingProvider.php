<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

use ProOceanVan\Repository\RouteCacheRepository;

final class PeliasGeocodingProvider implements GeocodingProviderInterface
{
    public function __construct(private readonly string $baseUrl, private readonly string $apiKey)
    {
    }

    public function geocodeAddress(array $address): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            return ['ok' => false, 'error' => 'HeiGIT-Geocoding ist nicht vollständig konfiguriert.'];
        }
        $payload = [
            'postal_code' => preg_replace('/\D+/', '', (string) ($address['postal_code'] ?? '')),
            'city' => sanitize_text_field((string) ($address['city'] ?? '')),
            'street' => trim(sanitize_text_field((string) ($address['street'] ?? '')) . ' ' . sanitize_text_field((string) ($address['house_number'] ?? ''))),
            'state' => sanitize_text_field((string) ($address['state'] ?? '')),
            'country' => 'Germany',
        ];
        if ($payload['postal_code'] === '' && $payload['city'] === '') {
            return ['ok' => false, 'error' => 'Adresse unvollständig.'];
        }

        $cache = new RouteCacheRepository();
        $providerKey = 'pelias:' . substr(hash('sha256', $this->baseUrl), 0, 12);
        $cached = $cache->get($providerKey, 'geocode', $payload);
        if ($cached) {
            return $cached;
        }

        $url = add_query_arg(array_filter([
            'postalcode' => $payload['postal_code'],
            'locality' => $payload['city'],
            'address' => $payload['street'],
            'region' => $payload['state'],
            'country' => $payload['country'],
            'size' => 1,
        ], static fn (mixed $value): bool => $value !== ''), trailingslashit($this->baseUrl) . 'search/structured');
        $response = wp_remote_get($url, [
            'timeout' => 3,
            'redirection' => 2,
            'sslverify' => ! (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local'),
            'headers' => [
                'Authorization' => $this->apiKey,
                'User-Agent' => 'ProOceanVan/' . POV_VERSION . '; ' . home_url(),
            ],
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['ok' => false, 'error' => $this->httpError($code)];
        }
        $feature = (array) ($body['features'][0] ?? []);
        $coordinates = (array) ($feature['geometry']['coordinates'] ?? []);
        $properties = (array) ($feature['properties'] ?? []);
        if (! is_numeric($coordinates[0] ?? null) || ! is_numeric($coordinates[1] ?? null)) {
            return ['ok' => false, 'error' => 'HeiGIT-Geocoding ohne Treffer.'];
        }
        $layer = sanitize_key((string) ($properties['layer'] ?? ''));
        if ($payload['street'] !== '' && $layer !== '' && ! in_array($layer, ['address', 'venue', 'street'], true)) {
            return ['ok' => false, 'error' => 'Straße und Hausnummer konnten nicht eindeutig gefunden werden.'];
        }

        $resultPostalCode = preg_replace('/\D+/', '', (string) ($properties['postalcode'] ?? ''));
        if ($payload['postal_code'] !== '' && $resultPostalCode !== '' && $resultPostalCode !== $payload['postal_code']) {
            return ['ok' => false, 'error' => 'Geocoding-Treffer passt nicht zur PLZ.'];
        }
        if ($payload['postal_code'] !== '' && $payload['city'] === '' && $resultPostalCode === '' && ! in_array($layer, ['postalcode', 'postalcode_locality'], true)) {
            return ['ok' => false, 'error' => 'HeiGIT konnte die PLZ ohne Ortsangabe nicht eindeutig zuordnen.'];
        }
        $result = [
            'ok' => true,
            'latitude' => (float) $coordinates[1],
            'longitude' => (float) $coordinates[0],
            'label' => sanitize_text_field((string) ($properties['label'] ?? '')),
            'postal_code' => $resultPostalCode,
            'country_code' => 'de',
            'precision' => $layer,
        ];
        $cache->set($providerKey, 'geocode', $payload, $result, (int) get_option('pov_geocoding_cache_ttl', 30 * DAY_IN_SECONDS));
        return $result;
    }

    private function httpError(int $code): string
    {
        return match ($code) {
            401, 403 => 'HeiGIT hat den API-Schlüssel abgelehnt oder nicht für Geocoding freigeschaltet.',
            429 => 'Das HeiGIT-Tages- oder Minutenkontingent ist erreicht.',
            default => 'HeiGIT-Geocoding antwortet mit HTTP ' . $code . '.',
        };
    }
}
