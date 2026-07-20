<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use ProOceanVan\Domain\CalendarState;

final class CalendarDayRepository
{
    public function forRange(string $start, string $end): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_calendar_days';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE calendar_date BETWEEN %s AND %s ORDER BY calendar_date ASC",
            $start,
            $end
        ), ARRAY_A) ?: [];

        return array_column($rows, null, 'calendar_date');
    }

    public function upsert(string $date, string $state, string $publicNote = '', string $internalNote = '', array $start = []): void
    {
        if (! $this->validDate($date)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pov_calendar_days';
        $allowed = [CalendarState::AVAILABLE, CalendarState::LIMITED, CalendarState::UNAVAILABLE];
        $state = in_array($state, $allowed, true) ? $state : CalendarState::AVAILABLE;
        $data = [
            'calendar_date' => $date,
            'availability_state' => $state,
            'public_note' => sanitize_text_field($publicNote),
            'internal_note' => sanitize_textarea_field($internalNote),
            'custom_start_label' => sanitize_text_field((string) ($start['label'] ?? '')),
            'custom_start_latitude' => $start['latitude'] ?? null,
            'custom_start_longitude' => $start['longitude'] ?? null,
            'updated_at' => current_time('mysql'),
        ];

        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE calendar_date = %s", $date));
        if ($exists > 0) {
            $wpdb->update($table, $data, ['id' => $exists]);
            return;
        }

        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
    }

    public function upsertRange(string $from, string $to, string $state, string $publicNote = '', string $internalNote = '', array $start = []): int
    {
        if (! $this->validDate($from)) {
            return 0;
        }

        $to = $this->validDate($to) ? $to : $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $count = 0;
        $period = new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), (new DateTimeImmutable($to))->modify('+1 day'));
        foreach ($period as $day) {
            $this->upsert($day->format('Y-m-d'), $state, $publicNote, $internalNote, $start);
            $count++;
        }

        return $count;
    }

    public function isBlocked(string $date): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_calendar_days';
        return CalendarState::UNAVAILABLE === (string) $wpdb->get_var($wpdb->prepare(
            "SELECT availability_state FROM {$table} WHERE calendar_date = %s",
            $date
        ));
    }

    private function validDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }
}
