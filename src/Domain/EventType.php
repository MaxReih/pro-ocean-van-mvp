<?php

declare(strict_types=1);

namespace ProOceanVan\Domain;

final class EventType
{
    public const SCHOOL = 'school';
    public const EVENT = 'event';
    public const WALK_IN = 'walk_in';
    public const OTHER = 'other';

    public static function normalize(string $value): string
    {
        $value = strtolower(remove_accents(trim($value)));
        if (str_contains($value, 'schule')) {
            return self::SCHOOL;
        }
        if (str_contains($value, 'walk') || str_contains($value, 'offen')) {
            return self::WALK_IN;
        }
        if (str_contains($value, 'veranstaltung') || str_contains($value, 'event')) {
            return self::EVENT;
        }
        return in_array($value, [self::SCHOOL, self::EVENT, self::WALK_IN, self::OTHER], true)
            ? $value
            : self::OTHER;
    }

    public static function labels(): array
    {
        return [
            self::SCHOOL => 'Schule',
            self::EVENT => 'Veranstaltung',
            self::WALK_IN => 'Walk-in-Event',
            self::OTHER => 'Sonstiges',
        ];
    }
}
