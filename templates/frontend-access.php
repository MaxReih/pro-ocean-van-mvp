<?php
/** @var bool $hasError */
if (! defined('ABSPATH')) {
    exit;
}
?>
<section class="pov-access-gate" aria-labelledby="pov-access-title">
    <img src="<?php echo esc_url(POV_PLUGIN_URL . 'assets/brand/pro-ocean-logo-blue.svg'); ?>" alt="Pro Ocean">
    <span class="pov-kicker">Ocean Van</span>
    <h1 id="pov-access-title">Buchung öffnen</h1>
    <form method="post">
        <input type="hidden" name="pov_frontend_access_action" value="unlock">
        <?php wp_nonce_field('pov_frontend_access', '_pov_access_nonce'); ?>
        <label for="pov-frontend-password">Passwort</label>
        <div class="pov-access-row">
            <input id="pov-frontend-password" type="password" name="pov_frontend_password" required autocomplete="current-password" autofocus>
            <button class="pov-button" type="submit">Öffnen <span aria-hidden="true">→</span></button>
        </div>
        <?php if ($hasError) : ?>
            <p class="pov-access-error" role="alert">Passwort nicht korrekt.</p>
        <?php endif; ?>
    </form>
</section>
