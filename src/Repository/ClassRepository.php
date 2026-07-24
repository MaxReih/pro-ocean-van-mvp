<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

final class ClassRepository
{
    public function forRequest(int $requestId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_request_classes';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE request_id = %d ORDER BY sort_order ASC, id ASC",
            $requestId
        ), ARRAY_A) ?: [];
    }

    public function replaceForRequest(int $requestId, array $classes): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_request_classes';
        $wpdb->delete($table, ['request_id' => $requestId]);
        $order = 0;
        $participantTotal = 0;
        foreach (array_slice($classes, 0, 10) as $class) {
            if (! is_array($class)) {
                continue;
            }
            $participants = min(500 - $participantTotal, max(1, (int) ($class['participant_count'] ?? 0)));
            if ($participants <= 0) {
                break;
            }
            $grade = min(6, max(1, (int) ($class['grade'] ?? 1)));
            $wpdb->insert($table, [
                'request_id' => $requestId,
                'class_name' => sanitize_text_field((string) ($class['class_name'] ?? '')),
                'grade' => $grade,
                'participant_count' => $participants,
                'sort_order' => $order,
                'created_at' => current_time('mysql'),
            ]);
            $participantTotal += $participants;
            $order += 10;
        }
    }

    public function participantCount(int $requestId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_request_classes';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(participant_count) FROM {$table} WHERE request_id = %d", $requestId));
    }

    public function updateFirstName(int $requestId, string $name): void
    {
        if ($requestId <= 0 || trim($name) === '') {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pov_request_classes';
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE request_id = %d ORDER BY sort_order ASC, id ASC LIMIT 1",
            $requestId
        ));
        if ($id > 0) {
            $wpdb->update($table, ['class_name' => sanitize_text_field($name)], ['id' => $id]);
        }
    }

    public function syncParticipantTotal(int $requestId, int $participantTotal): void
    {
        if ($requestId <= 0 || $participantTotal < 0) {
            return;
        }

        $classes = $this->forRequest($requestId);
        if (! $classes) {
            return;
        }

        $weightTotal = array_sum(array_map(
            static fn (array $class): int => max(0, (int) ($class['participant_count'] ?? 0)),
            $classes
        ));
        $weights = [];
        foreach ($classes as $index => $class) {
            $weight = $weightTotal > 0 ? max(0, (int) ($class['participant_count'] ?? 0)) : 1;
            $exact = $participantTotal * $weight / ($weightTotal > 0 ? $weightTotal : count($classes));
            $weights[$index] = [
                'count' => (int) floor($exact),
                'fraction' => $exact - floor($exact),
            ];
        }

        $assigned = array_sum(array_column($weights, 'count'));
        $remainder = $participantTotal - $assigned;
        $order = array_keys($weights);
        usort($order, static function (int $left, int $right) use ($weights): int {
            return $weights[$right]['fraction'] <=> $weights[$left]['fraction'];
        });
        for ($index = 0; $index < $remainder; $index++) {
            $weights[$order[$index % count($order)]]['count']++;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pov_request_classes';
        foreach ($classes as $index => $class) {
            $wpdb->update(
                $table,
                ['participant_count' => $weights[$index]['count']],
                ['id' => (int) $class['id']]
            );
        }
    }
}
