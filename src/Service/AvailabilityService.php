<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use ProOceanVan\Domain\CalendarState;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;

final class AvailabilityService
{
    public function publicCalendar(string $start, string $end): array
    {
        if (! $this->validDate($start) || ! $this->validDate($end) || $start > $end) {
            return [];
        }

        $startDate = new DateTimeImmutable($start, wp_timezone());
        $endDate = new DateTimeImmutable($end, wp_timezone());
        if (((int) $startDate->diff($endDate)->days + 1) > 62) {
            return [];
        }

        $calendarDays = (new CalendarDayRepository())->forRange($start, $end);
        $appointments = (new AppointmentRepository())->forRange($start, $end);
        $period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->modify('+1 day'));
        $today = new DateTimeImmutable('today', wp_timezone());
        $result = [];

        foreach ($period as $day) {
            $date = $day->format('Y-m-d');
            $isWeekend = (int) $day->format('N') >= 6;
            if ($day < $today) {
                $result[] = [
                    'date' => $date,
                    'public_state' => 'past',
                    'public_label' => 'Vergangen',
                    'public_city' => '',
                    'is_weekend' => $isWeekend,
                    'is_selectable' => false,
                ];
                continue;
            }
            $row = $calendarDays[$date] ?? null;
            if (($row['availability_state'] ?? '') === CalendarState::WALK_IN) {
                $title = trim((string) ($row['public_title'] ?? ''));
                $result[] = [
                    'date' => $date,
                    'public_state' => CalendarState::WALK_IN,
                    'public_label' => $title !== '' ? $title : 'Walk-in-Event',
                    'public_city' => '',
                    'is_weekend' => $isWeekend,
                    'is_selectable' => false,
                    'is_interactive' => true,
                    'public_event' => [
                        'title' => $title !== '' ? $title : 'Walk-in-Event',
                        'description' => (string) ($row['public_description'] ?? ''),
                        'location' => (string) ($row['public_location'] ?? ''),
                        'url' => (string) ($row['public_url'] ?? ''),
                    ],
                ];
                continue;
            }
            if (isset($appointments[$date])) {
                $city = (string) $appointments[$date]['public_city'];
                $result[] = [
                    'date' => $date,
                    'public_state' => CalendarState::TOUR,
                    'public_label' => 'Ocean Van in ' . $city,
                    'public_city' => $city,
                    'is_selectable' => false,
                ];
                continue;
            }

            $state = $row ? (string) $row['availability_state'] : CalendarState::AVAILABLE;
            if ($isWeekend && $state === CalendarState::AVAILABLE) {
                $state = CalendarState::LIMITED;
            }
            $result[] = [
                'date' => $date,
                'public_state' => $state,
                'public_label' => $this->label($state),
                'public_city' => '',
                'is_weekend' => $isWeekend,
                'is_selectable' => ! $isWeekend && $state !== CalendarState::UNAVAILABLE && $day >= $today,
                'is_interactive' => false,
            ];
        }

        return $result;
    }

    private function label(string $state): string
    {
        return match ($state) {
            CalendarState::LIMITED => 'Auf Anfrage',
            CalendarState::UNAVAILABLE => 'Nicht buchbar',
            default => 'Buchbar',
        };
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}
