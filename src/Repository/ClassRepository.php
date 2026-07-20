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
}
