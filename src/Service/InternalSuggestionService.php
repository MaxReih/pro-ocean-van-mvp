<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\SuggestionRepository;

final class InternalSuggestionService
{
    private const LIMITED_DAY_PENALTY_EUR = 25.0;

    public function recalculate(int $requestId): array
    {
        $request = (new RequestRepository())->find($requestId);
        if (! $request) {
            return [];
        }
        if (! is_numeric($request['latitude'] ?? null) || ! is_numeric($request['longitude'] ?? null)) {
            $request = (new RequestRoutingService())->refreshRequest($requestId) ?? $request;
        }

        $from = $request['request_mode'] === 'specific_date' ? (string) $request['specific_requested_date'] : (string) $request['desired_date_from'];
        $to = $request['request_mode'] === 'specific_date' ? (string) $request['specific_requested_date'] : (string) $request['desired_date_to'];
        if (! $from || ! $to) {
            $from = (new DateTimeImmutable('today'))->format('Y-m-d');
            $to = (new DateTimeImmutable('+60 days'))->format('Y-m-d');
        }

        $calendar = (new CalendarDayRepository())->forRange($from, $to);
        $appointments = (new AppointmentRepository())->forRange($from, $to);
        $planningSignals = new PlanningSignalService();
        $planningProfile = $planningSignals->build(
            $appointments,
            (new RequestRepository())->planningForRange($from, $to),
            $from,
            $to,
            $requestId
        );
        $weekdays = array_filter(explode(',', (string) ($request['possible_weekdays'] ?? '')));
        $suggestions = [];
        $comparisonCache = [];
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        foreach (new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), (new DateTimeImmutable($to))->modify('+1 day')) as $day) {
            $date = $day->format('Y-m-d');
            if ($date < $today || (int) $day->format('N') >= 6 || isset($appointments[$date])) {
                continue;
            }
            if ($weekdays && ! in_array(strtolower($day->format('D')), $weekdays, true)) {
                continue;
            }
            $calendarState = (string) ($calendar[$date]['availability_state'] ?? 'available');
            if ($calendarState === 'unavailable') {
                continue;
            }

            $depot = $this->startPoint($calendar[$date] ?? null);
            $week = $day->format('o-W');
            $cacheKey = $week . ':' . $this->insertionSlot($appointments, $week, $date) . ':' . md5(wp_json_encode($depot));
            if (! array_key_exists($cacheKey, $comparisonCache)) {
                $comparisonCache[$cacheKey] = $this->routeComparison($request, $appointments, $week, $date, $depot);
            }
            $comparison = $comparisonCache[$cacheKey];
            $distance = $comparison['incremental_distance_km'] ?? ($request['route_distance_km'] ?: null);
            $cost = $comparison['incremental_cost'] ?? ($request['route_cost'] ?: null);
            $savings = (float) ($comparison['cost_saved'] ?? 0);
            $anchorCount = (int) ($comparison['anchor_count'] ?? 0);
            $score = is_numeric($cost) ? (float) $cost : 10000.0;
            $codes = [];

            if ($calendarState === 'limited') {
                $score += self::LIMITED_DAY_PENALTY_EUR;
                $codes[] = 'LIMITED_DAY';
            }
            if ($anchorCount > 0) {
                $codes[] = 'SAME_TOUR_CHAIN';
            } else {
                $codes[] = 'DIRECT_DAY_TRIP';
            }
            if ($savings >= 10) {
                $codes[] = 'LOW_INCREMENTAL_COST';
                $score -= min(30.0, $savings * 0.15);
            }
            $planning = $planningSignals->forDate($planningProfile, $date, (string) ($request['state_code'] ?? ''));
            $codes = array_merge($codes, $planning['codes']);
            $score += (float) $planning['score_adjustment'];
            if (($comparison['matrix_source'] ?? '') !== 'osrm') {
                $codes[] = 'ROUTING_ESTIMATED';
                $score += 5.0;
            }

            $suggestions[] = [
                'date' => $date,
                'type' => $this->suggestionType($codes, $anchorCount),
                'score' => round($score + ((int) $day->format('N') / 100), 2),
                'distance_km' => is_numeric($distance) ? (float) $distance : null,
                'cost' => is_numeric($cost) ? (float) $cost : null,
                'reason_codes' => $codes,
                'reason_summary' => $this->reasonSummary($distance, $cost, $savings, $anchorCount, (string) ($comparison['matrix_source'] ?? 'unavailable'), $planning),
            ];
        }

        usort($suggestions, static fn (array $left, array $right): int => [$left['score'], $left['date']] <=> [$right['score'], $right['date']]);
        $suggestions = array_slice($suggestions, 0, 5);
        (new SuggestionRepository())->replaceGenerated($requestId, $suggestions);
        return $suggestions;
    }

    private function routeComparison(array $request, array $appointments, string $week, string $candidateDate, ?array $depot): array
    {
        if (! $depot || ! is_numeric($request['latitude'] ?? null) || ! is_numeric($request['longitude'] ?? null)) {
            return [];
        }

        $anchors = [];
        foreach ($appointments as $date => $appointment) {
            if ((new DateTimeImmutable($date))->format('o-W') !== $week) {
                continue;
            }
            if (is_numeric($appointment['latitude'] ?? null) && is_numeric($appointment['longitude'] ?? null)) {
                $anchors[] = $appointment;
            }
        }

        return (new RouteOptimizationService())->compareDatedInsertion($anchors, $request, $candidateDate, $depot);
    }

    private function startPoint(?array $day): ?array
    {
        if ($day && is_numeric($day['custom_start_latitude'] ?? null) && is_numeric($day['custom_start_longitude'] ?? null)) {
            return ['latitude' => (float) $day['custom_start_latitude'], 'longitude' => (float) $day['custom_start_longitude']];
        }
        if (! is_numeric(get_option('pov_default_start_latitude')) || ! is_numeric(get_option('pov_default_start_longitude'))) {
            return null;
        }
        return ['latitude' => (float) get_option('pov_default_start_latitude'), 'longitude' => (float) get_option('pov_default_start_longitude')];
    }

    private function insertionSlot(array $appointments, string $week, string $candidateDate): int
    {
        $slot = 0;
        foreach (array_keys($appointments) as $appointmentDate) {
            if ((new DateTimeImmutable($appointmentDate))->format('o-W') === $week && $appointmentDate <= $candidateDate) {
                $slot++;
            }
        }
        return $slot;
    }

    private function suggestionType(array $codes, int $anchorCount): string
    {
        if (in_array('SAME_STATE_CLUSTER', $codes, true)) {
            return 'state_clustered_week';
        }
        if (in_array('WEEK_FILLING', $codes, true)) {
            return 'week_fill';
        }
        return $anchorCount > 0 ? 'route_optimized_insertion' : 'route_optimized_day_trip';
    }

    private function reasonSummary(mixed $distance, mixed $cost, float $savings, int $anchorCount, string $source, array $planning): string
    {
        if (! is_numeric($distance) || ! is_numeric($cost)) {
            return 'Termin ist frei; die Route muss noch manuell geprüft werden.';
        }

        $prefix = $anchorCount > 0 ? 'Einfügung in eine bestehende Tour' : 'Direkte Tagestour';
        if ((int) ($planning['same_state_count'] ?? 0) > 0) {
            $prefix = 'Regional gebündelte Woche';
        } elseif ((int) ($planning['week_load'] ?? 0) > 0) {
            $prefix = 'Ergänzung einer geplanten Woche';
        }
        $summary = sprintf(
            '%s: ca. %s km Zusatzfahrt und %s € variable Kosten.',
            $prefix,
            number_format((float) $distance, 0, ',', '.'),
            number_format((float) $cost, 2, ',', '.')
        );
        if ($savings >= 1) {
            $summary .= ' Spart ca. ' . number_format($savings, 2, ',', '.') . ' € gegenüber einer Einzelfahrt.';
        }
        if ($source !== 'osrm') {
            $summary .= ' Näherungswert – Routing bitte prüfen.';
        }
        return $summary;
    }
}
