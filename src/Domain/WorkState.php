<?php

declare(strict_types=1);

namespace ProOceanVan\Domain;

final class WorkState
{
    public const NEW = 'new';
    public const IN_REVIEW = 'in_review';
    public const AWAITING_RESPONSE = 'awaiting_response';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    public static function labels(): array
    {
        return [
            self::NEW => 'Neu',
            self::IN_REVIEW => 'In Prüfung',
            self::AWAITING_RESPONSE => 'Rückmeldung ausstehend',
            self::ACCEPTED => 'Angenommen',
            self::REJECTED => 'Abgelehnt',
            self::CANCELLED => 'Storniert',
        ];
    }
}
