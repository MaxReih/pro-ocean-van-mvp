<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

use ProOceanVan\Domain\EventType;

final class AppointmentRepository
{
    private array $heldLocks = [];
    private array $fallbackLockTokens = [];
    private ?bool $mysqlNamedLocks = null;

    public function all(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        return $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'confirmed' ORDER BY appointment_date ASC", ARRAY_A) ?: [];
    }

    public function forRange(string $start, string $end): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'confirmed' AND appointment_date BETWEEN %s AND %s ORDER BY appointment_date ASC",
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
            "SELECT * FROM {$table} WHERE status = 'confirmed' AND appointment_date >= CURDATE() ORDER BY appointment_date ASC LIMIT %d",
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

    public function findForRequest(int $requestId): ?array
    {
        if ($requestId <= 0) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pov_appointments
             WHERE request_id = %d
             ORDER BY CASE WHEN status = 'confirmed' THEN 0 ELSE 1 END, id DESC
             LIMIT 1",
            $requestId
        ), ARRAY_A);
        return $row ?: null;
    }

    public function existsOnDate(string $date, int $excludeAppointmentId = 0): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND appointment_date = %s AND id != %d",
            $date,
            $excludeAppointmentId
        )) > 0;
    }

    public function existsForRequest(int $requestId, int $excludeAppointmentId = 0): bool
    {
        if ($requestId <= 0) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE request_id = %d AND status = 'confirmed' AND id != %d",
            $requestId,
            $excludeAppointmentId
        )) > 0;
    }

    public function syncAddressFromRequest(array $request): void
    {
        $requestId = (int) ($request['id'] ?? 0);
        if ($requestId <= 0) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pov_appointments';
        $data = [
            'public_city' => sanitize_text_field((string) ($request['city'] ?? '')),
            'institution_name' => sanitize_text_field((string) ($request['institution_name'] ?? '')),
            'contact_name' => trim(sanitize_text_field((string) ($request['contact_first_name'] ?? '') . ' ' . (string) ($request['contact_last_name'] ?? ''))),
            'contact_email' => sanitize_email((string) ($request['contact_email'] ?? '')),
            'contact_phone' => sanitize_text_field((string) ($request['contact_phone'] ?? '')),
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
        ];
        $outcomeRecorded = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE request_id = %d
               AND (metrics_recorded_at IS NOT NULL OR participants_children IS NOT NULL OR participants_adults IS NOT NULL)",
            $requestId
        )) > 0;
        if (! $outcomeRecorded) {
            $data['event_type'] = EventType::normalize((string) ($request['institution_type'] ?? ''));
        }
        $wpdb->update($table, $data, ['request_id' => $requestId]);
    }

    public function createFromRequest(array $request, string $date, string $publicCity, array $route = [], string $notes = ''): int
    {
        $requestId = (int) ($request['id'] ?? 0);
        if ($requestId <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! $this->acquireRequestLock($requestId)) {
            return 0;
        }

        $dateLocked = false;
        try {
            $dateLocked = $this->acquireDateLock($date);
            if (! $dateLocked) {
                return 0;
            }
            if ($this->existsForRequest($requestId) || $this->existsOnDate($date)) {
                return 0;
            }
            global $wpdb;
            $table = $wpdb->prefix . 'pov_appointments';
            $inserted = $wpdb->insert($table, [
            'request_id' => $requestId,
            'appointment_date' => $date,
            'status' => 'confirmed',
            'public_city' => sanitize_text_field($publicCity),
            'institution_name' => (string) $request['institution_name'],
            'event_type' => EventType::normalize((string) ($request['institution_type'] ?? '')),
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
        } finally {
            if ($dateLocked) {
                $this->releaseDateLock($date);
            }
            $this->releaseRequestLock($requestId);
        }
    }

    public function reschedule(int $id, string $date): bool
    {
        if ($id <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $appointment = $this->find($id);
        $requestId = (int) ($appointment['request_id'] ?? 0);
        if ($requestId <= 0 || ! $this->acquireRequestLock($requestId)) {
            return false;
        }

        $dateLocked = false;
        try {
            $dateLocked = $this->acquireDateLock($date);
            if (! $dateLocked
                || $this->existsForRequest($requestId, $id)
                || $this->existsOnDate($date, $id)) {
                return false;
            }
            global $wpdb;
            return $wpdb->update($wpdb->prefix . 'pov_appointments', [
                'appointment_date' => $date,
                'status' => 'confirmed',
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'calendar_exported_at' => null,
                'updated_at' => current_time('mysql'),
            ], ['id' => $id]) !== false;
        } finally {
            if ($dateLocked) {
                $this->releaseDateLock($date);
            }
            $this->releaseRequestLock($requestId);
        }
    }

    public function withPlanningLocks(int $requestId, string $date, callable $callback): mixed
    {
        if ($requestId <= 0
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || ! $this->acquireRequestLock($requestId)) {
            return null;
        }

        $dateLocked = false;
        try {
            $dateLocked = $this->acquireDateLock($date);
            return $dateLocked ? $callback() : null;
        } finally {
            if ($dateLocked) {
                $this->releaseDateLock($date);
            }
            $this->releaseRequestLock($requestId);
        }
    }

    public function withRequestLock(int $requestId, callable $callback): mixed
    {
        if ($requestId <= 0 || ! $this->acquireRequestLock($requestId)) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->releaseRequestLock($requestId);
        }
    }

    public function cancel(int $id, string $reason = ''): bool
    {
        if ($id <= 0) {
            return false;
        }
        global $wpdb;
        return $wpdb->update($wpdb->prefix . 'pov_appointments', [
            'status' => 'cancelled',
            'cancelled_at' => current_time('mysql'),
            'cancellation_reason' => sanitize_textarea_field($reason),
            'calendar_exported_at' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]) !== false;
    }

    public function updateOutcome(int $id, string $eventType, ?int $children, ?int $adults): bool
    {
        if ($id <= 0 || ($children !== null && $children < 0) || ($adults !== null && $adults < 0)) {
            return false;
        }
        global $wpdb;
        return $wpdb->update($wpdb->prefix . 'pov_appointments', [
            'event_type' => EventType::normalize($eventType),
            'participants_children' => $children,
            'participants_adults' => $adults,
            'metrics_recorded_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]) !== false;
    }

    private function acquireDateLock(string $date): bool
    {
        return $this->acquireNamedLock('pov_appointment_' . $date);
    }

    private function releaseDateLock(string $date): void
    {
        $this->releaseNamedLock('pov_appointment_' . $date);
    }

    private function acquireRequestLock(int $requestId): bool
    {
        return $this->acquireNamedLock('pov_request_appointment_' . $requestId);
    }

    private function releaseRequestLock(int $requestId): void
    {
        $this->releaseNamedLock('pov_request_appointment_' . $requestId);
    }

    private function acquireNamedLock(string $name): bool
    {
        if (isset($this->heldLocks[$name])) {
            $this->heldLocks[$name]++;
            return true;
        }

        global $wpdb;
        if ($this->supportsMysqlNamedLocks()) {
            $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
            if ($result === null && (string) $wpdb->last_error !== '') {
                $this->mysqlNamedLocks = false;
                return $this->acquireNamedLock($name);
            }
            $locked = (int) $result === 1;
            if ($locked) {
                $this->heldLocks[$name] = 1;
            }
            return $locked;
        }

        $option = 'pov_lock_' . hash('sha256', $name);
        $token = wp_generate_password(32, false, false);
        $deadline = microtime(true) + 5.0;
        do {
            $value = ['token' => $token, 'expires' => time() + 120];
            if (add_option($option, $value, '', false)) {
                $this->heldLocks[$name] = 1;
                $this->fallbackLockTokens[$name] = ['option' => $option, 'token' => $token];
                return true;
            }
            $current = get_option($option);
            if (is_array($current) && (int) ($current['expires'] ?? 0) < time()) {
                global $wpdb;
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                    $option,
                    maybe_serialize($current)
                ));
                wp_cache_delete($option, 'options');
                wp_cache_delete('notoptions', 'options');
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function releaseNamedLock(string $name): void
    {
        if (! isset($this->heldLocks[$name])) {
            return;
        }
        $this->heldLocks[$name]--;
        if ($this->heldLocks[$name] > 0) {
            return;
        }
        unset($this->heldLocks[$name]);

        global $wpdb;
        if ($this->supportsMysqlNamedLocks()) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            return;
        }

        $stored = $this->fallbackLockTokens[$name] ?? null;
        unset($this->fallbackLockTokens[$name]);
        if (! is_array($stored)) {
            return;
        }
        $current = get_option((string) $stored['option']);
        if (is_array($current) && hash_equals((string) $stored['token'], (string) ($current['token'] ?? ''))) {
            delete_option((string) $stored['option']);
        }
    }

    private function supportsMysqlNamedLocks(): bool
    {
        if ($this->mysqlNamedLocks !== null) {
            return $this->mysqlNamedLocks;
        }
        global $wpdb;
        $class = strtolower(get_class($wpdb));
        $serverInfo = method_exists($wpdb, 'db_server_info')
            ? strtolower((string) $wpdb->db_server_info())
            : '';
        $this->mysqlNamedLocks = ! str_contains($class, 'sqlite')
            && ! str_contains($serverInfo, 'sqlite')
            && ! defined('SQLITE_DB_DROPIN_VERSION')
            && ! defined('SQLITE_DB');
        return $this->mysqlNamedLocks;
    }
}
