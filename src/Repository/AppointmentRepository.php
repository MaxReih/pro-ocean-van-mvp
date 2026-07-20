<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

final class AppointmentRepository
{
    public function all(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY appointment_date ASC", ARRAY_A) ?: [];
    }

    public function forRange(string $start, string $end): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE appointment_date BETWEEN %s AND %s ORDER BY appointment_date ASC",
            $start,
            $end
        ), ARRAY_A) ?: [];

        return array_column($rows, null, 'appointment_date');
    }

    public function next(int $limit = 5): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE appointment_date >= CURDATE() ORDER BY appointment_date ASC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public function existsOnDate(string $date): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE appointment_date = %s", $date)) > 0;
    }

    public function existsForRequest(int $requestId): bool
    {
        if ($requestId <= 0) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE request_id = %d", $requestId)) > 0;
    }

    public function syncAddressFromRequest(array $request): void
    {
        $requestId = (int) ($request['id'] ?? 0);
        if ($requestId <= 0) {
            return;
        }
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'pov_appointments', [
            'public_city' => sanitize_text_field((string) ($request['city'] ?? '')),
            'street' => sanitize_text_field((string) ($request['street'] ?? '')),
            'house_number' => sanitize_text_field((string) ($request['house_number'] ?? '')),
            'postal_code' => preg_replace('/\D+/', '', (string) ($request['postal_code'] ?? '')),
            'city' => sanitize_text_field((string) ($request['city'] ?? '')),
            'state_code' => strtoupper(sanitize_key((string) ($request['state_code'] ?? ''))),
            'latitude' => is_numeric($request['latitude'] ?? null) ? (float) $request['latitude'] : null,
            'longitude' => is_numeric($request['longitude'] ?? null) ? (float) $request['longitude'] : null,
            'route_distance_km' => is_numeric($request['route_distance_km'] ?? null) ? (float) $request['route_distance_km'] : null,
            'route_cost' => is_numeric($request['route_cost'] ?? null) ? (float) $request['route_cost'] : null,
            'updated_at' => current_time('mysql'),
        ], ['request_id' => $requestId]);
    }

    public function createFromRequest(array $request, string $date, string $publicCity, array $route = [], string $notes = ''): int
    {
        $requestId = (int) ($request['id'] ?? 0);
        if ($requestId <= 0 || $this->existsForRequest($requestId) || $this->existsOnDate($date)) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        $inserted = $wpdb->insert($table, [
            'request_id' => $requestId,
            'appointment_date' => $date,
            'public_city' => sanitize_text_field($publicCity),
            'institution_name' => (string) $request['institution_name'],
            'contact_name' => trim((string) $request['contact_first_name'] . ' ' . (string) $request['contact_last_name']),
            'contact_email' => (string) $request['contact_email'],
            'contact_phone' => (string) $request['contact_phone'],
            'street' => (string) $request['street'],
            'house_number' => (string) $request['house_number'],
            'postal_code' => (string) $request['postal_code'],
            'city' => (string) $request['city'],
            'state_code' => (string) $request['state_code'],
            'latitude' => $request['latitude'] ?: null,
            'longitude' => $request['longitude'] ?: null,
            'start_label' => sanitize_text_field((string) ($route['start_label'] ?? get_option('pov_default_start_label', 'Tübingen'))),
            'start_latitude' => $route['start_latitude'] ?? null,
            'start_longitude' => $route['start_longitude'] ?? null,
            'route_distance_km' => $route['distance_km'] ?? null,
            'route_cost' => $route['cost'] ?? null,
            'internal_notes' => sanitize_textarea_field($notes),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return $inserted ? (int) $wpdb->insert_id : 0;
    }
}
