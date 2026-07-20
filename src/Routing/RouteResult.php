<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

final class RouteResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly float $distanceKm = 0.0,
        public readonly ?float $durationMinutes = null,
        public readonly string $error = ''
    ) {
    }

    public static function failure(string $error): self
    {
        return new self(false, 0.0, null, $error);
    }
}
