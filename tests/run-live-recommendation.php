<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 4) . '/wp-load.php';

$postalCode = preg_replace('/\D+/', '', (string) ($argv[1] ?? '73312'));
$stateCode = strtoupper((string) ($argv[2] ?? 'BW'));
$result = (new \ProOceanVan\Service\PublicRecommendationService())->recommend($postalCode, $stateCode);

echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
