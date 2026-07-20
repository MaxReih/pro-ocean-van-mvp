<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use ProOceanVan\Repository\RouteCacheRepository;
use ProOceanVan\Repository\StateRepository;

final class PostalCodeService
{
    private const BASE_URL = 'https://openplzapi.org/de/';
    private const STATE_KEYS = [
        'SH' => '01', 'HH' => '02', 'NI' => '03', 'HB' => '04',
        'NW' => '05', 'HE' => '06', 'RP' => '07', 'BW' => '08',
        'BY' => '09', 'SL' => '10', 'BE' => '11', 'BB' => '12',
        'MV' => '13', 'SN' => '14', 'ST' => '15', 'TH' => '16',
    ];

    public function resolve(string $postalCode, string $stateCode): array
    {
        $postalCode = preg_replace('/\D+/', '', $postalCode);
        $stateCode = strtoupper(sanitize_key($stateCode));
        if (! preg_match('/^\d{5}$/', $postalCode)) {
            return ['ok' => false, 'error' => 'Ungültige PLZ.'];
        }

        $stateName = $this->stateName($stateCode);
        $stateKey = self::STATE_KEYS[$stateCode] ?? '';
        if ($stateName === '' || $stateKey === '') {
            return ['ok' => false, 'error' => 'Bundesland nicht gefunden.'];
        }

        if (function_exists('get_option') && get_option('pov_geocoding_provider') === 'demo') {
            return [
                'ok' => true,
                'postal_code' => $postalCode,
                'city' => $this->demoCity($postalCode),
                'state_code' => $stateCode,
                'state_name' => $stateName,
            ];
        }

        $payload = ['postal_code' => $postalCode, 'state_code' => $stateCode];
        $cache = new RouteCacheRepository();
        $cached = $cache->get('openplzapi:v1', 'postal_code', $payload);
        if ($cached) {
            return $cached;
        }

        $url = add_query_arg([
            'postalCode' => $postalCode,
            'page' => 1,
            'pageSize' => 20,
        ], self::BASE_URL . 'Localities');
        $response = wp_remote_get($url, [
            'timeout' => 4,
            'redirection' => 2,
            'sslverify' => ! (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local'),
            'headers' => ['Accept' => 'application/json'],
            'user-agent' => 'ProOceanVan/' . POV_VERSION . '; ' . home_url(),
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $rows = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || ! is_array($rows)) {
            return ['ok' => false, 'error' => 'Das PLZ-Verzeichnis ist gerade nicht erreichbar.'];
        }

        $postalMatch = false;
        foreach ($rows as $row) {
            if (! is_array($row) || (string) ($row['postalCode'] ?? '') !== $postalCode) {
                continue;
            }
            $postalMatch = true;
            $resultState = sanitize_text_field((string) ($row['federalState']['name'] ?? ''));
            $resultStateKey = preg_replace('/\D+/', '', (string) ($row['federalState']['key'] ?? ''));
            if (str_pad($resultStateKey, 2, '0', STR_PAD_LEFT) !== $stateKey) {
                continue;
            }
            $city = sanitize_text_field((string) ($row['name'] ?? ''));
            if ($city === '') {
                continue;
            }
            $result = [
                'ok' => true,
                'postal_code' => $postalCode,
                'city' => $city,
                'state_code' => $stateCode,
                'state_name' => $resultState !== '' ? $resultState : $stateName,
            ];
            $cache->set('openplzapi:v1', 'postal_code', $payload, $result, 30 * DAY_IN_SECONDS);
            return $result;
        }

        return [
            'ok' => false,
            'error' => $postalMatch ? 'Die PLZ passt nicht zum gewählten Bundesland.' : 'Die PLZ wurde nicht gefunden.',
        ];
    }

    private function stateName(string $stateCode): string
    {
        foreach ((new StateRepository())->all(false) as $state) {
            if (strtoupper((string) ($state['state_code'] ?? '')) === $stateCode) {
                return sanitize_text_field((string) ($state['state_name'] ?? ''));
            }
        }
        return '';
    }

    private function demoCity(string $postalCode): string
    {
        return [
            '48143' => 'Münster',
            '54290' => 'Trier',
            '66111' => 'Saarbrücken',
            '70178' => 'Stuttgart',
            '72072' => 'Tübingen',
            '73312' => 'Geislingen an der Steige',
            '90403' => 'Nürnberg',
            '96047' => 'Bamberg',
        ][$postalCode] ?? 'Einsatzort ' . $postalCode;
    }
}
