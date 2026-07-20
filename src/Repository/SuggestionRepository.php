<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

use ProOceanVan\Domain\SuggestionState;

final class SuggestionRepository
{
    public function forRequest(int $requestId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_suggestions';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE request_id = %d ORDER BY sort_order ASC, score ASC, suggestion_date ASC",
            $requestId
        ), ARRAY_A) ?: [];
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_suggestions';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public function replaceGenerated(int $requestId, array $suggestions): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_suggestions';
        $wpdb->delete($table, ['request_id' => $requestId, 'state' => SuggestionState::PENDING]);
        $sortOrder = 1;
        foreach (array_slice($suggestions, 0, 5) as $suggestion) {
            $wpdb->insert($table, [
                'request_id' => $requestId,
                'suggestion_date' => $suggestion['date'],
                'suggestion_type' => $suggestion['type'] ?? 'internal_request_match',
                'sort_order' => $sortOrder,
                'state' => SuggestionState::PENDING,
                'score' => $suggestion['score'] ?? null,
                'estimated_distance_km' => $suggestion['distance_km'] ?? null,
                'estimated_cost' => $suggestion['cost'] ?? null,
                'reason_codes' => wp_json_encode($suggestion['reason_codes'] ?? []),
                'reason_summary' => sanitize_text_field((string) ($suggestion['reason_summary'] ?? '')),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            $sortOrder++;
        }
    }

    public function setState(int $id, string $state): void
    {
        global $wpdb;
        $allowed = [
            SuggestionState::PENDING,
            SuggestionState::ACCEPTED,
            SuggestionState::REJECTED,
            SuggestionState::SENT,
            SuggestionState::EXPIRED,
        ];
        if (! in_array($state, $allowed, true)) {
            return;
        }
        $wpdb->update($wpdb->prefix . 'pov_suggestions', [
            'state' => $state,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public function acceptedForRequest(int $requestId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pov_suggestions WHERE request_id = %d AND state = %s ORDER BY sort_order ASC LIMIT 5",
            $requestId,
            SuggestionState::ACCEPTED
        ), ARRAY_A) ?: [];
    }

    public function markSentForRequest(int $requestId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}pov_suggestions SET state = %s, updated_at = %s WHERE request_id = %d AND state = %s",
            SuggestionState::SENT,
            current_time('mysql'),
            $requestId,
            SuggestionState::ACCEPTED
        ));
    }

    public function markSent(array $suggestionIds): void
    {
        global $wpdb;
        $suggestionIds = array_values(array_unique(array_filter(array_map('intval', $suggestionIds))));
        if (! $suggestionIds) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($suggestionIds), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}pov_suggestions SET state = %s, updated_at = %s WHERE id IN ({$placeholders}) AND state = %s",
            array_merge([SuggestionState::SENT, current_time('mysql')], $suggestionIds, [SuggestionState::ACCEPTED])
        ));
    }

    public function acceptPublicChoice(int $requestId, int $suggestionId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pov_suggestions';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = %s, updated_at = %s WHERE request_id = %d AND id != %d AND state IN (%s, %s, %s)",
            SuggestionState::EXPIRED,
            current_time('mysql'),
            $requestId,
            $suggestionId,
            SuggestionState::PENDING,
            SuggestionState::ACCEPTED,
            SuggestionState::SENT
        ));
        $wpdb->update($table, [
            'state' => SuggestionState::ACCEPTED,
            'updated_at' => current_time('mysql'),
        ], [
            'id' => $suggestionId,
            'request_id' => $requestId,
        ]);
    }
}
