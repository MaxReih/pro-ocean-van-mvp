<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateTimeImmutable;

final class AppointmentDates
{
    public const MAX_DAYS = 180;
    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public static function fromSelection(array $input): array
    {
        $mode = $input['schedule_mode'] ?? 'days';
        if ($mode === 'range') {
            $start = self::parse($input['date_from'] ?? null);
            $end = self::parse($input['date_to'] ?? null);
            if (! $start || ! $end || $end < $start || $start->diff($end)->days >= self::MAX_DAYS) {
                return [];
            }
            $days = [];
            for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
                $days[] = $day->format('Y-m-d');
            }
            return $days;
        }
        if ($mode !== 'days') {
            return [];
        }
        $dates = $input['dates'] ?? [$input['appointment_date'] ?? $input['date'] ?? ''];
        if (! is_array($dates) || count($dates) > self::MAX_DAYS) {
            return [];
        }
        $days = [];
        foreach ($dates as $value) {
            if ($value === '') {
                continue;
            }
            $day = self::parse($value);
            if (! $day) {
                return [];
            }
            $days[$day->format('Y-m-d')] = true;
        }
        $days = array_keys($days);
        sort($days, SORT_STRING);
        return $days;
    }

    public static function parse(mixed $date): ?DateTimeImmutable
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        return $day && $day->format('Y-m-d') === $date ? $day : null;
    }

    public static function fitsRequest(string $date, array $request): bool
    {
        $day = self::parse($date);
        if (! $day) {
            return false;
        }
        if (($request['request_mode'] ?? '') === 'specific_date') {
            return $date === (string) ($request['specific_requested_date'] ?? '');
        }
        $from = (string) ($request['desired_date_from'] ?? '');
        $to = (string) ($request['desired_date_to'] ?? '');
        $weekdays = array_filter(explode(',', (string) ($request['possible_weekdays'] ?? '')));
        return ($from === '' || $date >= $from) && ($to === '' || $date <= $to)
            && (! $weekdays || in_array(strtolower($day->format('D')), $weekdays, true));
    }

    public static function label(array $dates): string
    {
        if (! $dates) {
            return '';
        }
        if (count($dates) === 1) {
            return GermanDateFormatter::full($dates[0]);
        }
        $first = self::parse($dates[0]);
        $last = self::parse($dates[count($dates) - 1]);
        if ($first && $last && $first->diff($last)->days + 1 === count($dates)) {
            return GermanDateFormatter::short($dates[0]) . ' bis ' . GermanDateFormatter::short($last->format('Y-m-d'));
        }
        return count($dates) . ' Einsatztage';
    }
}
