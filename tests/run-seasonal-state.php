<?php

declare(strict_types=1);

$GLOBALS['pov_test_options'] = [];

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['pov_test_options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, bool $autoload = false): bool
{
    $GLOBALS['pov_test_options'][$name] = $value;
    return true;
}

function sanitize_key(string $value): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?: '';
}

function wp_generate_uuid4(): string
{
    return '00000000-0000-4000-8000-' . str_pad((string) count($GLOBALS['pov_test_options']), 12, '0', STR_PAD_LEFT);
}

require_once dirname(__DIR__) . '/src/Service/SeasonalStateService.php';

use ProOceanVan\Service\SeasonalStateService;

function expect_season(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "OK: {$message}\n";
}

$service = new SeasonalStateService();
expect_season($service->allows('BE', '2027-01-12'), 'without a seasonal window the global state selection remains authoritative');
expect_season($service->add('2027-01-01', '2027-02-28', ['HH', 'NI']), 'a valid seasonal state window can be saved');
expect_season($service->allows('HH', '2027-01-12'), 'a selected state is allowed inside the seasonal window');
expect_season(! $service->allows('BE', '2027-01-12'), 'an unselected state is blocked inside the seasonal window');
expect_season($service->allows('BE', '2027-03-01'), 'states remain unrestricted outside seasonal windows');
expect_season($service->rangeHasAllowedDay('NI', '2027-01-11', '2027-01-15', ['mon', 'wed']), 'range requests detect an allowed weekday');
expect_season(! $service->rangeHasAllowedDay('BE', '2027-01-11', '2027-01-15', ['mon', 'wed']), 'range requests reject a fully blocked state window');

echo "Seasonal-state checks passed.\n";
