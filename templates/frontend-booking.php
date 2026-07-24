<?php
/**
 * @var array $states
 */
if (! defined('ABSPATH')) {
    exit;
}

$privacy = (string) get_option('pov_privacy_page_url', '');
?>
<section class="pov-booking" data-pov-booking data-started-at="<?php echo esc_attr((string) time()); ?>" aria-labelledby="pov-booking-title">
    <header class="pov-hero">
        <a class="pov-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Zur Pro-Ocean-Startseite">
            <img class="pov-logo" src="<?php echo esc_url(POV_PLUGIN_URL . 'assets/brand/pro-ocean-logo-blue.svg'); ?>" alt="Pro Ocean">
        </a>
        <div>
            <span class="pov-kicker">Ocean Van</span>
            <h1 id="pov-booking-title">Hol dir das Meer zu dir</h1>
        </div>
    </header>

    <section class="pov-route-screen" data-screen="route" aria-labelledby="pov-route-heading">
        <div class="pov-panel pov-route-panel">
            <div class="pov-section-heading">
                <h2 id="pov-route-heading" tabindex="-1">Einsatzort</h2>
            </div>

            <div class="pov-region">
                <label for="pov-state-code">Bundesland
                    <select id="pov-state-code" data-field="state_code" required autocomplete="address-level1">
                        <option value="">Bitte auswählen</option>
                        <?php foreach ($states as $state) : ?>
                            <option value="<?php echo esc_attr($state['state_code']); ?>"><?php echo esc_html($state['state_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label for="pov-postal-code">Postleitzahl
                    <input id="pov-postal-code" data-field="postal_code" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" autocomplete="postal-code" placeholder="z. B. 72072">
                </label>
                <button type="button" class="pov-button pov-route-button" data-action="check-route">
                    <span>Termine finden</span><span aria-hidden="true">→</span>
                </button>
            </div>
            <div class="pov-live pov-route-status" role="status" aria-live="polite" data-role="route-status"></div>

            <section class="pov-recommendations" aria-labelledby="pov-recommendation-title">
                <div class="pov-subheading">
                    <div>
                        <strong id="pov-recommendation-title" class="pov-section-title">Empfohlene Termine</strong>
                    </div>
                </div>
                <div class="pov-suggestions" data-role="suggestions" aria-live="polite" aria-busy="false">
                    <div class="pov-empty-state">
                        <strong>Gebt euren Ort ein.</strong>
                        <span>Dann zeigen wir die zwei besten Tage.</span>
                    </div>
                </div>
            </section>

            <div class="pov-route-alternatives" aria-label="Weitere Terminoptionen">
                <button type="button" class="pov-secondary-button" data-action="toggle-range" aria-expanded="false" aria-controls="pov-range-panel">Zeitraum anfragen</button>
            </div>

            <section class="pov-option-panel" id="pov-calendar-panel" data-role="calendar-panel" aria-labelledby="pov-calendar-title">
                <div class="pov-calendar-toolbar">
                    <button type="button" class="pov-icon-button" data-action="prev-month" aria-label="Vorheriger Monat">‹</button>
                    <h3 id="pov-calendar-title" data-role="month-label" tabindex="-1"></h3>
                    <button type="button" class="pov-icon-button" data-action="next-month" aria-label="Nächster Monat">›</button>
                </div>
                <div class="pov-live pov-calendar-status" role="status" aria-live="polite" data-role="calendar-status"></div>
                <div class="pov-calendar" data-role="calendar" role="group" aria-label="Verfügbare Besuchstage" aria-busy="false"></div>
                <div class="pov-legend" aria-label="Kalenderlegende">
                    <span><i class="is-available"></i>Buchbar</span>
                    <span><i class="is-limited"></i>Auf Anfrage</span>
                    <span><i class="is-tour"></i>Van unterwegs</span>
                    <span><i class="is-walk-in"></i>Einfach vorbeikommen</span>
                    <span><i class="is-unavailable"></i>Nicht buchbar</span>
                </div>
            </section>

            <section class="pov-option-panel pov-range-panel" id="pov-range-panel" data-role="range-panel" aria-labelledby="pov-range-title" hidden>
                <h3 id="pov-range-title" tabindex="-1">Euer Wunschzeitraum</h3>
                <div class="pov-grid-2">
                    <label>Frühester Termin
                        <input type="date" data-range-field="from">
                    </label>
                    <label>Spätester Termin
                        <input type="date" data-range-field="to">
                    </label>
                </div>
                <fieldset class="pov-choice-fieldset" data-range-weekday-group>
                    <legend>Mögliche Wochentage</legend>
                    <div class="pov-weekdays">
                        <label><input type="checkbox" data-range-weekday value="mon"><span>Mo</span></label>
                        <label><input type="checkbox" data-range-weekday value="tue"><span>Di</span></label>
                        <label><input type="checkbox" data-range-weekday value="wed"><span>Mi</span></label>
                        <label><input type="checkbox" data-range-weekday value="thu"><span>Do</span></label>
                        <label><input type="checkbox" data-range-weekday value="fri"><span>Fr</span></label>
                    </div>
                </fieldset>
                <div class="pov-live" role="status" aria-live="polite" data-role="range-status"></div>
                <button type="button" class="pov-button" data-action="select-range">Mit Zeitraum weiter <span aria-hidden="true">→</span></button>
            </section>
        </div>
    </section>

    <form class="pov-panel pov-form" data-role="form" hidden novalidate aria-label="Ocean-Van-Anfrage">
        <input type="text" name="website" tabindex="-1" autocomplete="off" class="pov-hp" aria-hidden="true">

        <div class="pov-form-topline">
            <button type="button" class="pov-back-button" data-action="back-route">← Termin ändern</button>
            <span class="pov-region-summary"><strong data-role="selected-date-summary">Noch offen</strong></span>
        </div>

        <nav class="pov-progress" aria-label="Anfragefortschritt">
            <span class="pov-progress-line" aria-hidden="true"><i data-role="progress-fill"></i></span>
            <button type="button" data-goto-step="1" class="is-current" aria-current="step"><span>1</span><small>Kontakt &amp; Gruppe</small></button>
            <button type="button" data-goto-step="2" disabled><span>2</span><small>Vor Ort &amp; senden</small></button>
        </nav>

        <fieldset data-form-step="1">
            <legend tabindex="-1">Kontakt und Einsatzort</legend>

            <div class="pov-grid-2">
                <label>Einrichtung oder Veranstalter
                    <input name="institution_name" required autocomplete="organization" placeholder="z. B. Meerblick-Schule oder Stadtfest">
                </label>
                <label>Anfrageart
                    <select name="institution_type" required>
                        <option value="">Bitte auswählen</option>
                        <option value="Schule">Schule (kostenlos)</option>
                        <option value="Veranstaltung">Veranstaltung (ggf. kostenpflichtig)</option>
                        <option value="Sonstiges">Sonstiges</option>
                    </select>
                </label>
                <label>Vorname
                    <input name="contact_first_name" required autocomplete="given-name">
                </label>
                <label>Nachname
                    <input name="contact_last_name" required autocomplete="family-name">
                </label>
                <label>E-Mail
                    <input type="email" name="contact_email" required autocomplete="email">
                </label>
                <label>Telefon
                    <input type="tel" name="contact_phone" required autocomplete="tel">
                </label>
                <label class="pov-span-2">Straße
                    <input name="street" required autocomplete="address-line1">
                </label>
                <label>Hausnummer
                    <input name="house_number" required autocomplete="address-line2">
                </label>
                <label>Ort
                    <input name="city" required autocomplete="address-level2">
                </label>
                <label>Postleitzahl
                    <input name="postal_code" required inputmode="numeric" pattern="[0-9]{5}" maxlength="5" autocomplete="postal-code">
                </label>
                <label>Bundesland
                    <select name="state_code" required autocomplete="address-level1">
                        <option value="">Bitte auswählen</option>
                        <?php foreach ($states as $state) : ?>
                            <option value="<?php echo esc_attr($state['state_code']); ?>"><?php echo esc_html($state['state_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Kinder und Jugendliche
                    <input type="number" name="children_count" min="0" max="500" step="1" required inputmode="numeric" value="0">
                </label>
                <label>Erwachsene
                    <input type="number" name="adult_count" min="0" max="500" step="1" required inputmode="numeric" value="0">
                </label>
                <label>Personen gesamt
                    <input type="number" name="participant_total" min="1" max="500" step="1" inputmode="numeric" value="0" readonly>
                </label>
                <label>Zielgruppe
                    <select name="target_group" required>
                        <option value="">Bitte auswählen</option>
                        <option>Grundschule</option>
                        <option>Weiterführende Schule</option>
                        <option>Kinder und Jugendliche</option>
                        <option>Familien</option>
                        <option>Erwachsene</option>
                        <option>Gemischte Gruppe</option>
                        <option>Sonstige Zielgruppe</option>
                    </select>
                </label>
            </div>

            <div class="pov-route-note" data-role="form-route-note" hidden>
                <span data-role="form-route-note-text"></span>
                <button type="button" class="pov-text-button" data-action="recheck-form-route">Route erneut prüfen</button>
            </div>
            <div class="pov-actions">
                <button type="button" class="pov-button" data-next>Weiter <span aria-hidden="true">→</span></button>
            </div>
        </fieldset>

        <fieldset data-form-step="2" hidden>
            <legend tabindex="-1">Ausstattung vor Ort</legend>
            <p class="pov-fieldset-intro">„Unklar“ ist völlig in Ordnung.</p>

            <div class="pov-questions">
                <?php
                $questions = [
                    'parking_available' => 'Parkplatz für den Van',
                    'electricity_available' => 'Stromanschluss in der Nähe',
                    'water_available' => 'Wasser oder Waschbecken',
                    'weather_option' => 'Innenraum oder Schlechtwetteroption',
                ];
                foreach ($questions as $name => $label) :
                    ?>
                    <fieldset class="pov-choice-fieldset pov-question">
                        <legend><?php echo esc_html($label); ?></legend>
                        <div>
                            <label><input type="radio" name="<?php echo esc_attr($name); ?>" value="yes" required><span>Ja</span></label>
                            <label><input type="radio" name="<?php echo esc_attr($name); ?>" value="no"><span>Nein</span></label>
                            <label><input type="radio" name="<?php echo esc_attr($name); ?>" value="unknown"><span>Unklar</span></label>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
            </div>

            <label>Hinweise zur Anfahrt
                <textarea name="general_notes" placeholder="Optional: Zufahrt, Barrierefreiheit oder Besonderheiten"></textarea>
            </label>

            <label class="pov-consent">
                <input type="checkbox" name="privacy_consent" value="1" required>
                <span>Ich stimme der Verarbeitung meiner Angaben zu<?php if ($privacy) : ?> und habe die <a href="<?php echo esc_url($privacy); ?>" target="_blank" rel="noopener">Datenschutzerklärung<span class="screen-reader-text"> (öffnet in einem neuen Tab)</span></a> gelesen<?php endif; ?>.</span>
            </label>

            <section class="pov-summary" data-role="summary" aria-labelledby="pov-summary-title">
                <h2 id="pov-summary-title">Anfrage prüfen</h2>
                <div data-role="summary-content"></div>
            </section>
            <div class="pov-live" role="status" aria-live="polite" data-role="submit-status"></div>
            <div class="pov-actions">
                <button type="button" class="pov-secondary-button" data-prev>Zurück</button>
                <button type="submit" class="pov-button pov-submit-button">Unverbindlich anfragen <span aria-hidden="true">→</span></button>
            </div>
        </fieldset>
    </form>

    <section class="pov-panel pov-success" data-role="success" role="status" aria-live="polite" tabindex="-1" hidden>
        <span class="pov-success-icon" aria-hidden="true">✓</span>
        <span class="pov-kicker">Anfrage gesendet</span>
        <h2>Anfrage gesendet</h2>
        <p>Wir melden uns per E-Mail.</p>
        <div class="pov-reference"><span>Anfragekennung</span><strong data-role="public-uuid"></strong></div>
    </section>

    <dialog class="pov-walk-in-dialog" data-role="walk-in-dialog" aria-labelledby="pov-walk-in-title">
        <button type="button" class="pov-dialog-close" data-action="close-walk-in" aria-label="Schließen">×</button>
        <span class="pov-kicker">Einfach vorbeikommen</span>
        <h2 id="pov-walk-in-title" data-role="walk-in-title"></h2>
        <p class="pov-walk-in-date" data-role="walk-in-date"></p>
        <p data-role="walk-in-description"></p>
        <p class="pov-walk-in-location" data-role="walk-in-location"></p>
        <a class="pov-button" data-role="walk-in-link" target="_blank" rel="noopener" hidden>Weitere Informationen</a>
    </dialog>
</section>
