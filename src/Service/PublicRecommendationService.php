<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use ProOceanVan\Domain\CalendarState;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\StateRepository;
use ProOceanVan\Routing\ProviderFactory;

final class PublicRecommendationService
{
    private const LIMITED_DAY_PENALTY = 25.0;
    private const MIN_RECOMMENDATION_LEAD_DAYS = 14;

    public function recommend(string $postalCode, string $stateCode): array
    {
        $postalCode = preg_replace('/\D+/', '', $postalCode);
        $stateCode = strtoupper(sanitize_key($stateCode));
        if (! preg_match('/^\d{5}$/', $postalCode)) {
            return ['ok' => false, 'message' => 'Bitte prüfe die PLZ.', 'suggestions' => [], 'eligible_dates' => [], 'filter_applied' => false, 'fallback' => true];
        }
        if (! (new StateRepository())->isActive($stateCode)) {
            return ['ok' => false, 'message' => 'Für dieses Bundesland nehmen wir aktuell noch keine Anfragen an.', 'suggestions' => [], 'eligible_dates' => [], 'filter_applied' => false, 'fallback' => true];
        }
        $tokenService = new EligibilityTokenService();
        if (! (new CostService())->isConfigured()) {
            return ['ok' => true, 'message' => 'Kein passender Routentermin gefunden.', 'suggestions' => [], 'eligible_dates' => [], 'eligibility_token' => $tokenService->issue($postalCode, $stateCode, []), 'filter_applied' => true, 'fallback' => true];
        }

        $states = new StateRepository();
        $location = (new PostalCodeService())->resolve($postalCode, $stateCode);
        $geoAddress = ['postal_code' => $postalCode, 'state_code' => $stateCode];
        if (! empty($location['ok'])) {
            $geoAddress['city'] = (string) $location['city'];
            $geoAddress['state'] = (string) $location['state_name'];
        } else {
            foreach ($states->all(false) as $state) {
                if (strtoupper((string) ($state['state_code'] ?? '')) === $stateCode) {
                    $geoAddress['state'] = (string) ($state['state_name'] ?? '');
                    break;
                }
            }
        }

        $factory = new ProviderFactory();
        $geo = $factory->geocoding()->geocodeAddress($geoAddress);
        $exactPostalMatch = (string) ($geo['postal_code'] ?? '') === $postalCode;
        if (empty($location['ok']) && ! $exactPostalMatch) {
            $geo = ['ok' => false, 'error' => (string) ($location['error'] ?? 'PLZ nicht gefunden.')];
        }
        if (empty($geo['ok'])) {
            return ['ok' => true, 'message' => 'Die Routenprüfung ist gerade nicht verfügbar. Du kannst trotzdem einen Zeitraum anfragen.', 'suggestions' => [], 'eligible_dates' => [], 'eligibility_token' => $tokenService->issue($postalCode, $stateCode, []), 'filter_applied' => true, 'fallback' => true];
        }

        $today = new DateTimeImmutable('today', wp_timezone());
        $start = $today->modify('+' . self::MIN_RECOMMENDATION_LEAD_DAYS . ' days');
        $end = $today->modify('+' . max(30, (int) get_option('pov_public_booking_horizon_days', 365)) . ' days');
        $calendar = (new CalendarDayRepository())->forRange($start->format('Y-m-d'), $end->format('Y-m-d'));
        $appointments = (new AppointmentRepository())->forRange($start->format('Y-m-d'), $end->format('Y-m-d'));
        $planningSignals = new PlanningSignalService();
        $planningProfile = $planningSignals->build(
            $appointments,
            (new RequestRepository())->planningForRange($start->format('Y-m-d'), $end->format('Y-m-d')),
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );
        $suggestions = [];
        $comparisonCache = [];

        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $day) {
            $date = $day->format('Y-m-d');
            if ((int) $day->format('N') >= 6) {
                continue;
            }
            if (isset($appointments[$date])) {
                continue;
            }
            $dayRow = $calendar[$date] ?? null;
            $state = $dayRow['availability_state'] ?? CalendarState::AVAILABLE;
            if (in_array($state, [CalendarState::UNAVAILABLE, CalendarState::WALK_IN], true)) {
                continue;
            }

            $startPoint = $this->startPoint($dayRow);
            if (! $startPoint) {
                continue;
            }
            $week = $day->format('o-W');
            $anchors = $this->weekAnchors($week, $appointments);
            $anchorKey = $anchors ? md5(wp_json_encode(array_map(static fn (array $anchor): array => [
                'appointment_date' => $anchor['appointment_date'] ?? null,
                'latitude' => $anchor['latitude'] ?? null,
                'longitude' => $anchor['longitude'] ?? null,
            ], $anchors))) : 'direct';
            $insertionSlot = $this->insertionSlot($anchors, $date);
            $cacheKey = $anchorKey . ':' . $insertionSlot . ':' . md5(wp_json_encode($startPoint));
            if (! isset($comparisonCache[$cacheKey])) {
                $comparisonCache[$cacheKey] = (new RouteOptimizationService())->compareDatedInsertion(
                    $anchors,
                    [
                        'latitude' => (float) $geo['latitude'],
                        'longitude' => (float) $geo['longitude'],
                        'label' => $postalCode,
                    ],
                    $date,
                    ['latitude' => $startPoint['lat'], 'longitude' => $startPoint['lon']]
                );
            }
            $comparison = $comparisonCache[$cacheKey];
            if (! $comparison) {
                continue;
            }

            $effectiveDistance = (float) $comparison['incremental_distance_km'];
            $baselineDistance = (float) $comparison['standalone_distance_km'];
            $estimatedSavingsKm = (float) $comparison['distance_saved_km'];
            $cost = (float) $comparison['incremental_cost'];
            if (! $this->withinLimits($effectiveDistance, $cost)) {
                continue;
            }

            $codes = [$startPoint['custom'] ? 'CUSTOM_START_POINT' : 'DEFAULT_START_POINT'];
            $anchorCount = (int) $comparison['anchor_count'];
            $codes[] = $anchorCount > 0 ? 'SAME_TOUR_CHAIN' : 'DIRECT_DAY_TRIP';
            $insertionContext = (string) ($comparison['insertion_context'] ?? 'direct_trip');
            if ($insertionContext === 'return_route') {
                $codes[] = 'EXTENDS_RETURN_ROUTE';
            } elseif ($insertionContext === 'between_stops') {
                $codes[] = 'BETWEEN_TOUR_STOPS';
            }
            if ($this->adjacentToAppointment($date, $appointments)) {
                $codes[] = 'ADJACENT_TO_CONFIRMED_DATE';
            }
            if ($estimatedSavingsKm >= 10) {
                $codes[] = 'LOW_INCREMENTAL_COST';
            }
            $planning = $planningSignals->forDate($planningProfile, $date, $stateCode);
            $codes = array_merge($codes, $planning['codes']);
            if (($comparison['matrix_source'] ?? '') !== 'osrm') {
                $codes[] = 'ROUTING_ESTIMATED';
            }

            $score = $cost + ($effectiveDistance / 5);
            if ($state === CalendarState::LIMITED) {
                $score += self::LIMITED_DAY_PENALTY;
                $codes[] = 'LIMITED_DAY';
            }
            $score -= min(50.0, (float) $comparison['cost_saved'] * 0.15);
            if ($anchorCount > 0) {
                $score -= 15.0;
            }
            if ($insertionContext === 'return_route') {
                $score -= 20.0;
            } elseif ($insertionContext === 'between_stops') {
                $score -= 10.0;
            }
            if (($comparison['matrix_source'] ?? '') !== 'osrm') {
                $score += 5.0;
            }
            if ($cost < ((float) get_option('pov_max_public_suggestion_cost', 999999) * 0.6)) {
                $codes[] = 'LOW_TRAVEL_COST';
            }
            $score += (float) $planning['score_adjustment'];

            $suggestions[] = [
                'date' => $date,
                'label' => GermanDateFormatter::full($date),
                'reason' => $this->publicReason($codes),
                'reason_codes' => array_values(array_unique($codes)),
                'score' => round($score, 2),
                'estimated_distance_km' => round($effectiveDistance, 1),
                'baseline_distance_km' => round($baselineDistance, 1),
                'estimated_cost' => $cost,
                'estimated_savings_km' => round($estimatedSavingsKm, 1),
                'estimated_savings_cost' => (float) $comparison['cost_saved'],
                'planning_context' => [
                    'week_load' => (int) $planning['week_load'],
                    'same_state_count' => (int) $planning['same_state_count'],
                    'multi_state_demand' => (bool) $planning['multi_state_demand'],
                ],
                'routing_precision' => ($comparison['matrix_source'] ?? '') === 'osrm' ? 'road_matrix' : 'geographic_estimate',
            ];
        }

        usort($suggestions, static function (array $a, array $b): int {
            $score = $a['score'] <=> $b['score'];
            return $score !== 0 ? $score : strcmp((string) $a['date'], (string) $b['date']);
        });
        $weekSuggestions = $this->weekSuggestions($suggestions);
        $eligibleDates = array_values(array_unique(array_map(
            static fn (array $suggestion): string => (string) $suggestion['date'],
            $suggestions
        )));
        $suggestions = array_slice($suggestions, 0, 2);
        $publicSuggestions = array_map(static fn (array $suggestion): array => [
            'date' => (string) $suggestion['date'],
            'label' => (string) $suggestion['label'],
        ], $suggestions);
        $publicWeeks = array_map(static fn (array $week): array => [
            'date_from' => (string) $week['date_from'],
            'date_to' => (string) $week['date_to'],
            'label' => (string) $week['label'],
            'available_days' => array_values(array_map('strval', (array) $week['available_days'])),
        ], $weekSuggestions);

        return [
            'ok' => true,
            'message' => $suggestions ? 'Diese Tage passen gut zu unserer Route' : 'Kein passender Routentermin gefunden.',
            'suggestions' => $publicSuggestions,
            'week_suggestions' => $publicWeeks,
            'eligible_dates' => $eligibleDates,
            'eligibility_token' => $tokenService->issue($postalCode, $stateCode, $eligibleDates),
            'filter_applied' => true,
            'fallback' => ! $suggestions,
        ];
    }

    private function startPoint(?array $dayRow): ?array
    {
        if ($dayRow && is_numeric($dayRow['custom_start_latitude']) && is_numeric($dayRow['custom_start_longitude'])) {
            return ['lat' => (float) $dayRow['custom_start_latitude'], 'lon' => (float) $dayRow['custom_start_longitude'], 'custom' => true];
        }
        if (is_numeric(get_option('pov_default_start_latitude')) && is_numeric(get_option('pov_default_start_longitude'))) {
            return ['lat' => (float) get_option('pov_default_start_latitude'), 'lon' => (float) get_option('pov_default_start_longitude'), 'custom' => false];
        }
        return null;
    }

    private function withinLimits(float $distance, float $cost): bool
    {
        $maxDistance = (float) get_option('pov_max_public_suggestion_distance_km', 0);
        $maxCost = (float) get_option('pov_max_public_suggestion_cost', 0);
        if ($maxDistance > 0 && $distance > $maxDistance) {
            return false;
        }
        if ($maxCost > 0 && $cost > $maxCost) {
            return false;
        }
        return true;
    }

    private function weekAnchors(string $week, array $appointments): array
    {
        $anchors = [];
        foreach ($appointments as $date => $appointment) {
            if ((new DateTimeImmutable($date))->format('o-W') !== $week) {
                continue;
            }
            if (is_numeric($appointment['latitude'] ?? null) && is_numeric($appointment['longitude'] ?? null)) {
                $anchors[] = $appointment;
            }
        }
        return $anchors;
    }

    private function adjacentToAppointment(string $date, array $appointments): bool
    {
        $candidate = new DateTimeImmutable($date);
        foreach (array_keys($appointments) as $appointmentDate) {
            if ($candidate->format('o-W') === (new DateTimeImmutable($appointmentDate))->format('o-W')
                && abs((int) $candidate->diff(new DateTimeImmutable($appointmentDate))->format('%r%a')) === 1) {
                return true;
            }
        }
        return false;
    }

    private function insertionSlot(array $anchors, string $candidateDate): int
    {
        $slot = 0;
        foreach ($anchors as $anchor) {
            if ((string) ($anchor['appointment_date'] ?? '') <= $candidateDate) {
                $slot++;
            }
        }
        return $slot;
    }

    private function publicReason(array $codes): string
    {
        if (in_array('ADJACENT_TO_CONFIRMED_DATE', $codes, true)) {
            return 'Passt direkt zu einem Einsatz in derselben Woche.';
        }
        if (in_array('SAME_TOUR_CHAIN', $codes, true)) {
            return 'Lässt sich günstig in eine Tour einfügen.';
        }
        return 'Günstige direkte Fahrt ab dem Startpunkt.';
    }

    private function weekSuggestions(array $suggestions): array
    {
        $weeks = [];
        foreach ($suggestions as $suggestion) {
            $day = new DateTimeImmutable((string) $suggestion['date']);
            $weekKey = $day->format('o-W');
            if (! isset($weeks[$weekKey])) {
                $monday = $day->modify('monday this week');
                $friday = $monday->modify('+4 days');
                $weeks[$weekKey] = [
                    'date_from' => $monday->format('Y-m-d'),
                    'date_to' => $friday->format('Y-m-d'),
                    'label' => GermanDateFormatter::dayMonth($monday->format('Y-m-d')) . ' bis ' . GermanDateFormatter::dayMonth($friday->format('Y-m-d')),
                    'available_days' => [],
                    'score' => 0.0,
                    'estimated_savings_km' => 0.0,
                    'estimated_distance_km' => 0.0,
                ];
            }
            $weeks[$weekKey]['available_days'][] = $suggestion['date'];
            $weeks[$weekKey]['score'] += (float) $suggestion['score'];
            $weeks[$weekKey]['estimated_savings_km'] += (float) ($suggestion['estimated_savings_km'] ?? 0);
            $weeks[$weekKey]['estimated_distance_km'] += (float) ($suggestion['estimated_distance_km'] ?? 0);
        }

        $weeks = array_filter($weeks, static fn (array $week): bool => count($week['available_days']) >= 2);
        foreach ($weeks as &$week) {
            $count = count($week['available_days']);
            $week['score'] = round(($week['score'] / $count) - ($count * 8), 2);
            $week['estimated_savings_km'] = round($week['estimated_savings_km'] / $count, 1);
            $week['estimated_distance_km'] = round($week['estimated_distance_km'] / $count, 1);
            $week['reason'] = $count . ' passende Werktage für eine flexible Tour.';
        }
        unset($week);

        usort($weeks, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        return array_slice(array_values($weeks), 0, 2);
    }
}
