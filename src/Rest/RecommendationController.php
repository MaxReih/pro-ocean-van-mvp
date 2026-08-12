<?php

declare(strict_types=1);

namespace ProOceanVan\Rest;

use ProOceanVan\Service\PublicRecommendationService;
use ProOceanVan\Security\FrontendAccess;
use WP_Error;
use WP_REST_Request;

final class RecommendationController
{
    private string $namespace = 'pro-ocean-van/v1';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/recommendations', [
            'methods' => 'POST',
            'callback' => [$this, 'recommend'],
            'permission_callback' => [FrontendAccess::class, 'authorizeRest'],
        ]);
    }

    public function recommend(WP_REST_Request $request): array|WP_Error
    {
        if (! $this->allowRequest()) {
            return new WP_Error('pov_rate_limited', 'Bitte warte kurz und versuche es erneut.', ['status' => 429]);
        }

        return (new PublicRecommendationService())->recommend(
            (string) $request->get_param('postal_code'),
            (string) $request->get_param('state_code')
        );
    }

    private function allowRequest(): bool
    {
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $key = 'pov_rec_' . md5($ip);
        if (get_transient($key)) {
            return false;
        }

        set_transient($key, '1', 2);
        return true;
    }
}
