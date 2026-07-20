<?php

declare(strict_types=1);

function sanitize_key(string $value): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $value));
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

require_once dirname(__DIR__) . '/src/Routing/GeocodingProviderInterface.php';
require_once dirname(__DIR__) . '/src/Routing/DemoGeocodingProvider.php';

$provider = new ProOceanVan\Routing\DemoGeocodingProvider();
$known = $provider->geocodeAddress(['postal_code' => '73312', 'state_code' => 'BW']);
if (empty($known['ok'])
    || (string) $known['postal_code'] !== '73312'
    || abs((float) $known['latitude'] - 48.6242) > 0.001
    || abs((float) $known['longitude'] - 9.8274) > 0.001) {
    throw new RuntimeException('Die bekannte Test-PLZ wird nicht korrekt aufgelöst.');
}
echo "OK: 73312 wird ohne Netzwerk eindeutig aufgelöst.\n";

$fallback = $provider->geocodeAddress(['postal_code' => '78462', 'state_code' => 'BW']);
if (empty($fallback['ok']) || ! is_numeric($fallback['latitude']) || ! is_numeric($fallback['longitude'])) {
    throw new RuntimeException('Die Demo-Auflösung für beliebige gültige PLZ ist fehlgeschlagen.');
}
echo "OK: beliebige gültige PLZ erhalten einen deterministischen Demo-Punkt.\n";

$invalid = $provider->geocodeAddress(['postal_code' => '123', 'state_code' => 'BW']);
if (! empty($invalid['ok'])) {
    throw new RuntimeException('Ungültige PLZ wurde akzeptiert.');
}
echo "Demo geocoding checks passed.\n";
