<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (! in_array('--seed', $argv, true)) {
    fwrite(STDERR, "Abbruch: Demo-Daten nur explizit mit --seed anlegen.\n");
    exit(1);
}

require dirname(__DIR__, 4) . '/wp-load.php';

$homeHost = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
if (! str_ends_with($homeHost, '.local') || ! in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    fwrite(STDERR, "Abbruch: Demo-Daten sind ausschließlich für lokale Entwicklungsseiten erlaubt.\n");
    exit(1);
}

try {
    $result = (new ProOceanVan\Service\DemoDataService())->seed();
    fwrite(STDOUT, wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
