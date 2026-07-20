<?php

declare(strict_types=1);

namespace ProOceanVan\Domain;

final class CalendarState
{
    public const AVAILABLE = 'available';
    public const LIMITED = 'limited';
    public const UNAVAILABLE = 'unavailable';
    public const TOUR = 'tour';

    public static function labels(): array
    {
        return [
            self::AVAILABLE => 'Buchbar',
            self::LIMITED => 'Auf Anfrage',
            self::UNAVAILABLE => 'Nicht buchbar',
            self::TOUR => 'Van unterwegs',
        ];
    }
}
