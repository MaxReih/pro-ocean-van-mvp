<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

final class CostService
{
    public function cost(float $kilometers, ?float $rate = null): float
    {
        $rate = $rate ?? (float) get_option('pov_kilometer_rate', 0);
        return round(max(0.0, $kilometers) * max(0.0, $rate), 2);
    }

    public function isConfigured(): bool
    {
        return (float) get_option('pov_kilometer_rate', 0) > 0
            && (
                (float) get_option('pov_max_public_suggestion_cost', 0) > 0
                || (float) get_option('pov_max_public_suggestion_distance_km', 0) > 0
            );
    }
}
