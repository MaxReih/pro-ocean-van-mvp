<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

final class RouteCacheRepository
{
    public function get(string $provider, string $type, array $payload): ?array
    {
        global $wpdb;
        $hash = $this->hash($payload);
        $table = $wpdb->prefix . 'pov_route_cache';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT response_payload FROM {$table} WHERE cache_key = %s AND expires_at >= %s",
            "{$provider}:{$type}:{$hash}",
            current_time('mysql', true)
        ), ARRAY_A);

        if (! $row) {
            return null;
        }

        $decoded = json_decode((string) $row['response_payload'], true);
        return is_array($decoded) ? $decoded : null;
    }

    public function set(string $provider, string $type, array $payload, array $response, int $ttl): void
    {
        global $wpdb;
        $hash = $this->hash($payload);
        $key = "{$provider}:{$type}:{$hash}";
        $table = $wpdb->prefix . 'pov_route_cache';
        $data = [
            'cache_key' => $key,
            'provider' => $provider,
            'request_type' => $type,
            'request_payload_hash' => $hash,
            'response_payload' => wp_json_encode($response),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + max(60, $ttl)),
            'created_at' => current_time('mysql', true),
        ];

        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE cache_key = %s", $key));
        if ($exists > 0) {
            $wpdb->update($table, $data, ['id' => $exists]);
            return;
        }
        $wpdb->insert($table, $data);
    }

    private function hash(array $payload): string
    {
        ksort($payload);
        return hash('sha256', wp_json_encode($payload));
    }
}
