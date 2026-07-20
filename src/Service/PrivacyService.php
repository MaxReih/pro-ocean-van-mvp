<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use ProOceanVan\Portal\OperationsPortal;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Security\Capabilities;

final class PrivacyService
{
    public function register(): void
    {
        add_action('admin_post_pov_export_request', [$this, 'exportRequest']);
        add_action('admin_post_pov_anonymize_request', [$this, 'anonymizeRequest']);
    }

    public function exportRequest(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PRIVACY)) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('pov_export_request');
        $id = (int) ($_GET['request_id'] ?? 0);
        $export = (new RequestRepository())->export($id);
        if (! $export) {
            wp_die('Anfrage nicht gefunden.');
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ocean-van-anfrage-' . $id . '.json"');
        echo wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function anonymizeRequest(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PRIVACY)) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('pov_anonymize_request');
        $id = (int) ($_POST['request_id'] ?? 0);
        (new RequestRepository())->anonymize($id);
        wp_safe_redirect(OperationsPortal::url('pov-requests', ['request_id' => $id, 'pov_notice' => 'anonymized']));
        exit;
    }
}
