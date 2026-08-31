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
            <h1 id="pov-booking-title">Hol das Meer zu dir</h1>
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
                    <span><i class="is-available"></i>Noch verfügbar</span>
                    <span><i class="is-limited"></i>Auf Anfrage</span>
                    <span><i class="is-tour"></i>Van unterwegs</span>
                    <span><i class="is-walk-in"></i>Öffentliches Event</span>
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
                <label>Veranstaltungsart
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
                <label>Funktion
                    <input name="contact_role" required placeholder="z. B. Lehrkraft oder Veranstaltungsleitung">
                </label>
                <label>Website der Institution / Organisation <small>(optional)</small>
                    <input type="url" name="institution_website" inputmode="url" autocomplete="url" placeholder="https://…">
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
            </div>

            <section class="pov-type-section" data-event-section="Schule" hidden>
                <h2>Schule</h2>
                <div class="pov-grid-2">
                    <label>Klassenstufe / Alter
                        <select name="school_grade" data-type-required>
                            <option value="">Bitte auswählen</option>
                            <option value="preschool">Vorschule</option>
                            <?php for ($grade = 1; $grade <= 13; $grade++) : ?>
                                <option value="grade_<?php echo esc_attr((string) $grade); ?>"><?php echo esc_html((string) $grade . '. Klasse'); ?></option>
                            <?php endfor; ?>
                            <option value="vocational">Berufsschule</option>
                            <option value="mixed">Altersgemischt</option>
                        </select>
                    </label>
                    <label>Anzahl Klassen
                        <input type="number" name="school_class_count" min="1" max="50" step="1" inputmode="numeric" value="1" data-type-required>
                    </label>
                    <label>Lehrpersonal je Klasse
                        <input type="number" name="school_teachers_per_class" min="0" max="20" step="1" inputmode="numeric" value="1" data-type-required>
                    </label>
                    <label>Teilnehmende Kinder / Klasse
                        <input type="number" name="school_children_per_class" min="1" max="100" step="1" inputmode="numeric" value="25" data-type-required data-participant-field>
                    </label>
                    <label>Teilnehmende Erwachsene
                        <input type="number" name="school_adult_count" min="0" max="500" step="1" inputmode="numeric" value="1" data-type-required data-participant-field>
                    </label>
                    <label class="pov-span-2">Mehrere Klassen oder besondere Anforderungen
                        <textarea name="school_needs" placeholder="Optional: Klassenaufteilung, Barrierefreiheit oder weitere Needs"></textarea>
                    </label>
                </div>
            </section>

            <section class="pov-type-section" data-event-section="Veranstaltung" hidden>
                <h2>Veranstaltung</h2>
                <div class="pov-grid-2">
                    <label>Teilnehmende Kinder
                        <input type="number" name="event_children_count" min="0" max="500" step="1" inputmode="numeric" value="0" data-type-required data-participant-field>
                    </label>
                    <label>Altersrange der teilnehmenden Kinder
                        <input name="event_child_age_range" placeholder="z. B. 6–12 Jahre">
                    </label>
                    <label>Teilnehmende Erwachsene
                        <input type="number" name="event_adult_count" min="0" max="500" step="1" inputmode="numeric" value="1" data-type-required data-participant-field>
                    </label>
                </div>
            </section>

            <section class="pov-type-section" data-event-section="Sonstiges" hidden>
                <h2>Sonstiges</h2>
                <div class="pov-grid-2">
                    <label class="pov-span-2">Veranstaltungsart oder Anlass
                        <textarea name="occasion_description" data-type-required placeholder="Beschreibt kurz, was geplant ist."></textarea>
                    </label>
                    <label>Teilnehmende Kinder
                        <input type="number" name="other_children_count" min="0" max="500" step="1" inputmode="numeric" value="0" data-type-required data-participant-field>
                    </label>
                    <label>Teilnehmende Erwachsene
                        <input type="number" name="other_adult_count" min="0" max="500" step="1" inputmode="numeric" value="1" data-type-required data-participant-field>
                    </label>
                </div>
            </section>

            <label class="pov-participant-total">Personen gesamt
                <input type="number" name="participant_total" min="1" max="500" step="1" inputmode="numeric" value="0" readonly>
            </label>

            <div class="pov-route-note" data-role="form-route-note" hidden>
                <span data-role="form-route-note-text"></span>
                <button type="button" class="pov-text-button" data-action="recheck-form-route">Route erneut prüfen</button>
            </div>
            <div class="pov-actions">
                <button type="button" class="pov-button" data-next>Weiter <span aria-hidden="true">→</span></button>
            </div>
        </fieldset>

        <fieldset data-form-step="2" hidden>
            <legend tabindex="-1">Details vor Ort</legend>

            <section class="pov-detail-block">
                <h2>Zeit und Zugang</h2>
                <div class="pov-grid-2">
                    <label>Zeitliche Verfügbarkeit
                        <select name="availability_window" required>
                            <option value="">Bitte auswählen</option>
                            <option value="morning">Vormittag</option>
                            <option value="afternoon">Nachmittag</option>
                            <option value="full_day">Ganztägig</option>
                        </select>
                    </label>
                    <label data-event-section="Schule" hidden>Hinweise / Wünsche zur zeitlichen Planung
                        <textarea name="school_schedule_notes" placeholder="z. B. Unterrichtszeiten, Pausen oder besondere Zeitfenster"></textarea>
                    </label>
                </div>
                <p class="pov-planning-note">Für Aufbau und Vorbereitung benötigen wir mindestens 1 Stunde vor Veranstaltungsbeginn Zugang zur Räumlichkeit bzw. Fläche.</p>
            </section>

            <section class="pov-detail-block">
                <h2>Einsatzfläche</h2>
                <label>Einsatzbereich
                    <select name="venue_type" required>
                        <option value="">Bitte auswählen</option>
                        <option value="indoor">Innenraum</option>
                        <option value="outdoor">Außenbereich</option>
                        <option value="both">Innen- und Außenbereich</option>
                    </select>
                </label>
                <div class="pov-grid-2 pov-venue-details">
                    <label data-venue-section="indoor" hidden>Beschreibung Räumlichkeit Innenraum-Veranstaltung
                        <textarea name="indoor_room_description" placeholder="Größe, Zugang, Etage und Besonderheiten"></textarea>
                    </label>
                    <label data-venue-section="outdoor" hidden>Beschreibung Räumlichkeit Außen-Veranstaltung
                        <textarea name="outdoor_area_description" placeholder="Untergrund, Größe, Zufahrt und Besonderheiten"></textarea>
                    </label>
                </div>
                <fieldset class="pov-choice-fieldset pov-question pov-fallback-question" data-venue-section="outdoor" hidden>
                    <legend>Schlechtwetteroption vorhanden?</legend>
                    <div>
                        <label><input type="radio" name="bad_weather_option_available" value="yes"><span>Ja</span></label>
                        <label><input type="radio" name="bad_weather_option_available" value="no"><span>Nein</span></label>
                    </div>
                </fieldset>
            </section>

            <div class="pov-questions">
                <?php
                $questions = [
                    'water_available' => 'Wasser oder Waschbecken',
                    'changing_room_available' => 'Umkleidekabine',
                    'shower_available' => 'Duschmöglichkeit',
                    'natural_water_nearby' => 'Fluss oder See in Laufnähe',
                    'wifi_available' => 'WLAN',
                ];
                foreach ($questions as $name => $label) :
                    ?>
                    <fieldset class="pov-choice-fieldset pov-question">
                        <legend><?php echo esc_html($label); ?></legend>
                        <div>
                            <label><input type="radio" name="<?php echo esc_attr($name); ?>" value="yes"><span>Ja</span></label>
                            <label><input type="radio" name="<?php echo esc_attr($name); ?>" value="no"><span>Nein</span></label>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
                <fieldset class="pov-question pov-power-question" data-venue-section="outdoor" hidden>
                    <legend>Stromanschluss im Außenbereich</legend>
                    <div class="pov-power-fields">
                        <label>Wo befindet sich der Anschluss?
                            <input name="electricity_outdoor_location" maxlength="500" placeholder="z. B. Außensteckdose am Nebengebäude">
                        </label>
                        <label>Benötigte Kabellänge (Meter)
                            <input type="number" min="0" max="5000" step="1" inputmode="numeric" name="electricity_outdoor_distance_m" placeholder="z. B. 15">
                        </label>
                    </div>
                    <small>Beide Angaben sind optional und helfen bei der Vorbereitung des Außeneinsatzes.</small>
                </fieldset>
                <fieldset class="pov-choice-fieldset pov-question">
                    <legend>Strom zum Laden der Technik
                        <span class="pov-info-tip" tabindex="0" aria-label="Hinweis zum Stromanschluss">i<span role="tooltip">Für Laptop, VR-Technik, Wechselakkus und weitere Geräte.</span></span>
                    </legend>
                    <div>
                        <label><input type="radio" name="electricity_charging_available" value="yes"><span>Ja</span></label>
                        <label><input type="radio" name="electricity_charging_available" value="no"><span>Nein</span></label>
                    </div>
                </fieldset>
            </div>

            <section class="pov-detail-block pov-parking-block">
                <h2>Van-Stellplatz
                    <span class="pov-info-tip" tabindex="0" aria-label="Maße und Gewicht des Ocean Vans">i<span role="tooltip">Länge: 6 m, Breite: 2,05 m, Höhe: 2,522 m; je nach Beladung 2,5–3,5 Tonnen.</span></span>
                </h2>
                <div class="pov-grid-2">
                    <label>Art des Stellplatzes
                        <select name="parking_type" required>
                            <option value="">Bitte auswählen</option>
                            <option value="schoolyard">Schulhof</option>
                            <option value="parking_lot">Parkplatz</option>
                            <option value="street">Straßenrand / Ladezone</option>
                            <option value="other">Sonstiger Stellplatz</option>
                        </select>
                    </label>
                    <label>Google-Maps-Link zum Stellplatz
                        <input type="url" name="parking_location" placeholder="https://maps.google.com/…">
                    </label>
                </div>
            </section>

            <section class="pov-detail-block" data-venue-section="indoor" hidden>
                <h2>Technische Ausstattung Räumlichkeit</h2>
                <fieldset class="pov-checklist-fieldset">
                    <legend>Präsentation von Lehrinhalten an der Wand</legend>
                    <div class="pov-checklist">
                        <label><input type="checkbox" name="presentation_equipment[]" value="chalkboard"><span>Kreidetafel</span></label>
                        <label><input type="checkbox" name="presentation_equipment[]" value="projector"><span>Beamer</span></label>
                        <label><input type="checkbox" name="presentation_equipment[]" value="digital_display"><span>Digitale Tafel / Screen</span></label>
                        <label><input type="checkbox" name="presentation_equipment[]" value="other" data-toggle-other="presentation"><span>Sonstiges</span></label>
                    </div>
                    <label data-other-field="presentation" hidden>Sonstige Präsentationstechnik
                        <input name="presentation_equipment_other" placeholder="Bitte kurz beschreiben">
                    </label>
                </fieldset>
                <fieldset class="pov-checklist-fieldset">
                    <legend>Anschlussmöglichkeit Laptop</legend>
                    <div class="pov-checklist">
                        <label><input type="checkbox" name="laptop_connections[]" value="usb_c"><span>USB-C</span></label>
                        <label><input type="checkbox" name="laptop_connections[]" value="usb_a"><span>USB Type A</span></label>
                        <label><input type="checkbox" name="laptop_connections[]" value="other" data-toggle-other="laptop"><span>Sonstige</span></label>
                    </div>
                    <label data-other-field="laptop" hidden>Sonstiger Laptop-Anschluss
                        <input name="laptop_connection_other" placeholder="Bitte kurz beschreiben">
                    </label>
                </fieldset>
            </section>

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
        <span class="pov-kicker">Öffentliches Event</span>
        <h2 id="pov-walk-in-title" data-role="walk-in-title"></h2>
        <p class="pov-walk-in-date" data-role="walk-in-date"></p>
        <p data-role="walk-in-description"></p>
        <p class="pov-walk-in-location" data-role="walk-in-location"></p>
        <a class="pov-button" data-role="walk-in-link" target="_blank" rel="noopener" hidden>Weitere Informationen</a>
    </dialog>
</section>
