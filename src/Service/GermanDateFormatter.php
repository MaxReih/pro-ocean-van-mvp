<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateTimeImmutable;

final class GermanDateFormatter
{
    private const WEEKDAYS = [
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag',
        6 => 'Samstag',
        7 => 'Sonntag',
    ];

    private const WEEKDAYS_SHORT = [
        1 => 'Mo',
        2 => 'Di',
        3 => 'Mi',
        4 => 'Do',
        5 => 'Fr',
        6 => 'Sa',
        7 => 'So',
    ];

    private const MONTHS = [
        1 => 'Januar',
        2 => 'Februar',
        3 => 'März',
        4 => 'April',
        5 => 'Mai',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'August',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Dezember',
    ];

    private const MONTHS_SHORT = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez',
    ];

    public static function full(string $date): string
    {
        $day = self::parse($date);
        return $day
            ? self::WEEKDAYS[(int) $day->format('N')] . ', ' . $day->format('j') . '. ' . self::MONTHS[(int) $day->format('n')] . ' ' . $day->format('Y')
            : $date;
    }

    public static function short(string $date): string
    {
        $day = self::parse($date);
        return $day ? self::WEEKDAYS_SHORT[(int) $day->format('N')] . ', ' . $day->format('d.m.Y') : $date;
    }

    public static function weekdayShort(string $date): string
    {
        $day = self::parse($date);
        return $day ? self::WEEKDAYS_SHORT[(int) $day->format('N')] : '';
    }

    public static function monthShort(string $date): string
    {
        $day = self::parse($date);
        return $day ? self::MONTHS_SHORT[(int) $day->format('n')] : '';
    }

    public static function monthYear(string $date): string
    {
        $day = self::parse($date);
        return $day ? self::MONTHS[(int) $day->format('n')] . ' ' . $day->format('Y') : $date;
    }

    private static function parse(string $date): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $day && $day->format('Y-m-d') === $date ? $day : null;
    }
}
