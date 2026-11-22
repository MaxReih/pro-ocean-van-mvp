<?php

declare(strict_types=1);

namespace ProOceanVan\Domain;

final class SchoolClasses
{
    public static function normalize(array $rows, array $grades): array
    {
        if (! $rows || count($rows) > 10) {
            return [];
        }
        $classes = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                return [];
            }
            $grade = filter_var($row['grade'] ?? null, FILTER_VALIDATE_INT);
            $children = filter_var($row['children'] ?? null, FILTER_VALIDATE_INT);
            $teachers = filter_var($row['teachers'] ?? null, FILTER_VALIDATE_INT);
            $adults = filter_var($row['adults'] ?? null, FILTER_VALIDATE_INT);
            if (! in_array($grade, $grades, true) || $children === false || $children < 1 || $children > 100
                || $teachers === false || $teachers < 0 || $teachers > 20 || $adults === false || $adults < 0 || $adults > 100) {
                return [];
            }
            $classes[] = [
                'class_name' => 'Klasse ' . ($index + 1),
                'grade' => $grade,
                'children' => $children,
                'teachers' => $teachers,
                'adults' => $adults,
                'participant_count' => $children + $teachers + $adults,
            ];
        }
        return array_sum(array_column($classes, 'participant_count')) <= 500 ? $classes : [];
    }

    public static function configuredGrades(): array
    {
        $grades = array_map('trim', explode(',', (string) get_option('pov_school_grade_options', '3,4')));
        $grades = array_filter($grades, static fn (string $grade): bool => ctype_digit($grade) && (int) $grade >= 1 && (int) $grade <= 13);
        return array_values(array_unique(array_map('intval', $grades))) ?: [3, 4];
    }
}
