<?php

declare(strict_types=1);

namespace ProOceanVan\Frontend;

use ProOceanVan\Repository\StateRepository;
use ProOceanVan\Security\FrontendAccess;

final class Shortcode
{
    public function register(): void
    {
        add_shortcode('pro_ocean_van_booking', [$this, 'render']);
    }

    public function render(): string
    {
        wp_enqueue_style('pov-frontend');
        if (! FrontendAccess::isGranted()) {
            FrontendAccess::disableCaching();
            return FrontendAccess::renderGate();
        }

        wp_enqueue_script('pov-frontend');
        $states = (new StateRepository())->all(true);
        ob_start();
        include POV_PLUGIN_DIR . 'templates/frontend-booking.php';
        return (string) ob_get_clean();
    }
}
