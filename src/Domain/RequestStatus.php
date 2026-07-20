<?php

declare(strict_types=1);

namespace ProOceanVan\Domain;

final class RequestStatus
{
    public const RECEIVED = 'received';
    public const PROPOSAL_SENT = 'proposal_sent';
    public const CONFIRMED = 'confirmed';

    public static function labels(): array
    {
        return [
            self::RECEIVED => 'Eingegangen',
            self::PROPOSAL_SENT => 'Vorschlag versendet',
            self::CONFIRMED => 'Bestätigt',
        ];
    }
}
