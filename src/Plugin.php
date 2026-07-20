<?php

declare(strict_types=1);

namespace ProOceanVan;

use ProOceanVan\Admin\Menu;
use ProOceanVan\Frontend\Assets;
use ProOceanVan\Frontend\Shortcode;
use ProOceanVan\Portal\OperationsPortal;
use ProOceanVan\Rest\AdminController;
use ProOceanVan\Rest\PublicCalendarController;
use ProOceanVan\Rest\RecommendationController;
use ProOceanVan\Rest\RequestController;
use ProOceanVan\Service\PrivacyService;
use ProOceanVan\Service\PublicSuggestionResponseService;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain('pro-ocean-van', false, dirname(plugin_basename(POV_PLUGIN_FILE)) . '/languages');

        Activation::maybeUpgrade();
        Activation::applyTestProfileIfUnconfigured();

        (new Assets())->register();
        (new Shortcode())->register();
        (new Menu())->register();
        (new OperationsPortal())->register();
        (new PrivacyService())->register();
        (new PublicSuggestionResponseService())->register();

        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('pov_cleanup_route_cache', [Activation::class, 'cleanupRouteCache']);
    }

    public function registerRestRoutes(): void
    {
        (new PublicCalendarController())->register_routes();
        (new RecommendationController())->register_routes();
        (new RequestController())->register_routes();
        (new AdminController())->register_routes();
    }

}
