<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use ProOceanVan\Domain\CalendarState;

final class CalendarDayRepository
{
    private const MAX_RANGE_DAYS = 366;

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

    public function walkIns(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}pov_calendar_days WHERE availability_state = %s ORDER BY calendar_date ASC",
                CalendarState::WALK_IN
            ),
            ARRAY_A
        ) ?: [];
        return array_column($rows, null, 'calendar_date');
    }

    public function upsert(
        string $date,
        string $state,
        string $publicNote = '',
        string $internalNote = '',
        array $start = [],
        array $publicEvent = []
    ): void
    {
        if (! $this->validDate($date)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pov_calendar_days';
        $allowed = [CalendarState::AVAILABLE, CalendarState::LIMITED, CalendarState::UNAVAILABLE, CalendarState::WALK_IN];
        $state = in_array($state, $allowed, true) ? $state : CalendarState::AVAILABLE;
        $children = trim((string) ($publicEvent['participants_children'] ?? '')) === ''
            ? null
            : max(0, (int) $publicEvent['participants_children']);
        $adults = trim((string) ($publicEvent['participants_adults'] ?? '')) === ''
            ? null
            : max(0, (int) $publicEvent['participants_adults']);
        if ($state !== CalendarState::WALK_IN) {
            $children = null;
            $adults = null;
        }
        $eventGroup = $state === CalendarState::WALK_IN
            ? substr(sanitize_text_field((string) ($publicEvent['group'] ?? '')), 0, 64)
            : '';
        if ($state === CalendarState::WALK_IN && $eventGroup === '') {
            $eventGroup = wp_generate_uuid4();
        }
        $data = [
            'calendar_date' => $date,
            'availability_state' => $state,
            'public_note' => sanitize_text_field($publicNote),
            'public_title' => sanitize_text_field((string) ($publicEvent['title'] ?? '')),
            'public_description' => sanitize_textarea_field((string) ($publicEvent['description'] ?? '')),
            'public_location' => sanitize_text_field((string) ($publicEvent['location'] ?? '')),
            'public_url' => esc_url_raw((string) ($publicEvent['url'] ?? '')),
            'public_event_group' => $eventGroup ?: null,
            'participants_children' => $children,
            'participants_adults' => $adults,
            'metrics_recorded_at' => $children === null && $adults === null ? null : current_time('mysql'),
            'internal_note' => sanitize_textarea_field($internalNote),
            'custom_start_label' => sanitize_text_field((string) ($start['label'] ?? '')),
            'custom_start_latitude' => $start['latitude'] ?? null,
            'custom_start_longitude' => $start['longitude'] ?? null,
            'updated_at' => current_time('mysql'),
        ];
        if ($state !== CalendarState::WALK_IN) {
            $data['request_id'] = null;
        } elseif (array_key_exists('request_id', $publicEvent)) {
            $data['request_id'] = max(0, (int) $publicEvent['request_id']) ?: null;
        }

        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE calendar_date = %s", $date));
        if ($exists > 0) {
            $wpdb->update($table, $data, ['id' => $exists]);
            return;
        }

        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
    }

    public function upsertRange(
        string $from,
        string $to,
        string $state,
        string $publicNote = '',
        string $internalNote = '',
        array $start = [],
        array $publicEvent = []
    ): int
    {
        if (! $this->validDate($from)) {
            return 0;
        }

        $to = $this->validDate($to) ? $to : $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $fromDate = new DateTimeImmutable($from);
        $toDate = new DateTimeImmutable($to);
        if (((int) $fromDate->diff($toDate)->days + 1) > self::MAX_RANGE_DAYS) {
            return 0;
        }

        $count = 0;
        if ($state === CalendarState::WALK_IN) {
            $group = substr(sanitize_text_field((string) ($publicEvent['group'] ?? '')), 0, 64);
            $publicEvent['group'] = $group !== '' ? $group : wp_generate_uuid4();
        }
        $period = new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), (new DateTimeImmutable($to))->modify('+1 day'));
        foreach ($period as $day) {
            $eventForDay = $publicEvent;
            if ($state === CalendarState::WALK_IN && $day->format('Y-m-d') !== $from) {
                unset($eventForDay['participants_children'], $eventForDay['participants_adults']);
            }
            $this->upsert($day->format('Y-m-d'), $state, $publicNote, $internalNote, $start, $eventForDay);
            $count++;
        }

        return $count;
    }

    public function isBlocked(string $date): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_calendar_days';
        return in_array((string) $wpdb->get_var($wpdb->prepare(
            "SELECT availability_state FROM {$table} WHERE calendar_date = %s",
            $date
        )), [CalendarState::UNAVAILABLE, CalendarState::WALK_IN], true);
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pov_calendar_days WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
