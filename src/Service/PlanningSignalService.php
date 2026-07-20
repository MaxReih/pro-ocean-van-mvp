<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use ProOceanVan\Domain\WorkState;

final class PlanningSignalService
{
    public function build(array $appointments, array $requests, string $start, string $end, int $excludeRequestId = 0): array
    {
        $profile = [
            'weeks' => [],
            'demand_states' => [],
            'demand_state_count' => 0,
        ];

        foreach ($appointments as $key => $appointment) {
            $date = (string) ($appointment['appointment_date'] ?? (is_string($key) ? $key : ''));
            if (! $this->validDate($date) || $date < $start || $date > $end) {
                continue;
            }
            $this->addToWeek($profile, $this->week($date), (string) ($appointment['state_code'] ?? ''), 'confirmed');
        }

        foreach ($requests as $request) {
            if ((int) ($request['id'] ?? 0) === $excludeRequestId
                || in_array((string) ($request['work_state'] ?? ''), [WorkState::ACCEPTED, WorkState::REJECTED, WorkState::CANCELLED], true)) {
                continue;
            }
            $stateCode = strtoupper((string) ($request['state_code'] ?? ''));
            if ($stateCode !== '') {
                $profile['demand_states'][$stateCode] = true;
            }
            foreach ($this->requestWeeks($request, $start, $end) as $week) {
                $this->addToWeek($profile, $week, $stateCode, 'demand');
            }
        }

        $profile['demand_state_count'] = count($profile['demand_states']);
        return $profile;
    }

    public function forDate(array $profile, string $date, string $stateCode): array
    {
        $week = $this->week($date);
        $row = (array) (($profile['weeks'] ?? [])[$week] ?? []);
        $confirmedCount = (int) ($row['confirmed_count'] ?? 0);
        $demandCount = (int) ($row['demand_count'] ?? 0);
        $plannedCount = $confirmedCount + $demandCount;
        $stateCode = strtoupper($stateCode);
        $stateRow = (array) (($row['states'] ?? [])[$stateCode] ?? []);
        $sameStateCount = (int) ($stateRow['confirmed'] ?? 0) + (int) ($stateRow['demand'] ?? 0);
        $regionalCount = array_sum(array_map(static fn (array $state): int =>
            (int) ($state['confirmed'] ?? 0) + (int) ($state['demand'] ?? 0),
            (array) ($row['states'] ?? [])
        ));
        $otherStateCount = max(0, $regionalCount - $sameStateCount);
        $multiStateDemand = (int) ($profile['demand_state_count'] ?? 0) > 1;

        $fillBonus = $plannedCount > 0 ? min(48.0, 12.0 + ($plannedCount * 9.0)) : 0.0;
        $capacityPenalty = max(0, $plannedCount - 4) * 25.0;
        $stateBonus = $sameStateCount > 0 ? min(45.0, 10.0 + ($sameStateCount * 10.0)) : 0.0;
        $separationPenalty = 0.0;
        if ($multiStateDemand && $otherStateCount > 0) {
            $separationPenalty = $sameStateCount > 0
                ? min(12.0, $otherStateCount * 3.0)
                : min(30.0, 6.0 + ($otherStateCount * 6.0));
        }

        $codes = [];
        if ($plannedCount > 0) {
            $codes[] = 'WEEK_FILLING';
        }
        if ($sameStateCount > 0) {
            $codes[] = 'SAME_STATE_CLUSTER';
        }
        if ($multiStateDemand) {
            $codes[] = 'MULTI_STATE_AGGREGATION';
        }
        if ($multiStateDemand && $sameStateCount === 0 && $otherStateCount > 0) {
            $codes[] = 'STATE_BLOCK_CONFLICT';
        }
        if ($plannedCount >= 5) {
            $codes[] = 'WEEK_NEAR_CAPACITY';
        }

        return [
            'week' => $week,
            'confirmed_count' => $confirmedCount,
            'demand_count' => $demandCount,
            'week_load' => $plannedCount,
            'same_state_count' => $sameStateCount,
            'other_state_count' => $otherStateCount,
            'multi_state_demand' => $multiStateDemand,
            'score_adjustment' => round(-$fillBonus - $stateBonus + $capacityPenalty + $separationPenalty, 2),
            'codes' => $codes,
        ];
    }

    private function addToWeek(array &$profile, string $week, string $stateCode, string $type): void
    {
        if (! isset($profile['weeks'][$week])) {
            $profile['weeks'][$week] = [
                'confirmed_count' => 0,
                'demand_count' => 0,
                'states' => [],
            ];
        }
        $countKey = $type === 'confirmed' ? 'confirmed_count' : 'demand_count';
        $profile['weeks'][$week][$countKey]++;
        $stateCode = strtoupper(trim($stateCode));
        if ($stateCode === '') {
            return;
        }
        if (! isset($profile['weeks'][$week]['states'][$stateCode])) {
            $profile['weeks'][$week]['states'][$stateCode] = ['confirmed' => 0, 'demand' => 0];
        }
        $profile['weeks'][$week]['states'][$stateCode][$type]++;
    }

    private function requestWeeks(array $request, string $start, string $end): array
    {
        $plannedDate = (string) ($request['planned_suggestion_date'] ?? '');
        if ($this->validDate($plannedDate) && $plannedDate >= $start && $plannedDate <= $end) {
            return [$this->week($plannedDate)];
        }
        if (($request['request_mode'] ?? '') === 'specific_date') {
            $date = (string) ($request['specific_requested_date'] ?? '');
            if (! $this->validDate($date) || $date < $start || $date > $end || (int) (new DateTimeImmutable($date))->format('N') >= 6) {
                return [];
            }
            return [$this->week($date)];
        }

        $from = max($start, (string) ($request['desired_date_from'] ?? ''));
        $to = min($end, (string) ($request['desired_date_to'] ?? ''));
        if (! $this->validDate($from) || ! $this->validDate($to) || $from > $to) {
            return [];
        }
        $weekdays = array_filter(explode(',', (string) ($request['possible_weekdays'] ?? '')));
        $weeks = [];
        foreach (new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), (new DateTimeImmutable($to))->modify('+1 day')) as $day) {
            if ((int) $day->format('N') >= 6 || ($weekdays && ! in_array(strtolower($day->format('D')), $weekdays, true))) {
                continue;
            }
            $weeks[$day->format('o-W')] = true;
        }
        return array_keys($weeks);
    }

    private function week(string $date): string
    {
        return (new DateTimeImmutable($date))->format('o-W');
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }
}
