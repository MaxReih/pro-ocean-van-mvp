<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

final class StateRepository
{
    public function all(bool $activeOnly = false): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_states';
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, state_name ASC", ARRAY_A) ?: [];
    }

    public function isActive(string $code): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_states';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE state_code = %s AND is_active = 1", strtoupper($code))) > 0;
    }

    public function updateStates(array $rows): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_states';
        foreach ($rows as $code => $row) {
            $wpdb->update($table, [
                'is_active' => ! empty($row['is_active']) ? 1 : 0,
                'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
                'updated_at' => current_time('mysql'),
            ], ['state_code' => strtoupper(sanitize_key((string) $code))]);
        }
    }
}
