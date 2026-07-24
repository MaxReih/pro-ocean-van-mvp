<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

final class TourExpenseRepository
{
    public function all(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}pov_tour_expenses ORDER BY expense_date ASC, id ASC",
            ARRAY_A
        ) ?: [];
    }

    public function forRange(string $from, string $to): array
    {
        if (! $this->validDate($from) || ! $this->validDate($to) || $from > $to) {
            return [];
        }
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pov_tour_expenses WHERE expense_date BETWEEN %s AND %s ORDER BY expense_date ASC, id ASC",
            $from,
            $to
        ), ARRAY_A) ?: [];
    }

    public function forRequest(int $requestId): array
    {
        if ($requestId <= 0) {
            return [];
        }
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pov_tour_expenses WHERE request_id = %d ORDER BY expense_date ASC, id ASC",
            $requestId
        ), ARRAY_A) ?: [];
    }

    public function save(array $data): int
    {
        $date = sanitize_text_field((string) ($data['expense_date'] ?? ''));
        $requestId = absint($data['request_id'] ?? 0);
        $appointmentId = absint($data['appointment_id'] ?? 0);
        if (! $this->validDate($date) || ($requestId <= 0 && $appointmentId <= 0)) {
            return 0;
        }

        $row = [
            'expense_type' => 'overnight',
            'expense_date' => $date,
            'request_id' => $requestId ?: null,
            'appointment_id' => $appointmentId ?: null,
            'place' => sanitize_text_field((string) ($data['place'] ?? '')),
            'amount' => round(max(0.0, (float) ($data['amount'] ?? 0)), 2),
            'note' => sanitize_textarea_field((string) ($data['note'] ?? '')),
            'created_by' => get_current_user_id() ?: null,
            'updated_at' => current_time('mysql'),
        ];
        $id = absint($data['id'] ?? 0);
        global $wpdb;
        if ($id > 0) {
            $updated = $wpdb->update($wpdb->prefix . 'pov_tour_expenses', $row, ['id' => $id]);
            return $updated === false ? 0 : $id;
        }
        $row['created_at'] = current_time('mysql');
        $inserted = $wpdb->insert($wpdb->prefix . 'pov_tour_expenses', $row);
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'pov_tour_expenses', ['id' => $id], ['%d']) !== false;
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}
