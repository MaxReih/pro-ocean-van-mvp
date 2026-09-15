<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;

final class SeasonalStateService
{
    private const OPTION = 'pov_state_windows';

    public function all(): array
    {
        $rows = get_option(self::OPTION, []);
        if (! is_array($rows)) {
            return [];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['from'] ?? ''), (string) ($b['from'] ?? '')));
        return $rows;
    }

    public function add(string $from, string $to, array $states): bool
    {
        if (! $this->validDate($from) || ! $this->validDate($to) || $from > $to) {
            return false;
        }
        $states = array_values(array_unique(array_filter(array_map(
            static fn (mixed $state): string => strtoupper(sanitize_key((string) $state)),
            $states
        ))));
        if (! $states) {
            return false;
        }
        $rows = $this->all();
        $rows[] = [
            'id' => wp_generate_uuid4(),
            'from' => $from,
            'to' => $to,
            'states' => $states,
        ];
        update_option(self::OPTION, $rows, false);
        return true;
    }

    public function delete(string $id): void
    {
        $rows = array_values(array_filter($this->all(), static fn (array $row): bool => ! hash_equals((string) ($row['id'] ?? ''), $id)));
        update_option(self::OPTION, $rows, false);
    }

    public function allows(string $stateCode, string $date): bool
    {
        $stateCode = strtoupper(sanitize_key($stateCode));
        $matching = array_values(array_filter($this->all(), static fn (array $row): bool => $date >= (string) ($row['from'] ?? '') && $date <= (string) ($row['to'] ?? '')));
        if (! $matching) {
            return true;
        }
        foreach ($matching as $row) {
            if (in_array($stateCode, (array) ($row['states'] ?? []), true)) {
                return true;
            }
        }
        return false;
    }

    public function rangeHasAllowedDay(string $stateCode, string $from, string $to, array $weekdays): bool
    {
        if (! $this->validDate($from) || ! $this->validDate($to) || $from > $to) {
            return false;
        }
        $keys = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        $period = new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), (new DateTimeImmutable($to))->modify('+1 day'));
        foreach ($period as $day) {
            if (in_array($keys[(int) $day->format('N')], $weekdays, true) && $this->allows($stateCode, $day->format('Y-m-d'))) {
                return true;
            }
        }
        return false;
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
