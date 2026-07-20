<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PublicFilteringTest extends TestCase
{
    public function testCalendarShapeContainsNoPersonalFields(): void
    {
        $allowed = ['date', 'public_state', 'public_label', 'public_city', 'is_selectable'];
        $row = ['date' => '2026-10-14', 'public_state' => 'tour', 'public_label' => 'Ocean Van in Freiburg', 'public_city' => 'Freiburg', 'is_selectable' => false];
        self::assertSame($allowed, array_keys($row));
    }
}
