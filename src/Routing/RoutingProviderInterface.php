<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

interface RoutingProviderInterface
{
    public function calculateRoute(array $coordinates): RouteResult;

    public function calculateMatrix(array $sources, array $destinations): array;
}
