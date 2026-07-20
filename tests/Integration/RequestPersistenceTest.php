<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RequestPersistenceTest extends TestCase
{
    public function testIntegrationRequiresWordPressDatabase(): void
    {
        self::assertTrue(function_exists('add_action'), 'Run this suite inside the WordPress PHPUnit environment.');
    }
}
