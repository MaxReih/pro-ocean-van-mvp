<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ProOceanVan\Service\IcsService;

final class IcsServiceTest extends TestCase
{
    public function testEscaping(): void
    {
        $service = new IcsService();
        self::assertStringContainsString('\,', $service->escape('A, B'));
        self::assertStringContainsString('\n', $service->escape("A\nB"));
    }
}
