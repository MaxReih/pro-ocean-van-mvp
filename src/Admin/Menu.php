<?php

declare(strict_types=1);

namespace ProOceanVan\Admin;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use ProOceanVan\Activation;
use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\SuggestionState;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Portal\OperationsPortal;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\StateRepository;
use ProOceanVan\Repository\SuggestionRepository;
use ProOceanVan\Routing\ProviderFactory;
use ProOceanVan\Security\Capabilities;
use ProOceanVan\Service\IcsService;
use ProOceanVan\Service\GermanDateFormatter;
use ProOceanVan\Service\InternalSuggestionService;
use ProOceanVan\Service\MailService;
use ProOceanVan\Service\RequestRoutingService;
use ProOceanVan\Service\StatisticsService;
use ProOceanVan\Service\WeeklyClusterService;

final class Menu
{
    public function __construct(private readonly bool $portal = false)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menus']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_page_access_denied', [$this, 'redirectLegacyAdmin']);
        add_action('admin_post_pov_save_settings', [$this, 'saveSettings']);
        add_action('admin_post_pov_enable_test_profile', [$this, 'enableTestProfile']);
        add_action('admin_post_pov_test_geo', [$this, 'testGeoConnection']);
        add_action('admin_post_pov_save_states', [$this, 'saveStates']);
        add_action('admin_post_pov_save_calendar_day', [$this, 'saveCalendarDay']);
        add_action('admin_post_pov_recalculate_suggestions', [$this, 'recalculateSuggestions']);
        add_action('admin_post_pov_suggestion_state', [$this, 'suggestionState']);
        add_action('admin_post_pov_send_proposals', [$this, 'sendProposals']);
        add_action('admin_post_pov_confirm_request', [$this, 'confirmRequest']);
        add_action('admin_post_pov_send_response', [$this, 'sendResponse']);
        add_action('admin_post_pov_save_note', [$this, 'saveNote']);
        add_action('admin_post_pov_save_request_address', [$this, 'saveRequestAddress']);
        add_action('admin_post_pov_retry_missing_addresses', [$this, 'retryMissingAddresses']);
        add_action('admin_post_pov_update_workflow', [$this, 'updateWorkflow']);
        add_action('admin_post_pov_download_ics', [$this, 'downloadIcs']);
    }

    public function menus(): void
    {
        add_menu_page('Ocean Van Einstellungen', 'Ocean Van', 'manage_options', 'pov-settings', [$this, 'settings'], 'dashicons-location-alt', 30);
    }

    public function assets(string $hook): void
    {
        if (str_contains($hook, 'pov-')) {
            wp_enqueue_style('pov-admin', POV_PLUGIN_URL . 'assets/dist/admin.css', [], POV_VERSION);
            wp_enqueue_script('pov-admin', POV_PLUGIN_URL . 'assets/dist/admin.js', [], POV_VERSION, true);
        }
    }

    public function redirectLegacyAdmin(): void
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if ($page === 'pov-settings' && current_user_can(Capabilities::ACCESS_PORTAL) && ! current_user_can('manage_options')) {
            wp_safe_redirect(OperationsPortal::landingUrl());
            exit;
        }
        if (in_array($page, ['pov-requests', 'pov-routes', 'pov-statistics', 'pov-calendar'], true)
            && current_user_can(Capabilities::viewForPage($page))) {
            wp_safe_redirect(OperationsPortal::url($page));
            exit;
        }
    }

    public function requests(): void
    {
        $this->requireCapability(Capabilities::VIEW_REQUESTS);
        $detailId = (int) ($_GET['request_id'] ?? 0);
        if ($detailId > 0) {
            $this->requestDetail($detailId);
            return;
        }

        $filters = [
            'main_status' => sanitize_text_field((string) ($_GET['main_status'] ?? '')),
            'work_state' => sanitize_text_field((string) ($_GET['work_state'] ?? '')),
            'state_code' => sanitize_text_field((string) ($_GET['state_code'] ?? '')),
            'request_mode' => sanitize_text_field((string) ($_GET['request_mode'] ?? '')),
            's' => sanitize_text_field((string) ($_GET['s'] ?? '')),
        ];
        $items = (new RequestRepository())->list(array_filter($filters), 100);
        $this->header('Anfragen');
        $this->setupNotice();
        echo '<div class="pov-admin-list-head"><strong>' . esc_html((string) count($items)) . ' Anfragen</strong><a class="button" href="' . esc_url($this->pageUrl('pov-routes')) . '">Tourplanung</a></div>';
        echo '<form class="pov-admin-filters" method="get" action="' . esc_url($this->pageUrl('pov-requests')) . '">' . $this->navigationField('pov-requests');
        echo '<label><span>Suche</span><input name="s" value="' . esc_attr($filters['s']) . '" placeholder="Name, Ort, PLZ oder Kennung"></label>';
        echo '<label><span>Arbeitsstand</span><select name="work_state"><option value="">Alle</option>' . $this->options(WorkState::labels(), $filters['work_state']) . '</select></label>';
        echo '<label><span>Terminwunsch</span><select name="request_mode"><option value="">Alle</option>' . $this->options(['specific_date' => 'Fester Tag', 'date_range' => 'Zeitraum'], $filters['request_mode']) . '</select></label>';
        echo '<button class="button button-primary">Filtern</button><a class="button" href="' . esc_url($this->pageUrl('pov-requests')) . '">Alle</a></form>';
        echo '<section class="pov-admin-panel pov-request-list-panel">';
        $this->requestTable($items);
        echo '</section>';
        $this->footer();
    }

    private function requestDetail(int $id): void
    {
        $repo = new RequestRepository();
        $request = $repo->find($id);
        if (! $request) {
            wp_die('Anfrage nicht gefunden.');
        }
        $suggestions = (new SuggestionRepository())->forRequest($id);
        $this->renderRequestWorkspace($request, $suggestions);
    }

    private function renderRequestWorkspace(array $request, array $suggestions): void
    {
        $id = (int) $request['id'];
        $workLabel = WorkState::labels()[$request['work_state']] ?? $request['work_state'];
        $distance = is_numeric($request['route_distance_km'] ?? null) ? number_format((float) $request['route_distance_km'], 0, ',', '.') . ' km' : 'Noch offen';
        $cost = is_numeric($request['route_cost'] ?? null) ? number_format((float) $request['route_cost'], 2, ',', '.') . ' €' : 'Noch offen';
        $bestSuggestions = array_slice($suggestions, 0, 2);

        $this->header('Anfrage ' . $request['public_uuid']);
        $this->setupNotice();
        echo '<a class="pov-admin-back" href="' . esc_url($this->pageUrl('pov-requests')) . '">← Zurück zu Anfragen</a>';
        echo '<section class="pov-request-hero"><div><span class="pov-admin-eyebrow">' . esc_html($request['public_uuid']) . '</span><h2>' . esc_html($request['institution_name']) . '</h2><p>' . esc_html($request['postal_code'] . ' ' . $request['city']) . ' · ' . esc_html($this->requestDateLabel($request)) . '</p></div>';
        echo '<div class="pov-request-hero-meta"><div><span>Status</span><strong>' . esc_html($workLabel) . '</strong></div><div><span>Gruppe</span><strong>' . esc_html((string) $request['participant_count']) . '</strong></div></div></section>';

        echo '<div class="pov-admin-workspace"><main>';
        echo '<section class="pov-admin-panel pov-route-decision"><div class="pov-panel-heading"><div><span class="pov-admin-eyebrow">Route</span><h2>Die besten Termine</h2></div>';
        if (current_user_can(Capabilities::MANAGE_TOURS)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('pov_recalculate_suggestions');
            echo '<input type="hidden" name="action" value="pov_recalculate_suggestions"><input type="hidden" name="request_id" value="' . esc_attr((string) $id) . '"><button class="button">Neu berechnen</button></form>';
        }
        echo '</div>';
        echo '<div class="pov-route-summary"><div><span>Hin &amp; zurück</span><strong>' . esc_html($distance) . '</strong></div><div><span>Fahrtkosten</span><strong>' . esc_html($cost) . '</strong></div><div><span>Ziel</span><strong>' . esc_html($request['postal_code'] . ' ' . $request['city']) . '</strong></div></div>';
        $this->renderSuggestionCards($bestSuggestions, false);
        echo '</section>';

        if (current_user_can(Capabilities::SEND_RESPONSES)) {
            echo '<section class="pov-admin-panel pov-response-panel"><div class="pov-panel-heading"><div><span class="pov-admin-eyebrow">Kommunikation</span><h2>Antwort senden</h2></div></div>' . $this->responseForm($request, $bestSuggestions) . '</section>';
        }
        echo '</main><aside>';

        echo $this->addressPanel($request);

        echo '<section class="pov-admin-panel"><span class="pov-admin-eyebrow">Vor Ort</span><h2>' . esc_html((string) $request['institution_type']) . '</h2><dl class="pov-request-facts"><div><dt>Teilnehmende</dt><dd>' . esc_html((string) $request['participant_count']) . '</dd></div><div><dt>Zielgruppe</dt><dd>' . esc_html((string) (($request['group_notes'] ?? '') ?: '–')) . '</dd></div></dl>' . $this->warnings($request) . '</section>';

        if (current_user_can(Capabilities::MANAGE_REQUESTS)) {
            echo '<section class="pov-admin-panel"><span class="pov-admin-eyebrow">Nur fürs Team</span><h2>Interne Notiz</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form">';
            wp_nonce_field('pov_save_note');
            echo '<input type="hidden" name="action" value="pov_save_note"><input type="hidden" name="request_id" value="' . esc_attr((string) $id) . '"><textarea name="internal_note" placeholder="Absprachen, Rückfragen, Besonderheiten …">' . esc_textarea((string) ($request['internal_note'] ?? '')) . '</textarea><button class="button">Notiz speichern</button></form></section>';
        }
        if (current_user_can(Capabilities::MANAGE_PRIVACY)) {
            echo '<details class="pov-admin-panel pov-privacy-panel"><summary>Daten verwalten</summary>' . $this->privacyActions($id) . '</details>';
        }
        echo '</aside></div>';
        $this->footer();
    }

    public function calendar(): void
    {
        $this->requireCapability(Capabilities::VIEW_CALENDAR);
        $month = sanitize_text_field((string) ($_GET['pov_month'] ?? date('Y-m')));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $monthStart = new DateTimeImmutable($month . '-01');
        $monthEnd = $monthStart->modify('last day of this month');
        $events = $this->adminCalendarEvents($monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));
        $appointments = array_values((new AppointmentRepository())->forRange(
            $monthStart->format('Y-m-d'),
            $monthEnd->format('Y-m-d')
        ));

        $this->header('Kalender');
        $this->setupNotice();
        echo '<div class="pov-calendar-layout">';
        echo '<section class="pov-admin-panel"><div class="pov-admin-calendar-head">';
        echo '<a class="button" href="' . esc_url($this->pageUrl('pov-calendar', ['pov_month' => $monthStart->modify('-1 month')->format('Y-m')])) . '">‹</a>';
        echo '<h2>' . esc_html(GermanDateFormatter::monthYear($monthStart->format('Y-m-d'))) . '</h2>';
        echo '<a class="button" href="' . esc_url($this->pageUrl('pov-calendar', ['pov_month' => $monthStart->modify('+1 month')->format('Y-m')])) . '">›</a>';
        echo '</div>';
        echo $this->adminCalendarGrid($monthStart, $events);
        echo '<div class="pov-admin-calendar-legend"><span>Buchbar</span><span>Auf Anfrage</span><span>Nicht buchbar</span><span>Bestätigter Termin</span><span>Offene Anfrage</span></div>';
        echo '</section>';

        if (current_user_can(Capabilities::EXPORT_CALENDAR) || current_user_can(Capabilities::MANAGE_CALENDAR)) {
            echo '<section class="pov-admin-panel pov-calendar-sidebar">';
            if (current_user_can(Capabilities::EXPORT_CALENDAR)) {
                echo $this->calendarExportPanel($appointments);
            }
            if (current_user_can(Capabilities::MANAGE_CALENDAR)) {
                if (current_user_can(Capabilities::EXPORT_CALENDAR)) {
                    echo '<div class="pov-calendar-sidebar-divider" aria-hidden="true"></div>';
                }
                echo '<h2>Zeitraum pflegen</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form" data-pov-calendar-form>';
                wp_nonce_field('pov_save_calendar_day');
                echo '<input type="hidden" name="action" value="pov_save_calendar_day">';
                echo '<div class="pov-admin-two"><label>Datum von <input type="date" name="calendar_date_from" data-date-start required></label>';
                echo '<label>Datum bis <input type="date" name="calendar_date_to" data-date-end></label></div>';
                echo '<label>Status <select name="availability_state"><option value="available">Buchbar</option><option value="limited">Auf Anfrage</option><option value="unavailable">Nicht buchbar</option></select></label>';
                echo '<label>Öffentliche Notiz <input name="public_note"></label>';
                echo '<label>Interne Notiz <textarea name="internal_note"></textarea></label>';
                echo '<details class="pov-calendar-start"><summary>Startpunkt ändern</summary>';
                echo '<div class="pov-admin-two"><label>Startpunkt PLZ <input name="custom_start_postal_code" inputmode="numeric" pattern="[0-9]{5}" maxlength="5"></label>';
                echo '<label>Startpunkt Ort <input name="custom_start_city"></label></div>';
                echo '<label>Name <input name="custom_start_label" placeholder="z. B. Lager Tübingen"></label>';
                echo '<div class="pov-admin-two"><label>Startpunkt Latitude <input name="custom_start_latitude"></label>';
                echo '<label>Startpunkt Longitude <input name="custom_start_longitude"></label></div></details>';
                echo '<p><button class="button button-primary">Speichern</button></p></form>';
            }
            echo '</section>';
        }
        echo '</div>';
        $this->footer();
    }

    public function routes(): void
    {
        $this->requireCapability(Capabilities::VIEW_TOURS);
        $clusters = (new WeeklyClusterService())->clusters();
        $this->renderRouteTimeline($clusters);
    }

    public function statistics(): void
    {
        $this->requireCapability(Capabilities::VIEW_STATISTICS);
        $period = sanitize_key((string) ($_GET['period'] ?? 'week'));
        $report = (new StatisticsService())->report($period);
        $rows = (array) $report['rows'];
        $totals = (array) $report['totals'];
        $maxCost = $rows ? max(1.0, ...array_map(static fn (array $row): float => (float) $row['total_cost'], $rows)) : 1.0;

        $this->header('Statistik');
        $this->setupNotice();
        echo '<nav class="pov-period-switch" aria-label="Zeitraum">';
        foreach (['week' => 'KW', 'month' => 'Monat', 'year' => 'Jahr'] as $value => $label) {
            echo '<a class="button' . ($report['period'] === $value ? ' button-primary' : '') . '" href="' . esc_url($this->pageUrl('pov-statistics', ['period' => $value])) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        echo '<div class="pov-route-overview pov-stat-overview"><div><span>Fahrstrecke</span><strong>' . esc_html(number_format((float) $totals['distance_km'], 0, ',', '.') . ' km') . '</strong></div><div><span>Fahrtkosten</span><strong>' . esc_html(number_format((float) $totals['route_cost'], 2, ',', '.') . ' €') . '</strong></div><div><span>Personalkosten</span><strong>' . esc_html(number_format((float) $totals['personnel_cost'], 2, ',', '.') . ' €') . '</strong></div><div><span>Gesamtkosten</span><strong>' . esc_html(number_format((float) $totals['total_cost'], 2, ',', '.') . ' €') . '</strong></div></div>';

        echo '<section class="pov-admin-panel pov-stat-panel">';
        if (! $rows) {
            echo '<div class="pov-admin-empty"><strong>Noch keine bestätigten Einsätze.</strong></div>';
        } else {
            echo '<div class="pov-stat-chart" aria-label="Kostenverlauf">';
            foreach ($rows as $row) {
                $width = max(2.0, ((float) $row['total_cost'] / $maxCost) * 100);
                echo '<div class="pov-stat-bar"><span>' . esc_html((string) $row['label']) . '</span><i style="--pov-bar:' . esc_attr(number_format($width, 2, '.', '')) . '%"></i><strong>' . esc_html(number_format((float) $row['total_cost'], 0, ',', '.') . ' €') . '</strong></div>';
            }
            echo '</div><div class="pov-table-scroll"><table class="pov-compact-table"><thead><tr><th>Zeitraum</th><th>Einsätze</th><th>Strecke</th><th>Fahrzeit</th><th>Fahrt</th><th>Personal</th><th>Gesamt</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><td><strong>' . esc_html((string) $row['label']) . '</strong></td><td>' . esc_html((string) $row['appointments']) . '</td><td>' . esc_html(number_format((float) $row['distance_km'], 0, ',', '.') . ' km') . '</td><td>' . esc_html($this->durationLabel((float) $row['travel_minutes'])) . '</td><td>' . esc_html(number_format((float) $row['route_cost'], 2, ',', '.') . ' €') . '</td><td>' . esc_html(number_format((float) $row['personnel_cost'], 2, ',', '.') . ' €') . '</td><td><strong>' . esc_html(number_format((float) $row['total_cost'], 2, ',', '.') . ' €') . '</strong></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<p class="description">Bestätigte Einsätze · Personal inklusive Einsatz- und Fahrzeit.</p></section>';
        $this->footer();
    }

    private function renderRouteTimeline(array $clusters): void
    {
        $issues = array_values(array_filter($clusters, static fn (array $cluster): bool => (int) ($cluster['missing_coordinates'] ?? 0) > 0));
        $clusters = array_values(array_filter($clusters, static fn (array $cluster): bool => (int) ($cluster['routable_count'] ?? 0) > 0));
        $totalRequests = array_sum(array_map(static fn (array $cluster): int => (int) $cluster['request_count'], $clusters));
        $totalDistance = array_sum(array_map(static fn (array $cluster): float => (float) $cluster['route_distance_km'], $clusters));
        $this->header('Tourplanung');
        $this->setupNotice();
        echo '<div class="pov-route-overview"><div><span>Tourwochen</span><strong>' . esc_html((string) count($clusters)) . '</strong></div><div><span>Offene Stopps</span><strong>' . esc_html((string) $totalRequests) . '</strong></div><div><span>Geplante Strecke</span><strong>' . esc_html(number_format($totalDistance, 0, ',', '.') . ' km') . '</strong></div></div>';

        if (! $clusters) {
            echo '<section class="pov-admin-panel"><div class="pov-admin-empty"><strong>Keine planbaren Touren.</strong><span>Neue Anfragen erscheinen automatisch nach erfolgreicher Geoanalyse.</span></div></section>';
            $this->routeIssues($issues);
            $this->footer();
            return;
        }

        echo '<div class="pov-route-board">';
        foreach ($clusters as $cluster) {
            $stops = array_values(array_filter((array) ($cluster['stops'] ?? []), static fn (array $stop): bool => is_numeric($stop['latitude'] ?? null) && is_numeric($stop['longitude'] ?? null)));
            $legs = (array) ($cluster['legs'] ?? []);
            $overnights = [];
            foreach ((array) ($cluster['overnights'] ?? []) as $overnight) {
                $overnights[(int) $overnight['after_stop']][] = $overnight;
            }
            $stopSummary = (int) $cluster['request_count'] . ' offen';
            if ((int) ($cluster['confirmed_count'] ?? 0) > 0) {
                $stopSummary .= ' · ' . (int) $cluster['confirmed_count'] . ' bestätigt';
            }
            $source = ['heigit' => 'HeiGIT-Straßenmatrix', 'osrm' => 'Straßenmatrix', 'estimated' => 'Geschätzte Fahrzeit'][(string) $cluster['matrix_source']] ?? 'Routendaten';
            echo '<article class="pov-route-cluster' . ($cluster['priority'] === 'recommended' ? ' is-recommended' : '') . '"><header><div><span class="pov-admin-eyebrow">' . esc_html(GermanDateFormatter::short((string) $cluster['week_start']) . ' – ' . GermanDateFormatter::short((string) $cluster['week_end'])) . '</span><h2>' . esc_html((string) $cluster['title']) . '</h2><p>' . esc_html($stopSummary . ' · Start ' . (string) $cluster['start_label']) . '</p></div><span class="pov-route-badge">' . ($cluster['priority'] === 'recommended' ? 'Empfohlen' : 'Entwurf') . '</span></header>';
            echo '<div class="pov-route-metrics"><div><span>Tourstrecke</span><strong>' . esc_html(number_format((float) $cluster['route_distance_km'], 0, ',', '.') . ' km') . '</strong><small>' . esc_html($source) . '</small></div><div><span>Fahrzeit</span><strong>' . esc_html($this->durationLabel((float) $cluster['route_duration_minutes'])) . '</strong><small>inklusive Rückfahrt</small></div><div><span>Fahrtkosten</span><strong>' . esc_html(number_format((float) $cluster['estimated_cost'], 2, ',', '.') . ' €') . '</strong></div></div>';
            echo '<div class="pov-tour-timeline"><div class="pov-tour-depot"><span>S</span><div><small>Start</small><strong>' . esc_html((string) $cluster['start_label']) . '</strong></div></div>';
            foreach ($stops as $index => $stop) {
                $leg = (array) ($legs[$index] ?? []);
                echo '<div class="pov-tour-leg"><span>Fahrt</span><strong>' . esc_html(number_format((float) ($leg['distance_km'] ?? 0), 0, ',', '.') . ' km · ' . $this->durationLabel((float) ($leg['duration_minutes'] ?? 0))) . '</strong></div>';
                $confirmed = ($stop['_stop_type'] ?? '') === 'confirmed';
                $date = (string) ($stop['_planning_date'] ?? $stop['appointment_date'] ?? '');
                $requestId = $confirmed ? (int) ($stop['request_id'] ?? 0) : (int) ($stop['id'] ?? 0);
                $dateType = $confirmed ? 'Bestätigt' : (! empty($stop['_optimized_date']) ? 'Routenempfehlung' : 'Anfrage');
                echo '<div class="pov-tour-stop' . ($confirmed ? ' is-confirmed' : '') . '"><time datetime="' . esc_attr($date) . '"><strong>' . esc_html(GermanDateFormatter::weekdayShort($date)) . '</strong><span>' . esc_html(mysql2date('d.m.', $date)) . '</span></time><div><small>' . esc_html($dateType) . '</small><strong>' . esc_html((string) $stop['institution_name']) . '</strong><span>' . esc_html((string) $stop['postal_code'] . ' ' . (string) $stop['city']) . '</span></div>';
                echo $requestId > 0 && current_user_can(Capabilities::VIEW_REQUESTS) ? '<a class="button" href="' . esc_url($this->pageUrl('pov-requests', ['request_id' => $requestId])) . '">Öffnen</a></div>' : '</div>';
                foreach ((array) ($overnights[$index] ?? []) as $overnight) {
                    echo '<div class="pov-tour-overnight"><span aria-hidden="true">Zzz</span><div><small>Übernachtungsregion</small><strong>' . esc_html((string) $overnight['place']) . '</strong><em>' . esc_html((string) $overnight['reason']) . '</em></div></div>';
                }
            }
            $returnLeg = (array) ($legs[count($stops)] ?? []);
            echo '<div class="pov-tour-leg"><span>Rückfahrt</span><strong>' . esc_html(number_format((float) ($returnLeg['distance_km'] ?? 0), 0, ',', '.') . ' km · ' . $this->durationLabel((float) ($returnLeg['duration_minutes'] ?? 0))) . '</strong></div><div class="pov-tour-depot is-end"><span>S</span><div><small>Ziel</small><strong>' . esc_html((string) $cluster['start_label']) . '</strong></div></div></div>';
            if ((int) $cluster['missing_coordinates'] > 0) {
                echo '<p class="pov-route-warning">' . esc_html((string) $cluster['missing_coordinates']) . ' Adresse' . ((int) $cluster['missing_coordinates'] === 1 ? '' : 'n') . ' ohne Koordinaten – nicht in der Route.</p>';
            }
            echo '</article>';
        }
        echo '</div>';
        $this->routeIssues($issues);
        $this->footer();
    }

    private function routeIssues(array $clusters): void
    {
        if (! $clusters || ! current_user_can(Capabilities::MANAGE_TOURS)) {
            return;
        }
        echo '<section class="pov-admin-panel pov-route-issues"><div><span class="pov-admin-eyebrow">Nicht eingeplant</span><h2>Adresse korrigieren</h2><p>Zuerst vorhandene Angaben erneut prüfen. Bleibt der Eintrag offen, Adresse korrigieren.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pov_retry_missing_addresses');
        echo '<input type="hidden" name="action" value="pov_retry_missing_addresses"><button class="button">Alle erneut prüfen</button></form></div><ul>';
        foreach ($clusters as $cluster) {
            foreach ((array) ($cluster['stops'] ?? []) as $stop) {
                if (is_numeric($stop['latitude'] ?? null) && is_numeric($stop['longitude'] ?? null)) {
                    continue;
                }
                $requestId = ($stop['_stop_type'] ?? '') === 'confirmed' ? (int) ($stop['request_id'] ?? 0) : (int) ($stop['id'] ?? 0);
                echo '<li><span><strong>' . esc_html((string) $stop['institution_name']) . '</strong><small>' . esc_html(trim((string) ($stop['street'] ?? '') . ' ' . (string) ($stop['house_number'] ?? ''))) . '<br>' . esc_html((string) $stop['postal_code'] . ' ' . (string) $stop['city']) . '</small></span>';
                if ($requestId > 0) {
                    $editUrl = $this->pageUrl('pov-requests', ['request_id' => $requestId, 'edit_address' => '1']) . '#pov-address-editor';
                    echo '<a class="button" href="' . esc_url($editUrl) . '">Adresse korrigieren</a></li>';
                } else {
                    echo '<small>Kein Vorgang verknüpft</small></li>';
                }
            }
        }
        echo '</ul></section>';
    }

    public function settings(): void
    {
        $this->requireCapability('manage_options');
        $states = (new StateRepository())->all(false);
        $this->header('Einstellungen');
        $this->setupNotice();
        echo '<section class="pov-admin-panel pov-portal-access"><div><span class="pov-admin-eyebrow">Teamzugang</span><h2>Van Operations</h2><p>Operative Planung läuft im geschützten Portal. Weise Benutzerinnen und Benutzern die Rolle „Ocean Van Team“ oder „Ocean Van Lesend“ zu.</p></div><div class="pov-admin-welcome-actions"><a class="button button-primary" href="' . esc_url(OperationsPortal::url()) . '">Portal öffnen</a><a class="button" href="' . esc_url(admin_url('users.php')) . '">Benutzer verwalten</a></div></section>';
        echo $this->geoTestPanel();
        echo $this->testProfilePanel();
        echo '<section class="pov-admin-panel"><h2>Konfiguration</h2>';
        if ((float) get_option('pov_kilometer_rate', 0) <= 0 || ((float) get_option('pov_max_public_suggestion_cost', 0) <= 0 && (float) get_option('pov_max_public_suggestion_distance_km', 0) <= 0)) {
            echo '<div class="notice notice-warning inline"><p>Kilometersatz und mindestens ein Grenzwert für Kosten oder Distanz fehlen. Öffentlich wird Zeitraum-Anfrage angeboten.</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form" data-pov-settings-form>';
        wp_nonce_field('pov_save_settings');
        echo '<input type="hidden" name="action" value="pov_save_settings">';
        $labels = $this->settingsFields();
        foreach ($this->settingsGroups() as $group => $names) {
            echo '<details class="pov-settings-group"' . ($group === 'Planung & Kosten' ? ' open' : '') . '><summary>' . esc_html($group) . '</summary><div>';
            if ($group === 'Routing') {
                echo '<label>Straßenrouting <select name="pov_routing_provider" data-pov-provider-select="routing">' . $this->options(['null' => 'Nicht konfiguriert', 'heigit' => 'HeiGIT / openrouteservice', 'osrm' => 'Eigener OSRM-Dienst'], (string) get_option('pov_routing_provider')) . '</select></label>';
                echo '<label>Adressprüfung <select name="pov_geocoding_provider" data-pov-provider-select="geocoding">' . $this->options(['null' => 'Nicht konfiguriert', 'heigit' => 'HeiGIT / Pelias', 'nominatim' => 'Eigener Nominatim-Dienst'], (string) get_option('pov_geocoding_provider')) . '</select></label>';
            }
            foreach ($names as $name) {
                $value = (string) get_option($name, '');
                $textarea = str_ends_with($name, '_body') || str_ends_with($name, '_template');
                $providerField = match ($name) {
                    'pov_heigit_api_key' => 'heigit',
                    'pov_routing_base_url' => 'routing:osrm',
                    'pov_geocoding_base_url' => 'geocoding:nominatim',
                    default => '',
                };
                echo '<label' . ($providerField !== '' ? ' data-pov-provider-field="' . esc_attr($providerField) . '"' : '') . '>' . esc_html($labels[$name] ?? $name);
                if ($textarea) {
                    echo '<textarea name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea>';
                } elseif ($name === 'pov_heigit_api_key') {
                    echo '<input type="password" name="' . esc_attr($name) . '" value="" autocomplete="new-password" placeholder="' . ($value !== '' ? 'Schlüssel ist hinterlegt' : 'HeiGIT API-Schlüssel') . '">';
                } elseif (in_array($name, ['pov_kilometer_rate', 'pov_personnel_hourly_rate', 'pov_personnel_count', 'pov_default_visit_hours', 'pov_average_driving_speed_kmh', 'pov_max_public_suggestion_cost', 'pov_max_public_suggestion_distance_km', 'pov_cluster_radius_km', 'pov_public_booking_horizon_days'], true)) {
                    $step = in_array($name, ['pov_personnel_count', 'pov_public_booking_horizon_days'], true) ? '1' : '0.01';
                    echo '<input type="number" min="0" step="' . esc_attr($step) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
                } else {
                    echo '<input name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
                }
                echo '</label>';
            }
            if ($group === 'Routing') {
                $testUrl = wp_nonce_url(admin_url('admin-post.php?action=pov_test_geo'), 'pov_test_geo');
                echo '<div class="pov-settings-action"><a class="button" href="' . esc_url($testUrl) . '">Gespeicherte Verbindung testen</a><span>Prüft Geocoding und Fahrzeitmatrix.</span></div>';
            }
            echo '</div></details>';
        }
        echo '<label><input type="checkbox" name="pov_cleanup_on_uninstall" value="1" ' . checked('1', get_option('pov_cleanup_on_uninstall'), false) . '> Daten beim Uninstall vollständig löschen</label>';
        echo '<p><button class="button button-primary">Einstellungen speichern</button></p></form></section>';

        echo '<section class="pov-admin-panel"><h2>Bundesländer</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pov_save_states');
        echo '<input type="hidden" name="action" value="pov_save_states"><table class="widefat"><thead><tr><th>Aktiv</th><th>Code</th><th>Name</th><th>Sortierung</th></tr></thead><tbody>';
        foreach ($states as $state) {
            echo '<tr><td><input type="checkbox" name="states[' . esc_attr($state['state_code']) . '][is_active]" value="1" ' . checked(1, (int) $state['is_active'], false) . '></td><td>' . esc_html($state['state_code']) . '</td><td>' . esc_html($state['state_name']) . '</td><td><input type="number" name="states[' . esc_attr($state['state_code']) . '][sort_order]" value="' . esc_attr((string) $state['sort_order']) . '"></td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary">Bundesländer speichern</button></p></form></section>';
        $this->footer();
    }

    public function saveSettings(): void
    {
        $this->guard('pov_save_settings', 'manage_options');
        foreach (array_keys($this->settingsFields()) as $name) {
            if ($name === 'pov_heigit_api_key' && trim((string) ($_POST[$name] ?? '')) === '') {
                continue;
            }
            $textarea = str_ends_with($name, '_body') || str_ends_with($name, '_template');
            update_option($name, $textarea ? sanitize_textarea_field((string) ($_POST[$name] ?? '')) : sanitize_text_field((string) ($_POST[$name] ?? '')));
        }
        update_option('pov_routing_provider', sanitize_key((string) ($_POST['pov_routing_provider'] ?? 'null')));
        update_option('pov_geocoding_provider', sanitize_key((string) ($_POST['pov_geocoding_provider'] ?? 'null')));
        update_option('pov_test_profile_enabled', Activation::isTestProfileActive() ? '1' : '0');
        update_option('pov_cleanup_on_uninstall', ! empty($_POST['pov_cleanup_on_uninstall']) ? '1' : '0');
        $this->redirect('pov-settings');
    }

    public function enableTestProfile(): void
    {
        $this->guard('pov_enable_test_profile', 'manage_options');
        Activation::enableTestProfile(true);
        $this->redirect('pov-settings', ['pov_notice' => 'test-profile']);
    }

    public function testGeoConnection(): void
    {
        $this->guard('pov_test_geo', 'manage_options');
        $factory = new ProviderFactory();
        $geocode = $factory->geocoding()->geocodeAddress([
            'postal_code' => '70173',
            'city' => 'Stuttgart',
            'country' => 'Deutschland',
        ]);
        $matrix = $factory->routing()->calculateMatrix(
            [['lat' => 48.5216, 'lon' => 9.0576], ['lat' => 48.7758, 'lon' => 9.1829]],
            [['lat' => 48.5216, 'lon' => 9.0576], ['lat' => 48.7758, 'lon' => 9.1829]]
        );
        $geocodingOk = ! empty($geocode['ok']);
        $routingOk = ! empty($matrix['ok'])
            && is_numeric($matrix['distances'][0][1] ?? null)
            && is_numeric($matrix['durations'][0][1] ?? null);
        set_transient('pov_geo_test_' . get_current_user_id(), [
            'routing_provider' => (string) get_option('pov_routing_provider'),
            'geocoding_provider' => (string) get_option('pov_geocoding_provider'),
            'routing_ok' => $routingOk,
            'geocoding_ok' => $geocodingOk,
            'routing_error' => sanitize_text_field((string) ($matrix['error'] ?? '')),
            'geocoding_error' => sanitize_text_field((string) ($geocode['error'] ?? '')),
            'distance' => is_numeric($matrix['distances'][0][1] ?? null) ? (float) $matrix['distances'][0][1] : null,
            'duration' => is_numeric($matrix['durations'][0][1] ?? null) ? (float) $matrix['durations'][0][1] : null,
            'checked_at' => current_time('mysql'),
        ], 10 * MINUTE_IN_SECONDS);
        $notice = $routingOk && $geocodingOk ? 'geo-ok' : (($routingOk || $geocodingOk) ? 'geo-partial' : 'geo-failed');
        $this->redirect('pov-settings', ['pov_notice' => $notice]);
    }

    public function saveStates(): void
    {
        $this->guard('pov_save_states', 'manage_options');
        (new StateRepository())->updateStates((array) ($_POST['states'] ?? []));
        $this->redirect('pov-settings');
    }

    public function saveCalendarDay(): void
    {
        $this->guard('pov_save_calendar_day', Capabilities::MANAGE_CALENDAR);
        $from = sanitize_text_field((string) ($_POST['calendar_date_from'] ?? ($_POST['calendar_date'] ?? '')));
        $to = sanitize_text_field((string) ($_POST['calendar_date_to'] ?? ''));
        $start = $this->resolveCalendarStartPoint();
        $count = (new CalendarDayRepository())->upsertRange(
            $from,
            $to,
            sanitize_key((string) ($_POST['availability_state'] ?? 'available')),
            (string) ($_POST['public_note'] ?? ''),
            (string) ($_POST['internal_note'] ?? ''),
            $start
        );
        $this->redirect('pov-calendar', ['pov_saved_days' => $count]);
    }

    public function recalculateSuggestions(): void
    {
        $this->guard('pov_recalculate_suggestions', Capabilities::MANAGE_TOURS);
        $id = (int) ($_POST['request_id'] ?? 0);
        (new InternalSuggestionService())->recalculate($id);
        $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'recalculated']);
    }

    public function suggestionState(): void
    {
        $this->guard('pov_suggestion_state', Capabilities::SEND_RESPONSES);
        $id = (int) ($_POST['suggestion_id'] ?? 0);
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $state = sanitize_key((string) ($_POST['suggestion_state'] ?? ''));
        (new SuggestionRepository())->setState($id, $state);
        $this->redirect('pov-requests', ['request_id' => $requestId, 'pov_notice' => 'suggestion-updated']);
    }

    public function sendProposals(): void
    {
        $this->guard('pov_send_proposals', Capabilities::SEND_RESPONSES);
        $id = (int) ($_POST['request_id'] ?? 0);
        $request = (new RequestRepository())->find($id);
        $suggestions = (new SuggestionRepository())->acceptedForRequest($id);
        if ($request) {
            foreach ($suggestions as $index => $suggestion) {
                $date = (string) $suggestion['suggestion_date'];
                if (! $this->dateAllowedForRequest($date, $request) || (new CalendarDayRepository())->isBlocked($date) || (new AppointmentRepository())->existsOnDate($date)) {
                    (new SuggestionRepository())->setState((int) $suggestion['id'], SuggestionState::EXPIRED);
                    unset($suggestions[$index]);
                }
            }
            $suggestions = array_values($suggestions);
        }
        if (! $request || ! $suggestions) {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'proposal-unavailable']);
        }
        if (! (new MailService())->sendProposal($request, $suggestions, (string) ($_POST['subject'] ?? ''), (string) ($_POST['message'] ?? ''))) {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'proposal-failed']);
        }
        (new SuggestionRepository())->markSent(array_column($suggestions, 'id'));
        (new RequestRepository())->updateStatus($id, RequestStatus::PROPOSAL_SENT, WorkState::AWAITING_RESPONSE, ['proposal_sent_at' => current_time('mysql')]);
        $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'proposal-sent']);
    }

    public function confirmRequest(): void
    {
        $this->guard('pov_confirm_request', Capabilities::MANAGE_REQUESTS);
        $id = (int) ($_POST['request_id'] ?? 0);
        $request = (new RequestRepository())->find($id);
        if ($request) {
            $date = sanitize_text_field((string) ($_POST['appointment_date'] ?? ''));
            if (! $this->dateAllowedForRequest($date, $request)
                || (int) (new DateTimeImmutable($date))->format('N') >= 6
                || (new CalendarDayRepository())->isBlocked($date)) {
                $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'date-outside-request']);
            }
            $created = (new AppointmentRepository())->createFromRequest(
                $request,
                $date,
                sanitize_text_field((string) ($_POST['public_city'] ?? '')),
                ['distance_km' => is_numeric($_POST['route_distance_km'] ?? null) ? (float) $_POST['route_distance_km'] : null, 'cost' => is_numeric($_POST['route_cost'] ?? null) ? (float) $_POST['route_cost'] : null],
                (string) ($_POST['internal_notes'] ?? '')
            );
            if ($created > 0) {
                (new RequestRepository())->updateStatus($id, RequestStatus::CONFIRMED, WorkState::ACCEPTED, ['confirmed_at' => current_time('mysql')]);
                $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'confirmed']);
            }
        }
        $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'confirm-failed']);
    }

    public function sendResponse(): void
    {
        $this->guard('pov_send_response', Capabilities::SEND_RESPONSES);
        $id = (int) ($_POST['request_id'] ?? 0);
        $type = sanitize_key((string) ($_POST['response_type'] ?? ''));
        $message = sanitize_textarea_field((string) wp_unslash($_POST['message'] ?? ''));
        $requestRepository = new RequestRepository();
        $request = $requestRepository->find($id);

        if (! $request || ! in_array($type, ['accept', 'question', 'reject'], true) || $message === '') {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'response-invalid']);
        }
        if (in_array((string) $request['work_state'], [WorkState::ACCEPTED, WorkState::REJECTED, WorkState::CANCELLED], true)) {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'response-closed']);
        }

        $date = '';
        if ($type === 'accept') {
            $date = sanitize_text_field((string) ($_POST['appointment_date'] ?? ''));
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $appointments = new AppointmentRepository();
            if (! $parsedDate || $parsedDate->format('Y-m-d') !== $date
                || ! $this->dateAllowedForRequest($date, $request)
                || (int) $parsedDate->format('N') >= 6
                || (new CalendarDayRepository())->isBlocked($date)
                || ($appointments->existsOnDate($date) && ! $appointments->existsForRequest($id))) {
                $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'date-outside-request']);
            }

            if (! $appointments->existsForRequest($id)) {
                $created = $appointments->createFromRequest($request, $date, (string) $request['city'], [
                    'start_label' => (string) get_option('pov_default_start_label', ''),
                    'start_latitude' => is_numeric(get_option('pov_default_start_latitude')) ? (float) get_option('pov_default_start_latitude') : null,
                    'start_longitude' => is_numeric(get_option('pov_default_start_longitude')) ? (float) get_option('pov_default_start_longitude') : null,
                    'distance_km' => is_numeric($request['route_distance_km'] ?? null) ? (float) $request['route_distance_km'] : null,
                    'cost' => is_numeric($request['route_cost'] ?? null) ? (float) $request['route_cost'] : null,
                ]);
                if ($created <= 0) {
                    $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'confirm-failed']);
                }
            }
        }

        if (! (new MailService())->sendResponse($request, $type, $message, $date)) {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'response-failed']);
        }

        if ($type === 'accept') {
            $requestRepository->updateStatus($id, RequestStatus::CONFIRMED, WorkState::ACCEPTED, ['confirmed_at' => current_time('mysql')]);
        } elseif ($type === 'question') {
            $requestRepository->updateStatus($id, (string) $request['main_status'], WorkState::AWAITING_RESPONSE);
        } elseif ($type === 'reject') {
            $requestRepository->updateStatus($id, (string) $request['main_status'], WorkState::REJECTED);
        }

        $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'response-' . $type]);
    }

    public function saveNote(): void
    {
        $this->guard('pov_save_note', Capabilities::MANAGE_REQUESTS);
        $id = (int) ($_POST['request_id'] ?? 0);
        (new RequestRepository())->updateInternalNote($id, (string) ($_POST['internal_note'] ?? ''));
        $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'note-saved']);
    }

    public function saveRequestAddress(): void
    {
        $this->guard('pov_save_request_address', Capabilities::MANAGE_REQUESTS);
        $id = (int) ($_POST['request_id'] ?? 0);
        $repository = new RequestRepository();
        $request = $repository->find($id);
        $address = [
            'street' => sanitize_text_field((string) ($_POST['street'] ?? '')),
            'house_number' => sanitize_text_field((string) ($_POST['house_number'] ?? '')),
            'postal_code' => preg_replace('/\D+/', '', (string) ($_POST['postal_code'] ?? '')),
            'city' => sanitize_text_field((string) ($_POST['city'] ?? '')),
            'state_code' => strtoupper(sanitize_key((string) ($_POST['state_code'] ?? ''))),
        ];
        if (! $request || $address['street'] === '' || $address['house_number'] === '' || ! preg_match('/^\d{5}$/', $address['postal_code']) || $address['city'] === '' || ! array_key_exists($address['state_code'], array_column((new StateRepository())->all(false), 'state_name', 'state_code'))) {
            $this->redirect('pov-requests', ['request_id' => $id, 'edit_address' => '1', 'pov_notice' => 'address-invalid']);
        }
        if (! $repository->updateAddress($id, $address)) {
            $this->redirect('pov-requests', ['request_id' => $id, 'edit_address' => '1', 'pov_notice' => 'address-invalid']);
        }

        $routing = new RequestRoutingService();
        $updated = $routing->refreshRequest($id) ?? $repository->find($id);
        if ($updated) {
            (new AppointmentRepository())->syncAddressFromRequest($updated);
        }
        $geocoded = $updated && is_numeric($updated['latitude'] ?? null) && is_numeric($updated['longitude'] ?? null);
        $routeReady = $updated && is_numeric($updated['route_distance_km'] ?? null);
        if ($geocoded && ! in_array((string) ($updated['work_state'] ?? ''), [WorkState::ACCEPTED, WorkState::REJECTED, WorkState::CANCELLED], true)) {
            (new InternalSuggestionService())->recalculate($id);
        }
        if ($routing->lastError() !== '') {
            set_transient('pov_address_feedback_' . get_current_user_id(), sanitize_text_field($routing->lastError()), 5 * MINUTE_IN_SECONDS);
        }
        $notice = $routeReady ? 'address-geocoded' : ($geocoded ? 'address-no-route' : 'address-unverified');
        $this->redirect('pov-requests', ['request_id' => $id, 'edit_address' => $geocoded ? '0' : '1', 'pov_notice' => $notice]);
    }

    public function retryMissingAddresses(): void
    {
        $this->guard('pov_retry_missing_addresses', Capabilities::MANAGE_TOURS);
        $repository = new RequestRepository();
        $checked = 0;
        $found = 0;
        foreach ($repository->list([], 200) as $request) {
            if (is_numeric($request['latitude'] ?? null) && is_numeric($request['longitude'] ?? null)) {
                continue;
            }
            $checked++;
            $updated = (new RequestRoutingService())->refreshRequest((int) $request['id']);
            if (! $updated || ! is_numeric($updated['latitude'] ?? null) || ! is_numeric($updated['longitude'] ?? null)) {
                continue;
            }
            $found++;
            (new AppointmentRepository())->syncAddressFromRequest($updated);
            if (! in_array((string) ($updated['work_state'] ?? ''), [WorkState::ACCEPTED, WorkState::REJECTED, WorkState::CANCELLED], true)) {
                (new InternalSuggestionService())->recalculate((int) $request['id']);
            }
        }
        $this->redirect('pov-routes', ['pov_notice' => 'addresses-checked', 'pov_checked' => $checked, 'pov_found' => $found]);
    }

    public function updateWorkflow(): void
    {
        $this->guard('pov_update_workflow', Capabilities::MANAGE_REQUESTS);
        $id = (int) ($_POST['request_id'] ?? 0);
        $repository = new RequestRepository();
        $request = $repository->find($id);
        if (! $request) {
            wp_die('Anfrage nicht gefunden.');
        }

        $mainStatus = sanitize_key((string) ($_POST['main_status'] ?? ''));
        $workState = sanitize_key((string) ($_POST['work_state'] ?? ''));
        if (! array_key_exists($mainStatus, RequestStatus::labels())) {
            $mainStatus = (string) $request['main_status'];
        }
        if (! array_key_exists($workState, WorkState::labels())) {
            $workState = (string) $request['work_state'];
        }

        $extra = [];
        $distance = str_replace(',', '.', sanitize_text_field((string) ($_POST['route_distance_km'] ?? '')));
        $cost = str_replace(',', '.', sanitize_text_field((string) ($_POST['route_cost'] ?? '')));
        if (is_numeric($distance)) {
            $extra['route_distance_km'] = max(0, (float) $distance);
        }
        if (is_numeric($cost)) {
            $extra['route_cost'] = max(0, (float) $cost);
        }

        $repository->updateStatus($id, $mainStatus, $workState, $extra);
        $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'workflow-saved']);
    }

    public function downloadIcs(): void
    {
        $this->guard('pov_download_ics', Capabilities::EXPORT_CALENDAR);
        $appointment = (new AppointmentRepository())->find((int) ($_GET['appointment_id'] ?? 0));
        if (! $appointment) {
            wp_die('Termin nicht gefunden.');
        }
        $request = $appointment['request_id'] ? (new RequestRepository())->find((int) $appointment['request_id']) : [];
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="ocean-van-' . sanitize_file_name((string) $appointment['appointment_date']) . '.ics"');
        echo (new IcsService())->appointment($appointment, $request ?: []);
        exit;
    }

    private function adminCalendarEvents(string $start, string $end): array
    {
        $events = [];
        foreach ((new CalendarDayRepository())->forRange($start, $end) as $date => $row) {
            $events[$date]['day'] = $row;
        }

        foreach ((new AppointmentRepository())->forRange($start, $end) as $date => $row) {
            $events[$date]['appointment'] = $row;
        }

        foreach ((new RequestRepository())->list([], 200) as $request) {
            $dates = $this->requestDatesInRange($request, $start, $end);
            foreach ($dates as $date) {
                $events[$date]['requests'][] = $request;
            }
        }

        global $wpdb;
        $suggestions = $wpdb->get_results($wpdb->prepare(
            "SELECT suggestion_date, COUNT(*) AS total FROM {$wpdb->prefix}pov_suggestions WHERE suggestion_date BETWEEN %s AND %s AND state IN ('pending','accepted','sent') GROUP BY suggestion_date",
            $start,
            $end
        ), ARRAY_A) ?: [];
        foreach ($suggestions as $row) {
            $events[(string) $row['suggestion_date']]['suggestions'] = (int) $row['total'];
        }

        return $events;
    }

    private function requestDatesInRange(array $request, string $start, string $end): array
    {
        if (($request['work_state'] ?? '') === WorkState::ACCEPTED || ($request['work_state'] ?? '') === WorkState::REJECTED || ($request['work_state'] ?? '') === WorkState::CANCELLED) {
            return [];
        }

        $dates = [];
        if (($request['request_mode'] ?? '') === 'specific_date' && ! empty($request['specific_requested_date'])) {
            $date = (string) $request['specific_requested_date'];
            return ($date >= $start && $date <= $end) ? [$date] : [];
        }

        $from = (string) ($request['desired_date_from'] ?? '');
        $to = (string) ($request['desired_date_to'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return [];
        }

        if ($to < $start || $from > $end) {
            return [];
        }

        $rangeStart = max($from, $start);
        $rangeEnd = min($to, $end);
        $period = new DatePeriod(new DateTimeImmutable($rangeStart), new DateInterval('P1D'), (new DateTimeImmutable($rangeEnd))->modify('+1 day'));
        foreach ($period as $day) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    private function adminCalendarGrid(DateTimeImmutable $monthStart, array $events): string
    {
        $editable = current_user_can(Capabilities::MANAGE_CALENDAR);
        $html = '<div class="pov-admin-calendar-grid">';
        foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $weekday) {
            $html .= '<div class="pov-admin-calendar-weekday">' . esc_html($weekday) . '</div>';
        }

        $offset = ((int) $monthStart->format('N')) - 1;
        for ($i = 0; $i < $offset; $i++) {
            $html .= '<span class="pov-admin-calendar-empty"></span>';
        }

        $monthEnd = $monthStart->modify('last day of this month');
        $period = new DatePeriod($monthStart, new DateInterval('P1D'), $monthEnd->modify('+1 day'));
        foreach ($period as $day) {
            $date = $day->format('Y-m-d');
            $event = $events[$date] ?? [];
            $state = (string) ($event['day']['availability_state'] ?? 'available');
            if (isset($event['appointment'])) {
                $state = 'confirmed';
            }
            $classes = 'pov-admin-calendar-day is-' . sanitize_html_class($state);
            $html .= $editable
                ? '<button type="button" class="' . esc_attr($classes) . '" data-pov-calendar-day data-date="' . esc_attr($date) . '" data-state="' . esc_attr((string) ($event['day']['availability_state'] ?? 'available')) . '" data-public-note="' . esc_attr((string) ($event['day']['public_note'] ?? '')) . '" data-internal-note="' . esc_attr((string) ($event['day']['internal_note'] ?? '')) . '" data-start-label="' . esc_attr((string) ($event['day']['custom_start_label'] ?? '')) . '" data-start-lat="' . esc_attr((string) ($event['day']['custom_start_latitude'] ?? '')) . '" data-start-lon="' . esc_attr((string) ($event['day']['custom_start_longitude'] ?? '')) . '">'
                : '<div class="' . esc_attr($classes) . '">';
            $html .= '<strong>' . esc_html($day->format('j')) . '</strong>';
            $html .= '<span>' . esc_html($this->calendarStateLabel($state, $event)) . '</span>';
            if (! empty($event['requests'])) {
                $html .= '<em>' . esc_html((string) count($event['requests'])) . ' Anfrage' . (count($event['requests']) === 1 ? '' : 'n') . '</em>';
            }
            if (! empty($event['suggestions'])) {
                $html .= '<em>' . esc_html((string) $event['suggestions']) . ' Vorschlag' . ((int) $event['suggestions'] === 1 ? '' : 'e') . '</em>';
            }
            $html .= $editable ? '</button>' : '</div>';
        }

        return $html . '</div>';
    }

    private function calendarStateLabel(string $state, array $event): string
    {
        if ($state === 'confirmed') {
            return 'Bestätigt: ' . (string) ($event['appointment']['public_city'] ?? '');
        }

        return [
            'limited' => 'Auf Anfrage',
            'unavailable' => 'Nicht buchbar',
            'available' => 'Buchbar',
        ][$state] ?? 'Buchbar';
    }

    private function requestTable(array $items): void
    {
        echo '<div class="pov-table-scroll"><table class="pov-operations-table"><thead><tr><th>Anfrage</th><th>Einrichtung &amp; Ort</th><th>Terminwunsch</th><th>Route</th><th>Status</th><th><span class="screen-reader-text">Aktion</span></th></tr></thead><tbody>';
        foreach ($items as $item) {
            $route = is_numeric($item['route_cost'] ?? null)
                ? number_format((float) $item['route_cost'], 0, ',', '.') . ' € · ' . number_format((float) $item['route_distance_km'], 0, ',', '.') . ' km'
                : 'Prüfung offen';
            $warnings = $this->warningCount($item);
            echo '<tr><td data-label="Anfrage"><strong>' . esc_html($item['public_uuid']) . '</strong><small>Eingang ' . esc_html(mysql2date('d.m.Y', (string) $item['created_at'])) . '</small></td>';
            echo '<td data-label="Einrichtung"><strong>' . esc_html($item['institution_name']) . '</strong><small>' . esc_html($item['postal_code'] . ' ' . $item['city'] . ' · ' . $item['state_code']) . '</small></td>';
            echo '<td data-label="Termin"><span>' . esc_html($this->requestDateLabel($item)) . '</span>' . ($warnings > 0 ? '<small class="pov-table-warning">' . esc_html((string) $warnings) . ' Vor-Ort-Hinweis' . ($warnings === 1 ? '' : 'e') . '</small>' : '') . '</td>';
            echo '<td data-label="Route"><strong>' . esc_html($route) . '</strong><small>' . (is_numeric($item['latitude'] ?? null) ? 'Geocodiert' : 'Adresse prüfen') . '</small></td>';
            echo '<td data-label="Status"><span class="pov-status is-' . esc_attr($item['work_state']) . '">' . esc_html(WorkState::labels()[$item['work_state']] ?? $item['work_state']) . '</span><small>' . esc_html(RequestStatus::labels()[$item['main_status']] ?? $item['main_status']) . '</small></td>';
            echo '<td><a class="button pov-open-button" href="' . esc_url($this->pageUrl('pov-requests', ['request_id' => (int) $item['id']])) . '">Öffnen →</a></td></tr>';
        }
        if (! $items) {
            echo '<tr><td colspan="6"><div class="pov-admin-empty"><strong>Keine Anfragen in dieser Ansicht.</strong><span>Passt die Filter an oder setzt sie zurück.</span></div></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function warningCount(array $request): int
    {
        $count = 0;
        foreach (['parking_available', 'indoor_room_available', 'bad_weather_option_available', 'electricity_available', 'water_available'] as $field) {
            if (($request[$field] ?? 'unknown') !== 'yes') {
                $count++;
            }
        }
        return $count;
    }

    private function calendarExportPanel(array $items): string
    {
        $html = '<div class="pov-calendar-export" data-pov-calendar-export><span class="pov-admin-eyebrow">Bestätigte Termine</span><h2>Kalenderexport</h2>';
        if (! $items) {
            return $html . '<p>Keine bestätigten Termine in diesem Monat.</p></div>';
        }

        $today = current_time('Y-m-d');
        $selectedId = (int) ($items[0]['id'] ?? 0);
        foreach ($items as $item) {
            if ((string) ($item['appointment_date'] ?? '') >= $today) {
                $selectedId = (int) $item['id'];
                break;
            }
        }

        $requestRepository = new RequestRepository();
        $icsService = new IcsService();
        $links = [];
        $html .= '<label>Termin <select data-pov-calendar-export-select>';
        foreach ($items as $item) {
            $request = $item['request_id'] ? $requestRepository->find((int) $item['request_id']) : [];
            $ics = wp_nonce_url(admin_url('admin-post.php?action=pov_download_ics&appointment_id=' . (int) $item['id']), 'pov_download_ics');
            $google = $icsService->googleLink($item, $request ?: []);
            $links[(int) $item['id']] = ['ics' => $ics, 'google' => $google];
            $label = mysql2date('d.m.Y', (string) $item['appointment_date']) . ' · ' . ((string) ($item['public_city'] ?? '') ?: (string) ($item['institution_name'] ?? ''));
            $html .= '<option value="' . esc_attr((string) $item['id']) . '" data-date="' . esc_attr((string) $item['appointment_date']) . '" data-ics="' . esc_url($ics) . '" data-google="' . esc_url($google) . '"' . selected($selectedId, (int) $item['id'], false) . '>' . esc_html($label) . '</option>';
        }
        $selectedLinks = $links[$selectedId] ?? reset($links);
        $html .= '</select></label><div class="pov-calendar-export-actions">';
        $html .= '<a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url((string) ($selectedLinks['google'] ?? '')) . '" data-pov-calendar-export-google>In Google Kalender</a>';
        $html .= '<a class="button" href="' . esc_url((string) ($selectedLinks['ics'] ?? '')) . '" data-pov-calendar-export-ics>ICS herunterladen</a>';

        return $html . '</div></div>';
    }

    private function suggestionTable(array $suggestions): void
    {
        $this->renderSuggestionCards($suggestions);
    }

    private function renderSuggestionCards(array $suggestions, bool $showActions = true): void
    {
        echo '<div class="pov-suggestion-list">';
        foreach ($suggestions as $item) {
            $selected = $item['state'] === SuggestionState::ACCEPTED;
            echo '<article class="pov-admin-suggestion' . ($selected ? ' is-selected' : '') . '"><div class="pov-admin-date"><small>' . esc_html(GermanDateFormatter::weekdayShort((string) $item['suggestion_date'])) . '</small><strong>' . esc_html(mysql2date('d', (string) $item['suggestion_date'])) . '</strong><span>' . esc_html(GermanDateFormatter::monthShort((string) $item['suggestion_date'])) . '</span></div>';
            echo '<div class="pov-admin-suggestion-copy"><strong>' . esc_html(GermanDateFormatter::full((string) $item['suggestion_date'])) . '</strong></div>';
            echo '<div class="pov-admin-suggestion-metrics"><span>Zusatzstrecke<strong>' . esc_html(is_numeric($item['estimated_distance_km']) ? number_format((float) $item['estimated_distance_km'], 0, ',', '.') . ' km' : 'offen') . '</strong></span><span>Fahrtkosten<strong>' . esc_html(is_numeric($item['estimated_cost']) ? number_format((float) $item['estimated_cost'], 2, ',', '.') . ' €' : 'offen') . '</strong></span></div>';
            if ($showActions) {
                echo '<div class="pov-admin-suggestion-actions">' . $this->suggestionStateForm($item, SuggestionState::ACCEPTED, $selected ? 'Ausgewählt' : 'Auswählen', $selected ? 'button-primary' : '') . $this->suggestionStateForm($item, SuggestionState::REJECTED, 'Verwerfen', 'button-link') . '</div>';
            }
            echo '</article>';
        }
        if (! $suggestions) {
            echo '<div class="pov-admin-empty"><strong>Noch keine Termine berechnet.</strong></div>';
        }
        echo '</div>';
    }

    private function responseForm(array $request, array $suggestions): string
    {
        if (in_array((string) ($request['work_state'] ?? ''), [WorkState::ACCEPTED, WorkState::REJECTED, WorkState::CANCELLED], true)) {
            return '<div class="pov-admin-empty"><strong>Vorgang abgeschlossen.</strong></div>';
        }

        $value = (string) ($suggestions[0]['suggestion_date'] ?? $request['specific_requested_date'] ?? $request['desired_date_from'] ?? '');
        $min = ($request['request_mode'] ?? '') === 'specific_date'
            ? (string) ($request['specific_requested_date'] ?? '')
            : (string) ($request['desired_date_from'] ?? '');
        $max = ($request['request_mode'] ?? '') === 'specific_date'
            ? (string) ($request['specific_requested_date'] ?? '')
            : (string) ($request['desired_date_to'] ?? '');
        $templates = [
            'accept' => $this->responseTemplate('accept', $request),
            'question' => $this->responseTemplate('question', $request),
            'reject' => $this->responseTemplate('reject', $request),
        ];

        ob_start();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form pov-response-form" data-pov-response-form data-template-accept="' . esc_attr($templates['accept']) . '" data-template-question="' . esc_attr($templates['question']) . '" data-template-reject="' . esc_attr($templates['reject']) . '">';
        wp_nonce_field('pov_send_response');
        echo '<input type="hidden" name="action" value="pov_send_response"><input type="hidden" name="request_id" value="' . esc_attr((string) $request['id']) . '">';
        echo '<fieldset class="pov-response-choices"><legend class="screen-reader-text">Antwort wählen</legend>';
        foreach (['accept' => 'Zusage', 'question' => 'Rückfrage', 'reject' => 'Absage'] as $type => $label) {
            echo '<label><input type="radio" name="response_type" value="' . esc_attr($type) . '" ' . checked('accept', $type, false) . '><span>' . esc_html($label) . '</span></label>';
        }
        echo '</fieldset>';
        echo '<div class="pov-response-date" data-pov-response-date><label>Termin <input type="date" name="appointment_date" value="' . esc_attr($value) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" required></label>';
        if ($suggestions) {
            echo '<div class="pov-response-date-picks">';
            foreach ($suggestions as $suggestion) {
                $date = (string) $suggestion['suggestion_date'];
                echo '<button type="button" class="button" data-pov-response-date-value="' . esc_attr($date) . '">' . esc_html(GermanDateFormatter::short($date)) . '</button>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<label>Nachricht <textarea name="message" required>' . esc_textarea($templates['accept']) . '</textarea></label>';
        echo '<button class="button button-primary">Antwort senden</button></form>';
        return (string) ob_get_clean();
    }

    private function responseTemplate(string $type, array $request): string
    {
        $template = (string) get_option('pov_response_' . $type . '_template', '');
        return strtr($template, [
            '{Vorname}' => (string) ($request['contact_first_name'] ?? ''),
            '{Einrichtung}' => (string) ($request['institution_name'] ?? ''),
            '{Ort}' => (string) ($request['city'] ?? ''),
        ]);
    }

    private function suggestionStateForm(array $suggestion, string $state, string $label, string $buttonClass = ''): string
    {
        ob_start();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pov_suggestion_state');
        echo '<input type="hidden" name="action" value="pov_suggestion_state">';
        echo '<input type="hidden" name="suggestion_id" value="' . esc_attr((string) $suggestion['id']) . '">';
        echo '<input type="hidden" name="request_id" value="' . esc_attr((string) $suggestion['request_id']) . '">';
        echo '<input type="hidden" name="suggestion_state" value="' . esc_attr($state) . '">';
        echo '<button class="button ' . esc_attr($buttonClass) . '">' . esc_html($label) . '</button></form>';
        return (string) ob_get_clean();
    }

    private function suggestionStateLabel(string $state): string
    {
        return [
            SuggestionState::PENDING => 'Berechnet',
            SuggestionState::ACCEPTED => 'Für Mail ausgewählt',
            SuggestionState::REJECTED => 'Verworfen',
            SuggestionState::SENT => 'Versendet',
            SuggestionState::EXPIRED => 'Abgelaufen',
        ][$state] ?? $state;
    }

    private function proposalForm(array $request, array $suggestions): string
    {
        ob_start();
        $accepted = array_filter($suggestions, static fn (array $row): bool => $row['state'] === SuggestionState::ACCEPTED);
        echo '<h3>Terminvorschläge senden</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form">';
        wp_nonce_field('pov_send_proposals');
        echo '<input type="hidden" name="action" value="pov_send_proposals"><input type="hidden" name="request_id" value="' . esc_attr((string) $request['id']) . '">';
        echo '<label>Empfänger <input readonly value="' . esc_attr($request['contact_email']) . '"></label>';
        echo '<label>Betreff <input name="subject" value="' . esc_attr((string) get_option('pov_proposal_email_subject')) . '"></label>';
        echo '<label>Nachricht <textarea name="message">' . esc_textarea((string) get_option('pov_proposal_email_body')) . '</textarea></label>';
        echo '<p>Ausgewählte Termine: ' . esc_html((string) count($accepted)) . '</p>';
        echo '<button class="button button-primary" ' . disabled(!$accepted, true, false) . '>Senden</button></form>';
        return (string) ob_get_clean();
    }

    private function confirmForm(array $request): string
    {
        ob_start();
        $min = '';
        $max = '';
        $value = (string) ($request['specific_requested_date'] ?: '');
        if (($request['request_mode'] ?? '') === 'specific_date' && $value !== '') {
            $min = $value;
            $max = $value;
        } elseif (($request['request_mode'] ?? '') === 'date_range') {
            $min = (string) ($request['desired_date_from'] ?? '');
            $max = (string) ($request['desired_date_to'] ?? '');
            $value = $min;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form">';
        wp_nonce_field('pov_confirm_request');
        echo '<input type="hidden" name="action" value="pov_confirm_request"><input type="hidden" name="request_id" value="' . esc_attr((string) $request['id']) . '">';
        echo '<label>Datum <input type="date" name="appointment_date" value="' . esc_attr($value) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" required></label>';
        echo '<label>Öffentliche Stadt <input name="public_city" value="' . esc_attr((string) $request['city']) . '" required></label>';
        echo '<label>Interne Notiz <textarea name="internal_notes"></textarea></label>';
        echo '<button class="button button-primary">Termin bestätigen</button></form>';
        return (string) ob_get_clean();
    }

    private function privacyActions(int $id): string
    {
        $export = wp_nonce_url(admin_url('admin-post.php?action=pov_export_request&request_id=' . $id), 'pov_export_request');
        ob_start();
        echo '<a class="button" href="' . esc_url($export) . '">Anfrage exportieren</a>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Anfrage anonymisieren? Kalenderexporte können extern gespeichert sein.\')">';
        wp_nonce_field('pov_anonymize_request');
        echo '<input type="hidden" name="action" value="pov_anonymize_request"><input type="hidden" name="request_id" value="' . esc_attr((string) $id) . '"><button class="button button-link-delete">Anfrage anonymisieren</button></form>';
        return (string) ob_get_clean();
    }

    private function addressPanel(array $request): string
    {
        $geocoded = is_numeric($request['latitude'] ?? null) && is_numeric($request['longitude'] ?? null);
        $routeReady = is_numeric($request['route_distance_km'] ?? null);
        $open = ! empty($_GET['edit_address']);
        $stateOptions = [];
        foreach ((new StateRepository())->all(false) as $state) {
            $stateOptions[(string) $state['state_code']] = (string) $state['state_name'];
        }

        ob_start();
        echo '<section class="pov-admin-panel pov-address-panel"><span class="pov-admin-eyebrow">Kontakt &amp; Adresse</span><h2>' . esc_html($request['contact_first_name'] . ' ' . $request['contact_last_name']) . '</h2>';
        echo '<p><a href="mailto:' . esc_attr($request['contact_email']) . '">' . esc_html($request['contact_email']) . '</a><br><a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', (string) $request['contact_phone'])) . '">' . esc_html($request['contact_phone']) . '</a></p>';
        echo '<div class="pov-address-display"><address>' . esc_html($request['street'] . ' ' . $request['house_number']) . '<br>' . esc_html($request['postal_code'] . ' ' . $request['city']) . '<br>' . esc_html((string) $request['state_code']) . '</address>';
        echo '<span class="pov-address-status ' . ($geocoded ? 'is-ok' : 'is-error') . '">' . ($geocoded ? ($routeReady ? 'Adresse & Route geprüft' : 'Adresse geprüft') : 'Prüfung offen') . '</span></div>';
        if (current_user_can(Capabilities::MANAGE_REQUESTS)) {
            echo '<details id="pov-address-editor" class="pov-address-editor"' . ($open ? ' open' : '') . '><summary>Adresse ändern</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form">';
            wp_nonce_field('pov_save_request_address');
            echo '<input type="hidden" name="action" value="pov_save_request_address"><input type="hidden" name="request_id" value="' . esc_attr((string) $request['id']) . '">';
            echo '<div class="pov-admin-two"><label>Straße <input name="street" value="' . esc_attr((string) $request['street']) . '" required></label><label>Hausnummer <input name="house_number" value="' . esc_attr((string) $request['house_number']) . '" required></label></div>';
            echo '<div class="pov-admin-two"><label>PLZ <input name="postal_code" value="' . esc_attr((string) $request['postal_code']) . '" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" required></label><label>Ort <input name="city" value="' . esc_attr((string) $request['city']) . '" required></label></div>';
            echo '<label>Bundesland <select name="state_code" required>' . $this->options($stateOptions, (string) $request['state_code']) . '</select></label>';
            echo '<button class="button button-primary">Speichern &amp; erneut prüfen</button></form></details>';
        }
        echo '</section>';
        return (string) ob_get_clean();
    }

    private function warnings(array $request): string
    {
        $fields = [
            'parking_available' => 'Parkplatz',
            'indoor_room_available' => 'Innenraum',
            'bad_weather_option_available' => 'Schlechtwetter',
            'electricity_available' => 'Strom',
            'water_available' => 'Wasser',
        ];
        $out = '<div class="pov-chip-list">';
        foreach ($fields as $field => $label) {
            $value = (string) $request[$field];
            $out .= '<span class="pov-admin-chip ' . ($value === 'yes' ? 'is-ok' : 'is-warn') . '">' . esc_html($label . ': ' . $this->answerLabel($value)) . '</span>';
        }
        return $out . '</div>';
    }

    private function answerLabel(string $value): string
    {
        return ['yes' => 'Ja', 'no' => 'Nein', 'unknown' => 'Unklar'][$value] ?? 'Unklar';
    }

    private function requestDateLabel(array $request): string
    {
        if (($request['request_mode'] ?? '') === 'specific_date') {
            return 'Fester Tag: ' . mysql2date('d.m.Y', (string) $request['specific_requested_date']);
        }
        return mysql2date('d.m.Y', (string) $request['desired_date_from']) . ' – ' . mysql2date('d.m.Y', (string) $request['desired_date_to']);
    }

    private function dateAllowedForRequest(string $date, array $request): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        if (($request['request_mode'] ?? '') === 'specific_date') {
            return $date === (string) ($request['specific_requested_date'] ?? '');
        }

        $from = (string) ($request['desired_date_from'] ?? '');
        $to = (string) ($request['desired_date_to'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return true;
        }

        return $date >= $from && $date <= $to;
    }

    private function settingsFields(): array
    {
        return [
            'pov_default_start_label' => 'Standardstartpunkt',
            'pov_default_start_latitude' => 'Standardstart Latitude',
            'pov_default_start_longitude' => 'Standardstart Longitude',
            'pov_kilometer_rate' => 'Kilometersatz',
            'pov_personnel_hourly_rate' => 'Personalkosten pro Stunde und Person',
            'pov_personnel_count' => 'Teammitglieder pro Einsatz',
            'pov_default_visit_hours' => 'Einsatzdauer vor Ort in Stunden',
            'pov_average_driving_speed_kmh' => 'Durchschnittsgeschwindigkeit km/h',
            'pov_max_public_suggestion_cost' => 'Max. öffentliche Empfehlungskosten',
            'pov_max_public_suggestion_distance_km' => 'Max. öffentliche Empfehlungsdistanz km',
            'pov_cluster_radius_km' => 'Cluster-Radius km',
            'pov_public_booking_horizon_days' => 'Öffentlicher Buchungshorizont Tage',
            'pov_route_cache_ttl' => 'Routing Cache TTL Sekunden',
            'pov_geocoding_cache_ttl' => 'Geocoding Cache TTL Sekunden',
            'pov_heigit_api_key' => 'HeiGIT API-Schlüssel',
            'pov_routing_base_url' => 'Eigene OSRM Basis-URL',
            'pov_geocoding_base_url' => 'Eigene Nominatim Basis-URL',
            'pov_email_sender_name' => 'Absendername',
            'pov_email_sender_address' => 'Absenderadresse',
            'pov_team_notification_emails' => 'Team-E-Mails (kommagetrennt)',
            'pov_confirmation_email_subject' => 'Eingangsbestätigung Betreff',
            'pov_confirmation_email_body' => 'Eingangsbestätigung Text',
            'pov_proposal_email_subject' => 'Vorschlagsmail Betreff',
            'pov_proposal_email_body' => 'Vorschlagsmail Text',
            'pov_response_accept_template' => 'Vorlage Zusage',
            'pov_response_question_template' => 'Vorlage Rückfrage',
            'pov_response_reject_template' => 'Vorlage Absage',
            'pov_privacy_page_url' => 'Datenschutz URL',
        ];
    }

    private function settingsGroups(): array
    {
        return [
            'Planung & Kosten' => [
                'pov_default_start_label',
                'pov_default_start_latitude',
                'pov_default_start_longitude',
                'pov_kilometer_rate',
                'pov_personnel_hourly_rate',
                'pov_personnel_count',
                'pov_default_visit_hours',
                'pov_average_driving_speed_kmh',
                'pov_max_public_suggestion_cost',
                'pov_max_public_suggestion_distance_km',
                'pov_cluster_radius_km',
                'pov_public_booking_horizon_days',
            ],
            'Routing' => [
                'pov_heigit_api_key',
                'pov_routing_base_url',
                'pov_geocoding_base_url',
                'pov_route_cache_ttl',
                'pov_geocoding_cache_ttl',
            ],
            'E-Mail & Datenschutz' => [
                'pov_email_sender_name',
                'pov_email_sender_address',
                'pov_team_notification_emails',
                'pov_confirmation_email_subject',
                'pov_confirmation_email_body',
                'pov_proposal_email_subject',
                'pov_proposal_email_body',
                'pov_response_accept_template',
                'pov_response_question_template',
                'pov_response_reject_template',
                'pov_privacy_page_url',
            ],
        ];
    }

    private function resolveCalendarStartPoint(): array
    {
        $postalCode = preg_replace('/\D+/', '', (string) ($_POST['custom_start_postal_code'] ?? ''));
        $city = sanitize_text_field((string) ($_POST['custom_start_city'] ?? ''));
        $label = sanitize_text_field((string) ($_POST['custom_start_label'] ?? ''));

        if ($postalCode !== '' || $city !== '') {
            $result = (new ProviderFactory())->geocoding()->geocodeAddress([
                'postal_code' => $postalCode,
                'city' => $city,
            ]);

            if (! empty($result['ok']) && is_numeric($result['latitude'] ?? null) && is_numeric($result['longitude'] ?? null)) {
                return [
                    'label' => $label !== '' ? $label : trim($postalCode . ' ' . $city),
                    'latitude' => (float) $result['latitude'],
                    'longitude' => (float) $result['longitude'],
                ];
            }
        }

        return [
            'label' => $label,
            'latitude' => is_numeric($_POST['custom_start_latitude'] ?? null) ? (float) $_POST['custom_start_latitude'] : null,
            'longitude' => is_numeric($_POST['custom_start_longitude'] ?? null) ? (float) $_POST['custom_start_longitude'] : null,
        ];
    }

    private function options(array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr((string) $value) . '" ' . selected((string) $value, $selected, false) . '>' . esc_html((string) $label) . '</option>';
        }
        return $html;
    }

    private function durationLabel(float $minutes): string
    {
        $minutes = max(0, (int) round($minutes));
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;
        if ($hours === 0) {
            return $remainder . ' Min.';
        }
        return $remainder > 0 ? $hours . ' Std. ' . $remainder . ' Min.' : $hours . ' Std.';
    }

    private function header(string $title): void
    {
        $current = $this->portal
            ? OperationsPortal::pageForView(sanitize_key((string) ($_GET['view'] ?? 'requests')))
            : sanitize_key((string) ($_GET['page'] ?? 'pov-settings'));
        echo '<div class="' . ($this->portal ? '' : 'wrap ') . 'pov-admin"><header class="pov-admin-header"><div class="pov-admin-brand"><img src="' . esc_url(POV_PLUGIN_URL . 'assets/brand/pro-ocean-symbol-blue.svg') . '" alt=""><div><span>Pro Ocean</span><strong>Van Operations</strong></div></div><nav aria-label="Ocean-Van-Bereiche">';
        foreach ([
            'pov-requests' => 'Anfragen',
            'pov-routes' => 'Tourplanung',
            'pov-statistics' => 'Statistik',
            'pov-calendar' => 'Kalender',
        ] as $page => $label) {
            if (! current_user_can(Capabilities::viewForPage($page))) {
                continue;
            }
            echo '<a class="' . ($current === $page ? 'is-current' : '') . '" ' . ($current === $page ? 'aria-current="page" ' : '') . 'href="' . esc_url(OperationsPortal::url($page)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav><div class="pov-admin-usernav">';
        if (current_user_can('manage_options')) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=pov-settings')) . '"' . ($current === 'pov-settings' ? ' aria-current="page" class="is-current"' : '') . '>Einstellungen</a>';
        }
        $user = wp_get_current_user();
        if ($this->portal) {
            echo '<span>' . esc_html($user->display_name) . '</span><a href="' . esc_url(wp_logout_url(home_url('/'))) . '">Abmelden</a>';
        }
        echo '</div></header><div class="pov-admin-title"><div><span class="pov-admin-eyebrow">Ocean Van</span><h1>' . esc_html($title) . '</h1></div></div>';
    }

    private function footer(): void
    {
        echo '</div>';
    }

    private function setupNotice(): void
    {
        $notice = sanitize_key((string) ($_GET['pov_notice'] ?? ''));
        $messages = [
            'saved' => ['success', 'Gespeichert.'],
            'recalculated' => ['success', 'Terminvorschläge aktualisiert.'],
            'suggestion-updated' => ['success', 'Auswahl aktualisiert.'],
            'proposal-sent' => ['success', 'Terminvorschläge versendet.'],
            'proposal-unavailable' => ['warning', 'Bitte zuerst mindestens einen verfügbaren Termin auswählen.'],
            'proposal-failed' => ['error', 'Die E-Mail konnte nicht gesendet werden.'],
            'confirmed' => ['success', 'Termin bestätigt.'],
            'confirm-failed' => ['error', 'Der Termin konnte nicht bestätigt werden.'],
            'date-outside-request' => ['error', 'Der Termin liegt außerhalb des angefragten Zeitraums oder ist gesperrt.'],
            'response-accept' => ['success', 'Zusage gesendet und Termin eingetragen.'],
            'response-question' => ['success', 'Rückfrage gesendet.'],
            'response-reject' => ['success', 'Absage gesendet.'],
            'response-invalid' => ['error', 'Bitte Antwort und Nachricht vollständig ausfüllen.'],
            'response-closed' => ['warning', 'Dieser Vorgang ist bereits abgeschlossen.'],
            'response-failed' => ['error', 'Die Antwort konnte nicht per E-Mail gesendet werden.'],
            'workflow-saved' => ['success', 'Arbeitsstand gespeichert.'],
            'note-saved' => ['success', 'Notiz gespeichert.'],
            'address-geocoded' => ['success', 'Adresse gespeichert. Geoanalyse und Fahrstrecke wurden aktualisiert.'],
            'address-no-route' => ['warning', 'Adresse gespeichert und gefunden. Die Fahrstrecke ist noch offen.'],
            'address-unverified' => ['error', 'Adresse gespeichert, aber nicht gefunden. Angaben korrigieren oder Geoanalyse prüfen.'],
            'address-invalid' => ['error', 'Adresse unvollständig. Bitte Straße, Hausnummer, fünfstellige PLZ, Ort und Bundesland prüfen.'],
            'test-profile' => ['success', 'Testprofil aktiviert.'],
            'geo-ok' => ['success', 'Geoanalyse ist erreichbar: Adresse, Strecke und Fahrzeit wurden geprüft.'],
            'geo-partial' => ['warning', 'Ein Teil der Geoanalyse ist erreichbar. Details stehen im Verbindungstest.'],
            'geo-failed' => ['error', 'Geoanalyse nicht erreichbar. Anbieter, API-Schlüssel und gespeicherte Einstellungen prüfen.'],
        ];
        if (isset($messages[$notice]) && ! isset($_GET['pov_saved_days'])) {
            [$type, $message] = $messages[$notice];
            echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>';
        }

        if ($notice === 'addresses-checked') {
            $checked = max(0, (int) ($_GET['pov_checked'] ?? 0));
            $found = max(0, min($checked, (int) ($_GET['pov_found'] ?? 0)));
            $type = $checked === 0 || $found === $checked ? 'success' : 'warning';
            echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html((string) $found) . ' von ' . esc_html((string) $checked) . ' offenen Adressen gefunden. Nicht gefundene Angaben bitte korrigieren.</p></div>';
        }

        if (in_array($notice, ['address-no-route', 'address-unverified'], true)) {
            $feedbackKey = 'pov_address_feedback_' . get_current_user_id();
            $feedback = (string) get_transient($feedbackKey);
            delete_transient($feedbackKey);
            if ($feedback !== '') {
                echo '<div class="notice notice-warning inline"><p><strong>Technischer Hinweis:</strong> ' . esc_html($feedback) . '</p></div>';
            }
        }

        if (isset($_GET['pov_saved_days'])) {
            $days = max(0, (int) $_GET['pov_saved_days']);
            echo '<div class="notice notice-success inline"><p>' . esc_html((string) $days) . ' Kalendertage gespeichert.</p></div>';
        }

        if (Activation::isTestProfileActive()) {
            echo '<div class="notice notice-info inline"><p>Testmodus aktiv – öffentliche OSM-Dienste sind nicht für den Live-Betrieb gedacht.</p></div>';
            return;
        }

        if (get_option('pov_routing_provider') === 'null' || get_option('pov_geocoding_provider') === 'null') {
            echo '<div class="notice notice-warning inline"><p>Routing oder Geocoding fehlt.</p></div>';
        } elseif ((get_option('pov_routing_provider') === 'heigit' || get_option('pov_geocoding_provider') === 'heigit')
            && (string) get_option('pov_heigit_api_key', '') === '') {
            echo '<div class="notice notice-warning inline"><p>HeiGIT API-Schlüssel fehlt.</p></div>';
        }
    }

    private function geoTestPanel(): string
    {
        $result = get_transient('pov_geo_test_' . get_current_user_id());
        if (! is_array($result)) {
            return '';
        }
        $routingOk = ! empty($result['routing_ok']);
        $geocodingOk = ! empty($result['geocoding_ok']);
        $providerLabels = [
            'heigit' => 'HeiGIT',
            'osrm' => 'OSRM',
            'nominatim' => 'Nominatim',
            'null' => 'Nicht konfiguriert',
        ];
        ob_start();
        echo '<section class="pov-admin-panel pov-geo-result"><strong>Letzter Verbindungstest</strong><div>';
        echo '<article class="' . ($geocodingOk ? 'is-ok' : 'is-error') . '"><span>Adressprüfung · ' . esc_html($providerLabels[(string) ($result['geocoding_provider'] ?? '')] ?? (string) ($result['geocoding_provider'] ?? '')) . '</span><strong>' . ($geocodingOk ? 'Erreichbar' : 'Fehlgeschlagen') . '</strong>';
        if (! $geocodingOk && ! empty($result['geocoding_error'])) {
            echo '<small>' . esc_html((string) $result['geocoding_error']) . '</small>';
        }
        echo '</article>';
        echo '<article class="' . ($routingOk ? 'is-ok' : 'is-error') . '"><span>Straßenrouting · ' . esc_html($providerLabels[(string) ($result['routing_provider'] ?? '')] ?? (string) ($result['routing_provider'] ?? '')) . '</span><strong>' . ($routingOk ? 'Erreichbar' : 'Fehlgeschlagen') . '</strong>';
        if ($routingOk && is_numeric($result['distance'] ?? null) && is_numeric($result['duration'] ?? null)) {
            echo '<small>' . esc_html(number_format((float) $result['distance'], 1, ',', '.') . ' km · ' . $this->durationLabel((float) $result['duration'])) . '</small>';
        } elseif (! empty($result['routing_error'])) {
            echo '<small>' . esc_html((string) $result['routing_error']) . '</small>';
        }
        echo '</article></div></section>';
        return (string) ob_get_clean();
    }

    private function testProfilePanel(): string
    {
        ob_start();
        echo '<details class="pov-admin-panel pov-test-panel"><summary>Testprofil</summary>';
        echo '<ul class="pov-config-list">';
        echo '<li><strong>Start:</strong> ' . esc_html((string) get_option('pov_default_start_label', '')) . '</li>';
        echo '<li><strong>Geocoding:</strong> ' . esc_html((string) get_option('pov_geocoding_provider', '')) . '</li>';
        echo '<li><strong>Routing:</strong> ' . esc_html((string) get_option('pov_routing_provider', '')) . '</li>';
        echo '<li><strong>Kilometersatz:</strong> ' . esc_html((string) get_option('pov_kilometer_rate', '')) . ' €/km</li>';
        echo '</ul>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pov_enable_test_profile');
        echo '<input type="hidden" name="action" value="pov_enable_test_profile">';
        echo '<button class="button button-primary">OSM-Testprofil aktivieren</button>';
        echo '</form>';
        echo '<p class="description">Nur für lokale Tests, nicht für den Live-Betrieb.</p>';
        echo '</details>';
        return (string) ob_get_clean();
    }

    private function pageUrl(string $page, array $args = []): string
    {
        if ($this->portal || $page !== 'pov-settings') {
            return OperationsPortal::url($page, $args);
        }
        return add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php'));
    }

    private function navigationField(string $page): string
    {
        if (! $this->portal) {
            return '<input type="hidden" name="page" value="' . esc_attr($page) . '">';
        }
        $view = OperationsPortal::viewForPage($page);
        return $view === 'requests' ? '' : '<input type="hidden" name="view" value="' . esc_attr($view) . '">';
    }

    private function requireCapability(string $capability): void
    {
        if (! current_user_can($capability)) {
            wp_die('Keine Berechtigung.', 'Zugriff verweigert', ['response' => 403]);
        }
    }

    private function guard(string $nonce, string $capability): void
    {
        $this->requireCapability($capability);
        check_admin_referer($nonce);
    }

    private function redirect(string $page, array $args = []): void
    {
        $notice = isset($args['pov_notice']) ? (string) $args['pov_notice'] : 'saved';
        unset($args['pov_notice']);
        wp_safe_redirect($this->pageUrl($page, array_merge(['pov_notice' => $notice], $args)));
        exit;
    }
}
