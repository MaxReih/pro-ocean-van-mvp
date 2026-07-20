<?php

declare(strict_types=1);

namespace ProOceanVan\Domain;

final class SuggestionState
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const SENT = 'sent';
    public const EXPIRED = 'expired';
}
