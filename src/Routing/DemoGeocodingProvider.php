<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

/**
 * Deterministic, network-free postcode coordinates for the public MVP.
 * Never selected by normal installations.
 */
final class DemoGeocodingProvider implements GeocodingProviderInterface
{
    private const STATE_CENTRES = [
        'SH' => [54.22, 9.70], 'HH' => [53.55, 9.99], 'NI' => [52.64, 9.85], 'HB' => [53.08, 8.80],
        'NW' => [51.43, 7.66], 'HE' => [50.61, 9.03], 'RP' => [49.91, 7.45], 'BW' => [48.66, 9.05],
        'BY' => [48.95, 11.40], 'SL' => [49.38, 6.95], 'BE' => [52.52, 13.41], 'BB' => [52.41, 13.06],
        'MV' => [53.61, 12.43], 'SN' => [51.05, 13.36], 'ST' => [52.01, 11.70], 'TH' => [50.90, 11.03],
    ];

    private const KNOWN_POSTCODES = [
        '48143' => [51.9625, 7.6256, 'Münster'],
        '54290' => [49.7499, 6.6371, 'Trier'],
        '66111' => [49.2402, 6.9969, 'Saarbrücken'],
        '70178' => [48.7672, 9.1685, 'Stuttgart'],
        '70180' => [48.7643, 9.1681, 'Stuttgart'],
        '72072' => [48.5033, 9.0537, 'Tübingen'],
        '73312' => [48.6242, 9.8274, 'Geislingen an der Steige'],
        '90403' => [49.4559, 11.0786, 'Nürnberg'],
        '96047' => [49.8917, 10.8860, 'Bamberg'],
    ];

    public function geocodeAddress(array $address): array
    {
        $postalCode = preg_replace('/\D+/', '', (string) ($address['postal_code'] ?? ''));
        $stateCode = strtoupper(sanitize_key((string) ($address['state_code'] ?? '')));
        if (! preg_match('/^\d{5}$/', $postalCode) || ! isset(self::STATE_CENTRES[$stateCode])) {
            return ['ok' => false, 'error' => 'Demo-Adresse unvollständig.'];
        }

        if (isset(self::KNOWN_POSTCODES[$postalCode])) {
            [$latitude, $longitude, $city] = self::KNOWN_POSTCODES[$postalCode];
        } else {
            [$latitude, $longitude] = self::STATE_CENTRES[$stateCode];
            $seed = (int) substr($postalCode, -3);
            $latitude += (($seed % 29) - 14) * 0.012;
            $longitude += ((intdiv($seed, 29) % 29) - 14) * 0.018;
            $city = sanitize_text_field((string) ($address['city'] ?? '')) ?: 'Einsatzort ' . $postalCode;
        }

        return [
            'ok' => true,
            'latitude' => round($latitude, 6),
            'longitude' => round($longitude, 6),
            'label' => $city . ', ' . $postalCode,
            'postal_code' => $postalCode,
            'country_code' => 'de',
            'precision' => 'demo_postcode',
        ];
    }
}
