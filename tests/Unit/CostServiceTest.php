<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ProOceanVan\Service\CostService;

final class CostServiceTest extends TestCase
{
    public function testCostCalculation(): void
    {
        $service = new CostService();
        self::assertSame(75.0, $service->cost(150.0, 0.5));
    }
}
