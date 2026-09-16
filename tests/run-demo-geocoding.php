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

function get_option(string $name, mixed $default = false): mixed
{
    return $name === 'pov_cluster_radius_km' ? '80' : $default;
}

require_once dirname(__DIR__) . '/src/Routing/GeocodingProviderInterface.php';
require_once dirname(__DIR__) . '/src/Routing/DemoGeocodingProvider.php';
require_once dirname(__DIR__) . '/src/Service/PublicRecommendationService.php';

$provider = new ProOceanVan\Routing\DemoGeocodingProvider();
$known = $provider->geocodeAddress(['postal_code' => '73312', 'state_code' => 'BW']);
if (empty($known['ok'])
    || (string) $known['postal_code'] !== '73312'
    || abs((float) $known['latitude'] - 48.6242) > 0.001
    || abs((float) $known['longitude'] - 9.8274) > 0.001) {
    throw new RuntimeException('Die bekannte Test-PLZ wird nicht korrekt aufgelöst.');
}
echo "OK: 73312 wird ohne Netzwerk eindeutig aufgelöst.\n";

$stuttgart = $provider->geocodeAddress(['postal_code' => '70180', 'state_code' => 'BW']);
if (empty($stuttgart['ok'])
    || (string) $stuttgart['label'] !== 'Stuttgart, 70180'
    || abs((float) $stuttgart['latitude'] - 48.7643) > 0.001
    || abs((float) $stuttgart['longitude'] - 9.1681) > 0.001) {
    throw new RuntimeException('Die Stuttgarter Demo-PLZ wird nicht korrekt aufgelöst.');
}
echo "OK: 70180 wird als Stuttgart aufgelöst.\n";

$recommendations = new ProOceanVan\Service\PublicRecommendationService();
$regionalCluster = new ReflectionMethod($recommendations, 'isRegionalCluster');
$regionalCluster->setAccessible(true);
$candidate = ['latitude' => $stuttgart['latitude'], 'longitude' => $stuttgart['longitude']];
$gerlingen = [['state_code' => 'BW', 'latitude' => 48.8007, 'longitude' => 9.0636]];
$trier = [['state_code' => 'RP', 'latitude' => 49.7499, 'longitude' => 6.6371]];
if (! $regionalCluster->invoke($recommendations, $gerlingen, $candidate, 'BW')
    || $regionalCluster->invoke($recommendations, $trier, $candidate, 'BW')) {
    throw new RuntimeException('Die regionale Tourpriorisierung trennt Gerlingen und Trier nicht korrekt.');
}
echo "OK: Stuttgart wird der Gerlingen-Woche, nicht der Trier-Woche zugeordnet.\n";

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
