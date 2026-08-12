<?php

declare(strict_types=1);

namespace ProOceanVan\Rest;

use ProOceanVan\Service\AvailabilityService;
use ProOceanVan\Security\FrontendAccess;
use DateTimeImmutable;
use WP_Error;
use WP_REST_Request;

final class PublicCalendarController
{
    private string $namespace = 'pro-ocean-van/v1';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/calendar', [
            'methods' => 'GET',
            'callback' => [$this, 'calendar'],
            'permission_callback' => [FrontendAccess::class, 'authorizeRest'],
            'args' => [
                'start' => ['sanitize_callback' => 'sanitize_text_field'],
                'end' => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public function calendar(WP_REST_Request $request): array|WP_Error
    {
        $today = new DateTimeImmutable('today', wp_timezone());
        $defaultStart = $today->modify('first day of this month')->format('Y-m-d');
        $start = $this->dateOrDefault((string) $request->get_param('start'), $defaultStart);
        if (! $this->validDate($start)) {
            return new WP_Error('pov_invalid_calendar_range', 'Bitte prüfe den Kalenderzeitraum.', ['status' => 422]);
        }
        $defaultEnd = (new DateTimeImmutable($start, wp_timezone()))->modify('last day of this month')->format('Y-m-d');
        $end = $this->dateOrDefault((string) $request->get_param('end'), $defaultEnd);

        if (! $this->validDate($end) || $start > $end) {
            return new WP_Error('pov_invalid_calendar_range', 'Bitte prüfe den Kalenderzeitraum.', ['status' => 422]);
        }

        $startDate = new DateTimeImmutable($start, wp_timezone());
        $endDate = new DateTimeImmutable($end, wp_timezone());
        if (((int) $startDate->diff($endDate)->days + 1) > 62) {
            return new WP_Error('pov_calendar_range_too_large', 'Der Kalenderzeitraum darf höchstens 62 Tage umfassen.', ['status' => 422]);
        }

        return (new AvailabilityService())->publicCalendar($start, $end);
    }

    private function dateOrDefault(string $date, string $default): string
    {
        return $date === '' ? $default : $date;
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}
