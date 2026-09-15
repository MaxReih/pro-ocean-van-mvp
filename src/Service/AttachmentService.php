<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

final class AttachmentService
{
    private const MAX_FILES = 5;
    private const MAX_BYTES = 10485760;
    private array $errors = [];

    public function register(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'guardRestAttachment'], 10, 3);
        add_filter('rest_attachment_query', [$this, 'filterRestAttachments'], 10, 2);
    }

    public function handleUploads(string $field): array
    {
        $this->errors = [];
        if (empty($_FILES[$field]) || ! is_array($_FILES[$field])) {
            return [];
        }

        $files = array_values(array_filter(
            $this->normalizeFiles((array) $_FILES[$field]),
            static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ));
        if (! $files) {
            return [];
        }
        if (count($files) > self::MAX_FILES) {
            $this->errors[] = 'Es können höchstens fünf Dateien auf einmal hochgeladen werden.';
            return [];
        }
        foreach ($files as $file) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $this->errors[] = 'Mindestens eine Datei konnte nicht hochgeladen werden.';
                return [];
            }
            if ((int) ($file['size'] ?? 0) <= 0 || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
                $this->errors[] = 'Jede Datei muss kleiner als 10 MB sein.';
                return [];
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $privateDirectory = $this->privateDirectory();
        if ($privateDirectory === '') {
            $this->errors[] = 'Der geschützte Dateispeicher ist nicht verfügbar.';
            return [];
        }
        $ids = [];
        foreach ($files as $file) {
            $uploaded = wp_handle_upload($file, ['test_form' => false]);
            if (! empty($uploaded['error']) || empty($uploaded['file'])) {
                $this->errors[] = sanitize_text_field((string) ($uploaded['error'] ?? 'Die Datei konnte nicht gespeichert werden.'));
                $this->rollback($ids);
                return [];
            }
            $publicPath = (string) $uploaded['file'];
            $privatePath = $privateDirectory . DIRECTORY_SEPARATOR . wp_unique_filename(
                $privateDirectory,
                sanitize_file_name((string) ($file['name'] ?? basename($publicPath)))
            );
            if (! @rename($publicPath, $privatePath)) {
                if (! @copy($publicPath, $privatePath)) {
                    @unlink($publicPath);
                    $this->errors[] = 'Die Datei konnte nicht in den geschützten Speicher verschoben werden.';
                    $this->rollback($ids);
                    return [];
                }
                @unlink($publicPath);
            }
            $attachmentId = wp_insert_attachment([
                'post_mime_type' => sanitize_mime_type((string) ($uploaded['type'] ?? 'application/octet-stream')),
                'post_title' => sanitize_text_field(pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME)),
                'post_status' => 'private',
            ], $privatePath);
            if (is_wp_error($attachmentId) || (int) $attachmentId <= 0) {
                @unlink($privatePath);
                $this->errors[] = 'Die Datei konnte nicht registriert werden.';
                $this->rollback($ids);
                return [];
            }
            update_attached_file((int) $attachmentId, $privatePath);
            update_post_meta((int) $attachmentId, '_pov_private_attachment', '1');
            $metadata = wp_generate_attachment_metadata((int) $attachmentId, $privatePath);
            if (is_array($metadata)) {
                wp_update_attachment_metadata((int) $attachmentId, $metadata);
            }
            $ids[] = (int) $attachmentId;
        }
        return $ids;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function discard(array $ids): void
    {
        $this->rollback($ids);
    }

    public function protectKnownAttachments(): void
    {
        $ids = [];
        foreach ([
            'pov_confirmation_attachment_ids',
            'pov_proposal_attachment_ids',
            'pov_accept_attachment_ids',
            'pov_question_attachment_ids',
            'pov_reject_attachment_ids',
            'pov_teacher_attachment_ids',
            'pov_parents_attachment_ids',
            'pov_followup_attachment_ids',
        ] as $option) {
            $ids = array_merge($ids, $this->optionIds($option));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pov_communications';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            foreach (($wpdb->get_col("SELECT attachment_ids FROM {$table}") ?: []) as $encoded) {
                $decoded = json_decode((string) $encoded, true);
                if (is_array($decoded)) {
                    $ids = array_merge($ids, array_map('absint', $decoded));
                }
            }
        }
        foreach (array_values(array_unique(array_filter(array_map('absint', $ids)))) as $id) {
            $this->privatePath($id);
        }
    }

    public function guardRestAttachment(mixed $response, mixed $server, mixed $request): mixed
    {
        if (current_user_can('manage_options') || ! is_object($request) || ! method_exists($request, 'get_route')) {
            return $response;
        }
        if (preg_match('#^/wp/v2/media/(\d+)$#', (string) $request->get_route(), $matches)
            && get_post_meta((int) $matches[1], '_pov_private_attachment', true) === '1') {
            return new \WP_Error('pov_private_attachment', 'Datei nicht verfügbar.', ['status' => 404]);
        }
        return $response;
    }

    public function filterRestAttachments(array $args, mixed $request): array
    {
        if (current_user_can('manage_options')) {
            return $args;
        }
        $metaQuery = is_array($args['meta_query'] ?? null) ? $args['meta_query'] : [];
        $metaQuery[] = [
            'key' => '_pov_private_attachment',
            'compare' => 'NOT EXISTS',
        ];
        $args['meta_query'] = $metaQuery;
        return $args;
    }

    public function paths(array $ids): array
    {
        $paths = [];
        foreach (array_values(array_unique(array_map('absint', $ids))) as $id) {
            $path = $this->privatePath($id);
            if (is_string($path) && $path !== '' && is_readable($path)) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    public function rows(array $ids): array
    {
        $rows = [];
        foreach (array_values(array_unique(array_map('absint', $ids))) as $id) {
            $path = $this->privatePath($id);
            if ($path === '' || ! is_readable($path)) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'name' => get_the_title($id) ?: basename($path),
                'url' => wp_nonce_url(
                    admin_url('admin-post.php?action=pov_download_attachment&attachment_id=' . $id),
                    'pov_download_attachment_' . $id
                ),
            ];
        }
        return $rows;
    }

    public function path(int $id): string
    {
        return $this->privatePath(absint($id));
    }

    public function optionIds(string $name): array
    {
        $decoded = json_decode((string) get_option($name, ''), true);
        return is_array($decoded) ? array_values(array_filter(array_map('absint', $decoded))) : [];
    }

    public function updateOption(string $name, array $newIds, array $removeIds = []): array
    {
        $removeIds = array_map('absint', $removeIds);
        $previousIds = $this->optionIds($name);
        $ids = array_values(array_unique(array_filter(array_merge(
            array_diff($previousIds, $removeIds),
            array_map('absint', $newIds)
        ))));
        update_option($name, wp_json_encode($ids));
        $this->deleteUnusedAttachments(array_intersect($previousIds, $removeIds));
        return $ids;
    }

    public function deleteRequestFiles(int $requestId, array $ids): void
    {
        if ($requestId <= 0) {
            return;
        }
        $protected = [];
        foreach ([
            'pov_confirmation_attachment_ids',
            'pov_proposal_attachment_ids',
            'pov_accept_attachment_ids',
            'pov_question_attachment_ids',
            'pov_reject_attachment_ids',
            'pov_teacher_attachment_ids',
            'pov_parents_attachment_ids',
            'pov_followup_attachment_ids',
        ] as $option) {
            $protected = array_merge($protected, $this->optionIds($option));
        }
        global $wpdb;
        $otherRows = $wpdb->get_col($wpdb->prepare(
            "SELECT attachment_ids FROM {$wpdb->prefix}pov_communications
             WHERE request_id != %d AND attachment_ids IS NOT NULL AND attachment_ids != ''",
            $requestId
        )) ?: [];
        $usedElsewhere = [];
        foreach ($otherRows as $row) {
            $decoded = json_decode((string) $row, true);
            if (is_array($decoded)) {
                $usedElsewhere = array_merge($usedElsewhere, array_map('absint', $decoded));
            }
        }
        foreach (array_values(array_unique(array_map('absint', $ids))) as $id) {
            if ($id > 0 && ! in_array($id, $protected, true) && ! in_array($id, $usedElsewhere, true)) {
                wp_delete_attachment($id, true);
            }
        }
    }

    private function normalizeFiles(array $input): array
    {
        if (! is_array($input['name'] ?? null)) {
            return [$input];
        }

        $files = [];
        foreach ((array) $input['name'] as $index => $name) {
            $files[] = [
                'name' => $name,
                'type' => $input['type'][$index] ?? '',
                'tmp_name' => $input['tmp_name'][$index] ?? '',
                'error' => $input['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $input['size'][$index] ?? 0,
            ];
        }
        return $files;
    }

    public static function storageDirectoryPath(): string
    {
        $siteRoot = rtrim(ABSPATH, '/\\');
        $hashSource = wp_normalize_path($siteRoot);
        if (DIRECTORY_SEPARATOR === '\\') {
            $hashSource = strtolower($hashSource);
        }
        $directory = dirname($siteRoot)
            . DIRECTORY_SEPARATOR
            . 'pov-private-attachments-'
            . substr(hash('sha256', $hashSource), 0, 12);
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== false && self::pathIsWithin($directory, $documentRoot)) {
            $directory = dirname($documentRoot)
                . DIRECTORY_SEPARATOR
                . '.pov-private-attachments-'
                . substr(hash('sha256', $hashSource), 0, 12);
        }

        $resolved = realpath($directory);
        if ($documentRoot !== false
            && (($resolved !== false && self::pathIsWithin($resolved, $documentRoot))
                || self::pathIsWithin($directory, $documentRoot))) {
            return '';
        }
        return $directory;
    }

    private function privateDirectory(): string
    {
        $directory = self::storageDirectoryPath();
        if ($directory === '') {
            return '';
        }
        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            return '';
        }
        $resolved = realpath($directory);
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($resolved === false
            || ($documentRoot !== false && self::pathIsWithin($resolved, $documentRoot))) {
            return '';
        }
        return is_writable($resolved) ? $resolved : '';
    }

    private function privatePath(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $this->markPrivate($id);
        $path = get_attached_file($id);
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return '';
        }
        $directory = $this->privateDirectory();
        if ($directory === '') {
            return '';
        }
        $normalizedPath = wp_normalize_path($path);
        $normalizedDirectory = trailingslashit(wp_normalize_path($directory));
        if (str_starts_with($normalizedPath, $normalizedDirectory)) {
            return $path;
        }

        $target = $directory . DIRECTORY_SEPARATOR . wp_unique_filename($directory, basename($path));
        if (! @rename($path, $target)) {
            if (! @copy($path, $target)) {
                return '';
            }
            @unlink($path);
        }
        update_attached_file($id, $target);
        return $target;
    }

    private function markPrivate(int $id): void
    {
        if (get_post_type($id) !== 'attachment') {
            return;
        }
        if (get_post_status($id) !== 'private') {
            wp_update_post(['ID' => $id, 'post_status' => 'private']);
        }
        update_post_meta($id, '_pov_private_attachment', '1');
    }

    private function rollback(array $ids): void
    {
        foreach (array_map('absint', $ids) as $id) {
            if ($id > 0) {
                wp_delete_attachment($id, true);
            }
        }
    }

    private function deleteUnusedAttachments(array $ids): void
    {
        $candidates = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (! $candidates) {
            return;
        }

        $used = [];
        foreach ([
            'pov_confirmation_attachment_ids',
            'pov_proposal_attachment_ids',
            'pov_accept_attachment_ids',
            'pov_question_attachment_ids',
            'pov_reject_attachment_ids',
            'pov_teacher_attachment_ids',
            'pov_parents_attachment_ids',
            'pov_followup_attachment_ids',
        ] as $option) {
            $used = array_merge($used, $this->optionIds($option));
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pov_communications';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            foreach (($wpdb->get_col("SELECT attachment_ids FROM {$table}") ?: []) as $encoded) {
                $decoded = json_decode((string) $encoded, true);
                if (is_array($decoded)) {
                    $used = array_merge($used, array_map('absint', $decoded));
                }
            }
        }
        $used = array_values(array_unique($used));
        foreach ($candidates as $id) {
            if (! in_array($id, $used, true)
                && get_post_meta($id, '_pov_private_attachment', true) === '1') {
                wp_delete_attachment($id, true);
            }
        }
    }

    private static function pathIsWithin(string $path, string $root): bool
    {
        $normalizedPath = rtrim(wp_normalize_path($path), '/');
        $normalizedRoot = rtrim(wp_normalize_path($root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalizedPath = strtolower($normalizedPath);
            $normalizedRoot = strtolower($normalizedRoot);
        }
        return $normalizedPath === $normalizedRoot
            || str_starts_with($normalizedPath . '/', $normalizedRoot . '/');
    }
}
