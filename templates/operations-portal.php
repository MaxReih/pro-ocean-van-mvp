<?php

declare(strict_types=1);

use ProOceanVan\Portal\OperationsPortal;

if (! defined('ABSPATH')) {
    exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <?php wp_head(); ?>
</head>
<body <?php body_class('pov-portal-body'); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#pov-portal-content">Zum Inhalt</a>
<main id="pov-portal-content" class="pov-portal-shell">
    <?php (new OperationsPortal())->render(); ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
