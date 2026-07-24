<?php

declare(strict_types=1);

namespace ProOceanVan\Repository;

final class CommunicationRepository
{
    public function forRequest(int $requestId): array
    {
        if ($requestId <= 0) {
            return [];
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pov_communications WHERE request_id = %d ORDER BY created_at DESC, id DESC",
            $requestId
        ), ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string) ($row['attachment_ids'] ?? ''), true);
            $row['attachment_ids'] = is_array($decoded) ? array_values(array_filter(array_map('absint', $decoded))) : [];
        }
        unset($row);
        return $rows;
    }

    public function record(int $requestId, array $data): int
    {
        if ($requestId <= 0) {
            return 0;
        }

        $direction = sanitize_key((string) ($data['direction'] ?? 'outgoing'));
        $direction = in_array($direction, ['incoming', 'outgoing', 'internal', 'system'], true) ? $direction : 'outgoing';
        $attachmentIds = array_values(array_unique(array_filter(array_map('absint', (array) ($data['attachment_ids'] ?? [])))));
        global $wpdb;
        $inserted = $wpdb->insert($wpdb->prefix . 'pov_communications', [
            'request_id' => $requestId,
            'direction' => $direction,
            'communication_type' => sanitize_key((string) ($data['communication_type'] ?? 'message')),
            'subject' => sanitize_text_field((string) ($data['subject'] ?? '')),
            'message' => sanitize_textarea_field((string) ($data['message'] ?? '')),
            'sender_name' => sanitize_text_field((string) ($data['sender_name'] ?? '')),
            'recipient' => sanitize_text_field((string) ($data['recipient'] ?? '')),
            'sender_user_id' => absint($data['sender_user_id'] ?? get_current_user_id()) ?: null,
            'attachment_ids' => wp_json_encode($attachmentIds),
            'delivery_status' => sanitize_key((string) ($data['delivery_status'] ?? 'recorded')),
            'created_at' => current_time('mysql'),
        ]);

        return $inserted ? (int) $wpdb->insert_id : 0;
    }
}
