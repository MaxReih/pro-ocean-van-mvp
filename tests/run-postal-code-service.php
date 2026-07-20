<?php

declare(strict_types=1);

namespace ProOceanVan\Repository {
    final class RouteCacheRepository
    {
        public function get(string $provider, string $type, array $payload): ?array { return null; }
        public function set(string $provider, string $type, array $payload, array $response, int $ttl): void {}
    }

    final class StateRepository
    {
        public function all(bool $activeOnly = false): array
        {
            return [
                ['state_code' => 'BW', 'state_name' => 'Baden-Württemberg'],
                ['state_code' => 'BY', 'state_name' => 'Bayern'],
            ];
        }
    }
}

namespace {
    define('POV_VERSION', 'test');
    define('DAY_IN_SECONDS', 86400);
    $lastHttpRequest = [];

    function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $value)); }
    function sanitize_text_field(string $value): string { return trim($value); }
    function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
    function home_url(): string { return 'https://example.test'; }
    function wp_get_environment_type(): string { return 'local'; }
    function is_wp_error(mixed $value): bool { return false; }
    function wp_remote_retrieve_response_code(array $response): int { return (int) $response['response']['code']; }
    function wp_remote_retrieve_body(array $response): string { return (string) $response['body']; }
    function wp_remote_get(string $url, array $args): array
    {
        global $lastHttpRequest;
        $lastHttpRequest = compact('url', 'args');
        return ['response' => ['code' => 200], 'body' => json_encode([[
            'name' => 'Geislingen an der Steige',
            'postalCode' => '73312',
            'federalState' => ['key' => '08', 'name' => 'Baden-Württemberg'],
        ]], JSON_UNESCAPED_UNICODE)];
    }

    function expect_true(bool $condition, string $message): void
    {
        if (! $condition) throw new RuntimeException($message);
        echo "OK: {$message}\n";
    }

    require_once dirname(__DIR__) . '/src/Service/PostalCodeService.php';

    $service = new ProOceanVan\Service\PostalCodeService();
    $result = $service->resolve('73312', 'BW');
    expect_true(! empty($result['ok']) && $result['city'] === 'Geislingen an der Steige', 'postal code is resolved to its locality');
    expect_true(str_contains($lastHttpRequest['url'], 'postalCode=73312'), 'postal lookup uses the exact postal code');
    expect_true($lastHttpRequest['args']['sslverify'] === false, 'local SSL workaround stays limited by environment');

    $mismatch = $service->resolve('73312', 'BY');
    expect_true(empty($mismatch['ok']) && str_contains($mismatch['error'], 'Bundesland'), 'postal code and state must match');

    echo "Postal-code checks passed.\n";
}
