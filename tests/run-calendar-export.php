<?php

declare(strict_types=1);

function add_query_arg(array $arguments, string $url): string
{
    return $url . '?' . http_build_query($arguments);
}

require_once dirname(__DIR__) . '/src/Service/IcsService.php';

use ProOceanVan\Service\IcsService;

function expect_export(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "OK: {$message}\n";
}

$appointment = [
    'appointment_date' => '2026-08-06',
    'institution_name' => 'Testschule Meerblick',
    'street' => 'Hafenstraße',
    'house_number' => '1',
    'postal_code' => '73312',
    'city' => 'Geislingen an der Steige',
    'contact_name' => 'Test Kontakt',
    'contact_phone' => '0123',
    'contact_email' => 'test@example.org',
];
$link = (new IcsService())->googleLink($appointment, ['public_uuid' => 'OV-TEST']);
$query = [];
parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

expect_export(str_starts_with($link, 'https://calendar.google.com/calendar/render?'), 'confirmed appointments open the official Google Calendar template');
expect_export(($query['action'] ?? '') === 'TEMPLATE', 'Google Calendar opens a prefilled event template');
expect_export(($query['dates'] ?? '') === '20260806/20260807', 'the all-day appointment uses the correct date range');
expect_export(str_contains((string) ($query['location'] ?? ''), '73312'), 'the appointment address is included');
expect_export(str_contains((string) ($query['details'] ?? ''), 'OV-TEST'), 'the request reference is included');

$publicLink = (new IcsService())->googlePublicEventLink([
    'calendar_date' => '2026-08-08',
    'public_title' => 'Offener Meerestag',
    'public_location' => 'Hafenplatz',
    'public_description' => 'Öffentliches Programm',
    'public_url' => 'https://example.org/event',
], [
    'contact_first_name' => 'Mara',
    'contact_last_name' => 'Beispiel',
    'contact_phone' => '040 1234',
    'contact_email' => 'mara@example.org',
    'public_uuid' => 'OV-PUBLIC',
]);
$publicQuery = [];
parse_str((string) parse_url($publicLink, PHP_URL_QUERY), $publicQuery);
expect_export(str_contains((string) ($publicQuery['details'] ?? ''), 'Mara Beispiel'), 'public event export includes its linked contact');
expect_export(str_contains((string) ($publicQuery['details'] ?? ''), 'OV-PUBLIC'), 'public event export includes its request reference');

echo "Calendar-export checks passed.\n";
