<?php

declare(strict_types=1);

namespace ProOceanVan\Admin;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use ProOceanVan\Activation;
use ProOceanVan\Domain\EventType;
use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\SuggestionState;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Portal\OperationsPortal;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\CommunicationRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\StateRepository;
use ProOceanVan\Repository\SuggestionRepository;
use ProOceanVan\Repository\TourExpenseRepository;
use ProOceanVan\Routing\ProviderFactory;
use ProOceanVan\Security\Capabilities;
use ProOceanVan\Service\IcsService;
use ProOceanVan\Service\AttachmentService;
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
        add_action('admin_post_pov_save_request_details', [$this, 'saveRequestDetails']);
        add_action('admin_post_pov_log_communication', [$this, 'logCommunication']);
        add_action('admin_post_pov_save_appointment_outcome', [$this, 'saveAppointmentOutcome']);
        add_action('admin_post_pov_save_tour_expense', [$this, 'saveTourExpense']);
        add_action('admin_post_pov_delete_tour_expense', [$this, 'deleteTourExpense']);
        add_action('admin_post_pov_download_attachment', [$this, 'downloadAttachment']);
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
        $appointment = (new AppointmentRepository())->findForRequest($id);
        $displayDate = $appointment && (string) ($appointment['status'] ?? '') === 'confirmed'
            ? 'Bestätigt: ' . GermanDateFormatter::full((string) $appointment['appointment_date'])
            : $this->requestDateLabel($request);

        $this->header('Anfrage ' . $request['public_uuid']);
        $this->setupNotice();
        echo '<a class="pov-admin-back" href="' . esc_url($this->pageUrl('pov-requests')) . '">← Zurück zu Anfragen</a>';
        echo '<section class="pov-request-hero"><div><span class="pov-admin-eyebrow">' . esc_html($request['public_uuid']) . '</span><h2>' . esc_html($request['institution_name']) . '</h2><p>' . esc_html($request['postal_code'] . ' ' . $request['city']) . ' · ' . esc_html($displayDate) . '</p></div>';
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
        if (current_user_can(Capabilities::SEND_RESPONSES)
            && $bestSuggestions
            && ! in_array((string) ($request['work_state'] ?? ''), [WorkState::ACCEPTED, WorkState::REJECTED, WorkState::CANCELLED], true)) {
            echo '<details class="pov-proposal-form"><summary>Termine per E-Mail anbieten</summary>' . $this->proposalForm($request, $bestSuggestions) . '</details>';
        }
        echo '</section>';

        if (current_user_can(Capabilities::SEND_RESPONSES)) {
            echo '<section class="pov-admin-panel pov-response-panel"><div class="pov-panel-heading"><div><span class="pov-admin-eyebrow">Kommunikation</span><h2>Nachricht senden</h2></div></div>' . $this->responseForm($request, $bestSuggestions, $appointment) . '</section>';
        }
        echo $this->communicationHub($request);
        echo '</main><aside>';

        if (current_user_can(Capabilities::MANAGE_REQUESTS)) {
            echo $this->requestEditor($request, $appointment);
        }
        echo $this->addressPanel($request);

        $breakdownKnown = (int) ($request['children_count'] ?? 0) + (int) ($request['adult_count'] ?? 0) > 0;
        echo $this->requestPlanningPanel($request, $breakdownKnown);
        if ($appointment && (string) ($appointment['status'] ?? '') === 'confirmed') {
            echo $this->appointmentOutcomePanel($appointment);
        }

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
        echo '<div class="pov-admin-calendar-legend"><span>Buchbar</span><span>Auf Anfrage</span><span>Walk-in-Event</span><span>Nicht buchbar</span><span>Bestätigter Termin</span><span>Offene Anfrage</span></div>';
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
                echo '<label>Status <select name="availability_state" data-pov-calendar-state><option value="available">Buchbar</option><option value="limited">Auf Anfrage</option><option value="walk_in">Walk-in-Event</option><option value="unavailable">Nicht buchbar</option></select></label>';
                echo '<label>Öffentliche Notiz <input name="public_note"></label><input type="hidden" name="public_event_group">';
                echo '<div class="pov-walk-in-fields" data-pov-walk-in-fields hidden><label>Titel <input name="public_title" placeholder="z. B. Meerestag in Stuttgart"></label>';
                echo '<label>Beschreibung <textarea name="public_description" placeholder="Was erwartet die Besucherinnen und Besucher?"></textarea></label>';
                echo '<label>Ort <input name="public_location" placeholder="Veranstaltungsort"></label>';
                echo '<label>Link <input type="url" name="public_url" placeholder="https://…"></label>';
                echo '<div class="pov-admin-two"><label>Erreichte Kinder (gesamter Zeitraum) <input type="number" min="0" name="walk_in_children"></label><label>Erreichte Erwachsene (gesamter Zeitraum) <input type="number" min="0" name="walk_in_adults"></label></div></div>';
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
        $eventTypes = (array) ($report['event_types'] ?? []);
        $maxCost = $rows ? max(1.0, ...array_map(static fn (array $row): float => (float) $row['total_cost'], $rows)) : 1.0;

        $this->header('Statistik');
        $this->setupNotice();
        echo '<nav class="pov-period-switch" aria-label="Zeitraum">';
        foreach (['week' => 'KW', 'month' => 'Monat', 'year' => 'Jahr'] as $value => $label) {
            echo '<a class="button' . ($report['period'] === $value ? ' button-primary' : '') . '" href="' . esc_url($this->pageUrl('pov-statistics', ['period' => $value])) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        echo '<div class="pov-route-overview pov-stat-overview"><div><span>Personen gesamt</span><strong>' . esc_html(number_format((int) $totals['participants_total'], 0, ',', '.')) . '</strong><small>' . esc_html((string) $totals['participants_children']) . ' Kinder · ' . esc_html((string) $totals['participants_adults']) . ' Erwachsene</small></div><div><span>Fahrstrecke</span><strong>' . esc_html(number_format((float) $totals['distance_km'], 0, ',', '.') . ' km') . '</strong></div><div><span>Fahrtkosten</span><strong>' . esc_html(number_format((float) $totals['route_cost'], 2, ',', '.') . ' €') . '</strong></div><div><span>Personalkosten</span><strong>' . esc_html(number_format((float) $totals['personnel_cost'], 2, ',', '.') . ' €') . '</strong></div><div><span>Übernachtung</span><strong>' . esc_html(number_format((float) $totals['overnight_cost'], 2, ',', '.') . ' €') . '</strong></div><div><span>Gesamtkosten</span><strong>' . esc_html(number_format((float) $totals['total_cost'], 2, ',', '.') . ' €') . '</strong></div></div>';

        echo '<section class="pov-admin-panel pov-stat-panel">';
        if (! $rows) {
            echo '<div class="pov-admin-empty"><strong>Noch keine bestätigten Einsätze.</strong></div>';
        } else {
            echo '<div class="pov-panel-heading"><div><span class="pov-admin-eyebrow">Kostenverlauf</span><h2>Zeiträume im Vergleich</h2></div></div>';
            echo '<div class="pov-stat-chart" aria-label="Kostenverlauf">';
            foreach ($rows as $row) {
                $width = max(2.0, ((float) $row['total_cost'] / $maxCost) * 100);
                echo '<div class="pov-stat-bar"><span>' . esc_html((string) $row['label']) . '</span><i style="--pov-bar:' . esc_attr(number_format($width, 2, '.', '')) . '%"></i><strong>' . esc_html(number_format((float) $row['total_cost'], 0, ',', '.') . ' €') . '</strong></div>';
            }
            echo '</div><div class="pov-stat-periods">';
            foreach ($rows as $row) {
                $typeLabels = [];
                foreach ((array) ($row['event_types'] ?? []) as $type => $count) {
                    $typeLabels[] = (EventType::labels()[$type] ?? EventType::labels()[EventType::OTHER]) . ' ' . $count;
                }
                $people = (int) $row['participants_children'] . ' Kinder · ' . (int) $row['participants_adults'] . ' Erwachsene';
                if ((int) ($row['participants_missing'] ?? 0) > 0) {
                    $people .= ' · ' . (int) $row['participants_missing'] . ' offen';
                }
                echo '<article class="pov-stat-period"><header><div><span>Zeitraum</span><strong>' . esc_html((string) $row['label']) . '</strong></div><div class="pov-stat-period-total"><span>Gesamtkosten</span><strong>' . esc_html(number_format((float) $row['total_cost'], 2, ',', '.') . ' €') . '</strong></div></header>';
                echo '<div class="pov-stat-period-grid"><div><span>Einsätze</span><strong>' . esc_html((string) $row['appointments']) . '</strong><small>' . esc_html($typeLabels ? implode(' · ', $typeLabels) : 'Keine Art erfasst') . '</small></div>';
                echo '<div><span>Personen</span><strong>' . esc_html(number_format((int) $row['participants_total'], 0, ',', '.')) . '</strong><small>' . esc_html($people) . '</small></div>';
                echo '<div><span>Mobilität</span><strong>' . esc_html(number_format((float) $row['distance_km'], 0, ',', '.') . ' km') . '</strong><small>' . esc_html($this->durationLabel((float) $row['travel_minutes'])) . '</small></div>';
                echo '<dl class="pov-stat-costs"><div><dt>Fahrt</dt><dd>' . esc_html(number_format((float) $row['route_cost'], 2, ',', '.') . ' €') . '</dd></div><div><dt>Personal</dt><dd>' . esc_html(number_format((float) $row['personnel_cost'], 2, ',', '.') . ' €') . '</dd></div><div><dt>Übernachtung</dt><dd>' . esc_html(number_format((float) $row['overnight_cost'], 2, ',', '.') . ' €') . '</dd></div></dl></div></article>';
            }
            echo '</div>';
        }
        echo '<p class="description">Bestätigte Einsätze · Personal inklusive Einsatz- und Fahrzeit.</p></section>';
        echo '<section class="pov-admin-panel"><h2>Veranstaltungsarten</h2><div class="pov-table-scroll"><table class="pov-compact-table"><thead><tr><th>Art</th><th>Einsätze</th><th>Kinder</th><th>Erwachsene</th><th>Gesamt</th><th>Noch offen</th></tr></thead><tbody>';
        foreach ($eventTypes as $type) {
            echo '<tr><td><strong>' . esc_html((string) $type['label']) . '</strong></td><td>' . esc_html((string) $type['appointments']) . '</td><td>' . esc_html((string) $type['participants_children']) . '</td><td>' . esc_html((string) $type['participants_adults']) . '</td><td>' . esc_html((string) $type['participants_total']) . '</td><td>' . esc_html((string) $type['participants_missing']) . '</td></tr>';
        }
        if (! $eventTypes) {
            echo '<tr><td colspan="6">Noch keine Einsatzdaten.</td></tr>';
        }
        echo '</tbody></table></div></section>';
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
        $expenseRepository = new TourExpenseRepository();
        foreach ($clusters as $cluster) {
            $stops = array_values(array_filter((array) ($cluster['stops'] ?? []), static fn (array $stop): bool => is_numeric($stop['latitude'] ?? null) && is_numeric($stop['longitude'] ?? null)));
            $legs = (array) ($cluster['legs'] ?? []);
            $overnights = [];
            foreach ((array) ($cluster['overnights'] ?? []) as $overnight) {
                $overnights[(int) $overnight['after_stop']][] = $overnight;
            }
            $clusterRequestIds = [];
            $clusterAppointmentIds = [];
            foreach ($stops as $clusterStop) {
                if (($clusterStop['_stop_type'] ?? '') === 'confirmed') {
                    $clusterAppointmentIds[] = (int) ($clusterStop['id'] ?? 0);
                    $clusterRequestIds[] = (int) ($clusterStop['request_id'] ?? 0);
                } else {
                    $clusterRequestIds[] = (int) ($clusterStop['id'] ?? 0);
                }
            }
            $clusterRequestIds = array_values(array_filter($clusterRequestIds));
            $clusterAppointmentIds = array_values(array_filter($clusterAppointmentIds));
            $savedExpenses = array_values(array_filter(
                $expenseRepository->forRange((string) $cluster['week_start'], (string) $cluster['week_end']),
                static fn (array $expense): bool => in_array((int) ($expense['request_id'] ?? 0), $clusterRequestIds, true)
                    || in_array((int) ($expense['appointment_id'] ?? 0), $clusterAppointmentIds, true)
            ));
            $overnightCost = array_sum(array_map(static fn (array $expense): float => (float) $expense['amount'], $savedExpenses));
            $stopSummary = (int) $cluster['request_count'] . ' offen';
            if ((int) ($cluster['confirmed_count'] ?? 0) > 0) {
                $stopSummary .= ' · ' . (int) $cluster['confirmed_count'] . ' bestätigt';
            }
            $source = ['heigit' => 'HeiGIT-Straßenmatrix', 'osrm' => 'Straßenmatrix', 'estimated' => 'Geschätzte Fahrzeit'][(string) $cluster['matrix_source']] ?? 'Routendaten';
            $tourMapsUrl = $this->tourPoisUrl($stops);
            echo '<article class="pov-route-cluster' . ($cluster['priority'] === 'recommended' ? ' is-recommended' : '') . '"><header><div><span class="pov-admin-eyebrow">' . esc_html(GermanDateFormatter::short((string) $cluster['week_start']) . ' – ' . GermanDateFormatter::short((string) $cluster['week_end'])) . '</span><h2>' . esc_html((string) $cluster['title']) . '</h2><p>' . esc_html($stopSummary . ' · Start ' . (string) $cluster['start_label']) . '</p></div><div class="pov-route-cluster-actions"><a class="button" href="' . esc_url($tourMapsUrl) . '" target="_blank" rel="noopener">Tourstopps als POIs in Google Maps</a><span class="pov-route-badge">' . ($cluster['priority'] === 'recommended' ? 'Empfohlen' : 'Entwurf') . '</span></div></header>';
            echo '<div class="pov-route-metrics"><div><span>Tourstrecke</span><strong>' . esc_html(number_format((float) $cluster['route_distance_km'], 0, ',', '.') . ' km') . '</strong><small>' . esc_html($source) . '</small></div><div><span>Fahrzeit</span><strong>' . esc_html($this->durationLabel((float) $cluster['route_duration_minutes'])) . '</strong><small>inklusive Rückfahrt</small></div><div><span>Fahrtkosten</span><strong>' . esc_html(number_format((float) $cluster['estimated_cost'], 2, ',', '.') . ' €') . '</strong></div><div><span>Übernachtung</span><strong>' . esc_html(number_format($overnightCost, 2, ',', '.') . ' €') . '</strong><small>Tour gesamt ' . esc_html(number_format((float) $cluster['estimated_cost'] + $overnightCost, 2, ',', '.') . ' €') . '</small></div></div>';
            echo '<div class="pov-tour-timeline"><div class="pov-tour-depot"><span>S</span><div><small>Start</small><strong>' . esc_html((string) $cluster['start_label']) . '</strong></div></div>';
            foreach ($stops as $index => $stop) {
                $leg = (array) ($legs[$index] ?? []);
                echo '<div class="pov-tour-leg"><span>Fahrt</span><strong>' . esc_html(number_format((float) ($leg['distance_km'] ?? 0), 0, ',', '.') . ' km · ' . $this->durationLabel((float) ($leg['duration_minutes'] ?? 0))) . '</strong></div>';
                $confirmed = ($stop['_stop_type'] ?? '') === 'confirmed';
                $date = (string) ($stop['_planning_date'] ?? $stop['appointment_date'] ?? '');
                $requestId = $confirmed ? (int) ($stop['request_id'] ?? 0) : (int) ($stop['id'] ?? 0);
                $dateType = $confirmed ? 'Bestätigt' : (! empty($stop['_optimized_date']) ? 'Routenempfehlung' : 'Anfrage');
                echo '<div class="pov-tour-stop' . ($confirmed ? ' is-confirmed' : '') . '"><time datetime="' . esc_attr($date) . '"><strong>' . esc_html(GermanDateFormatter::weekdayShort($date)) . '</strong><span>' . esc_html(mysql2date('d.m.', $date)) . '</span></time><div><small>' . esc_html($dateType) . '</small><strong>' . esc_html((string) $stop['institution_name']) . '</strong><span>' . esc_html((string) $stop['postal_code'] . ' ' . (string) $stop['city']) . '</span></div>';
                if ($requestId > 0 && current_user_can(Capabilities::VIEW_REQUESTS)) {
                    echo '<div class="pov-tour-stop-actions"><a class="button" href="' . esc_url($this->pageUrl('pov-requests', ['request_id' => $requestId])) . '">Öffnen</a></div>';
                }
                echo '</div>';
                foreach ((array) ($overnights[$index] ?? []) as $overnight) {
                    echo '<div class="pov-tour-overnight"><span aria-hidden="true">Zzz</span><div><small>Übernachtungsregion</small><strong>' . esc_html((string) $overnight['place']) . '</strong><em>' . esc_html((string) $overnight['reason']) . '</em></div>';
                    if (current_user_can(Capabilities::MANAGE_TOURS)) {
                        echo $this->tourExpenseForm($stop, $overnight);
                    }
                    echo '</div>';
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
        if (current_user_can(Capabilities::MANAGE_TOURS)) {
            echo $this->tourExpensesPanel($expenseRepository->all());
        }
        $this->routeIssues($issues);
        $this->footer();
    }

    private function tourPoisUrl(array $stops): string
    {
        $pois = [];
        foreach ($stops as $stop) {
            $address = trim((string) ($stop['street'] ?? '') . ' ' . (string) ($stop['house_number'] ?? ''));
            $place = trim((string) ($stop['postal_code'] ?? '') . ' ' . (string) ($stop['city'] ?? ''));
            $label = trim((string) ($stop['institution_name'] ?? ''));
            $pois[] = trim($label . ($label !== '' && ($address !== '' || $place !== '') ? ', ' : '') . $address . ($address !== '' && $place !== '' ? ', ' : '') . $place);
        }
        $pois = array_values(array_filter($pois));

        return add_query_arg([
            'api' => '1',
            'query' => implode(' | ', $pois),
        ], 'https://www.google.com/maps/search/');
    }

    private function tourExpensesPanel(array $expenses): string
    {
        if (! $expenses) {
            return '';
        }

        ob_start();
        echo '<section class="pov-admin-panel pov-all-expenses"><span class="pov-admin-eyebrow">Kosten</span><h2>Alle Übernachtungskosten</h2>';
        echo '<div class="pov-saved-expenses">';
        foreach ($expenses as $expense) {
            echo $this->tourExpenseForm([], [], $expense);
        }
        echo '</div>';
        return (string) ob_get_clean() . '</section>';
    }

    private function tourExpenseForm(array $stop, array $defaults = [], array $expense = []): string
    {
        $confirmed = ($stop['_stop_type'] ?? '') === 'confirmed';
        $requestId = (int) ($expense['request_id'] ?? ($confirmed ? ($stop['request_id'] ?? 0) : ($stop['id'] ?? 0)));
        $appointmentId = (int) ($expense['appointment_id'] ?? ($confirmed ? ($stop['id'] ?? 0) : 0));
        $date = (string) ($expense['expense_date'] ?? $defaults['date'] ?? $stop['_planning_date'] ?? $stop['appointment_date'] ?? '');
        $place = (string) ($expense['place'] ?? $defaults['place'] ?? $stop['city'] ?? '');
        ob_start();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-tour-expense-form">';
        wp_nonce_field('pov_save_tour_expense');
        echo '<input type="hidden" name="action" value="pov_save_tour_expense"><input type="hidden" name="id" value="' . esc_attr((string) ($expense['id'] ?? 0)) . '"><input type="hidden" name="request_id" value="' . esc_attr((string) $requestId) . '"><input type="hidden" name="appointment_id" value="' . esc_attr((string) $appointmentId) . '">';
        echo '<label>Datum <input type="date" name="expense_date" value="' . esc_attr($date) . '" required></label><label>Ort <input name="place" value="' . esc_attr($place) . '"></label>';
        echo '<label>Kosten <input type="number" min="0" step="0.01" name="amount" value="' . esc_attr((string) ($expense['amount'] ?? '')) . '" required></label><label>Notiz <input name="note" value="' . esc_attr((string) ($expense['note'] ?? '')) . '"></label>';
        echo '<button class="button">' . (! empty($expense['id']) ? 'Aktualisieren' : 'Kosten speichern') . '</button></form>';
        if (! empty($expense['id'])) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-inline-delete">';
            wp_nonce_field('pov_delete_tour_expense');
            echo '<input type="hidden" name="action" value="pov_delete_tour_expense"><input type="hidden" name="id" value="' . esc_attr((string) $expense['id']) . '"><button class="button button-link-delete">Entfernen</button></form>';
        }
        return (string) ob_get_clean();
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
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form" data-pov-settings-form>';
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
            if ($group === 'E-Mail & Datenschutz') {
                echo $this->defaultAttachmentSettings();
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
        $attachments = new AttachmentService();
        $attachmentError = false;
        foreach (['confirmation', 'proposal', 'accept', 'question', 'reject'] as $type) {
            $newIds = $attachments->handleUploads($type . '_attachments');
            if ($attachments->hasErrors()) {
                $attachmentError = true;
                continue;
            }
            $attachments->updateOption(
                'pov_' . $type . '_attachment_ids',
                $newIds,
                (array) ($_POST['remove_' . $type . '_attachment_ids'] ?? [])
            );
        }
        if ($attachmentError) {
            $this->redirect('pov-settings', ['pov_notice' => 'attachment-invalid']);
        }
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
            $start,
            [
                'title' => (string) ($_POST['public_title'] ?? ''),
                'description' => (string) ($_POST['public_description'] ?? ''),
                'location' => (string) ($_POST['public_location'] ?? ''),
                'url' => (string) ($_POST['public_url'] ?? ''),
                'group' => (string) ($_POST['public_event_group'] ?? ''),
                'participants_children' => (string) ($_POST['walk_in_children'] ?? ''),
                'participants_adults' => (string) ($_POST['walk_in_adults'] ?? ''),
            ]
        );
        if ($count <= 0) {
            $this->redirect('pov-calendar', ['pov_notice' => 'calendar-invalid']);
        }
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
        $repository = new SuggestionRepository();
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['suggestion_ids'] ?? [])))));
        $suggestions = array_values(array_filter(
            $repository->forRequest($id),
            static fn (array $suggestion): bool => in_array((int) ($suggestion['id'] ?? 0), $selectedIds, true)
                && ! in_array((string) ($suggestion['state'] ?? ''), [SuggestionState::REJECTED, SuggestionState::EXPIRED], true)
        ));
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
        foreach ($suggestions as $suggestion) {
            $repository->setState((int) $suggestion['id'], SuggestionState::ACCEPTED);
        }
        $attachments = new AttachmentService();
        $attachmentIds = $attachments->handleUploads('attachments');
        if ($attachments->hasErrors()) {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'attachment-invalid']);
        }
        if (! (new MailService())->sendProposal($request, $suggestions, (string) ($_POST['subject'] ?? ''), (string) ($_POST['message'] ?? ''), $attachmentIds)) {
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
        $workState = (string) ($request['work_state'] ?? '');
        $allowedTypes = $workState === WorkState::ACCEPTED
            ? ['accept', 'message']
            : (in_array($workState, [WorkState::REJECTED, WorkState::CANCELLED], true)
                ? ['message']
                : ['accept', 'question', 'reject', 'message']);

        if (! $request || ! in_array($type, $allowedTypes, true) || $message === '') {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'response-invalid']);
        }

        $date = '';
        $appointments = null;
        $activeAppointment = null;
        $acceptanceRetry = $type === 'accept' && $workState === WorkState::ACCEPTED;
        if ($type === 'accept') {
            $appointments = new AppointmentRepository();
            $existingAppointment = $appointments->findForRequest($id);
            $activeAppointment = $existingAppointment && (string) ($existingAppointment['status'] ?? '') === 'confirmed'
                ? $existingAppointment
                : null;
            $date = $acceptanceRetry && $activeAppointment
                ? (string) $activeAppointment['appointment_date']
                : sanitize_text_field((string) ($_POST['appointment_date'] ?? ''));
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (! $parsedDate
                || $parsedDate->format('Y-m-d') !== $date
                || ($acceptanceRetry && ! $activeAppointment)
                || (! $acceptanceRetry && (
                    ! $this->dateAllowedForRequest($date, $request)
                    || (int) $parsedDate->format('N') >= 6
                    || (new CalendarDayRepository())->isBlocked($date)
                    || $appointments->existsOnDate($date, (int) ($activeAppointment['id'] ?? 0))
                ))) {
                $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'date-outside-request']);
            }
        }

        $attachments = new AttachmentService();
        $attachmentIds = $attachments->handleUploads('attachments');
        if ($attachments->hasErrors()) {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'attachment-invalid']);
        }

        if ($type === 'accept' && ! $acceptanceRetry && $appointments instanceof AppointmentRepository) {
            if ($activeAppointment && $date !== (string) $activeAppointment['appointment_date']) {
                if (! $appointments->reschedule((int) $activeAppointment['id'], $date)) {
                    $attachments->discard($attachmentIds);
                    $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'confirm-failed']);
                }
            } elseif (! $activeAppointment) {
                $created = $appointments->createFromRequest($request, $date, (string) $request['city'], [
                    'start_label' => (string) get_option('pov_default_start_label', ''),
                    'start_latitude' => is_numeric(get_option('pov_default_start_latitude')) ? (float) get_option('pov_default_start_latitude') : null,
                    'start_longitude' => is_numeric(get_option('pov_default_start_longitude')) ? (float) get_option('pov_default_start_longitude') : null,
                    'distance_km' => is_numeric($request['route_distance_km'] ?? null) ? (float) $request['route_distance_km'] : null,
                    'cost' => is_numeric($request['route_cost'] ?? null) ? (float) $request['route_cost'] : null,
                ]);
                if ($created <= 0) {
                    $attachments->discard($attachmentIds);
                    $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'confirm-failed']);
                }
            }
        }

        if ($type === 'accept') {
            $requestRepository->updateStatus($id, RequestStatus::CONFIRMED, WorkState::ACCEPTED, ['confirmed_at' => current_time('mysql')]);
        }
        if (! (new MailService())->sendResponse(
            $request,
            $type,
            $message,
            $date,
            sanitize_text_field((string) ($_POST['subject'] ?? '')),
            $attachmentIds
        )) {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'response-failed']);
        }

        if ($type === 'question') {
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

    public function logCommunication(): void
    {
        $this->guard('pov_log_communication', Capabilities::MANAGE_REQUESTS);
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $message = sanitize_textarea_field((string) wp_unslash($_POST['message'] ?? ''));
        if (! (new RequestRepository())->find($requestId) || $message === '') {
            $this->redirect('pov-requests', ['request_id' => $requestId, 'pov_notice' => 'communication-invalid']);
        }
        $attachments = new AttachmentService();
        $attachmentIds = $attachments->handleUploads('attachments');
        if ($attachments->hasErrors()) {
            $this->redirect('pov-requests', ['request_id' => $requestId, 'pov_notice' => 'attachment-invalid']);
        }
        (new CommunicationRepository())->record($requestId, [
            'direction' => 'incoming',
            'communication_type' => sanitize_key((string) ($_POST['communication_type'] ?? 'reply')),
            'subject' => (string) ($_POST['subject'] ?? ''),
            'message' => $message,
            'sender_name' => (string) ($_POST['sender_name'] ?? ''),
            'recipient' => 'Pro Ocean Team',
            'attachment_ids' => $attachmentIds,
            'delivery_status' => 'received',
        ]);
        $this->redirect('pov-requests', ['request_id' => $requestId, 'pov_notice' => 'communication-saved']);
    }

    public function saveRequestDetails(): void
    {
        $this->guard('pov_save_request_details', Capabilities::MANAGE_REQUESTS);
        $id = (int) ($_POST['request_id'] ?? 0);
        $repository = new RequestRepository();
        $before = $repository->find($id);
        $appointments = new AppointmentRepository();
        $appointment = $appointments->findForRequest($id);
        $cancel = ! empty($_POST['cancel_appointment']);
        $reactivate = ! empty($_POST['reactivate_appointment']);
        $date = sanitize_text_field((string) ($_POST['appointment_date'] ?? ''));
        $appointmentConfirmed = $appointment && (string) ($appointment['status'] ?? '') === 'confirmed';
        $shouldReschedule = $appointment
            && ! $cancel
            && ($appointmentConfirmed || $reactivate)
            && ($date !== (string) ($appointment['appointment_date'] ?? '') || ! $appointmentConfirmed);

        if (! $before) {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'details-invalid']);
        }
        if ($shouldReschedule) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (! $parsed
                || $parsed->format('Y-m-d') !== $date
                || $date < current_time('Y-m-d')
                || (new CalendarDayRepository())->isBlocked($date)
                || $appointments->existsOnDate($date, (int) $appointment['id'])) {
                $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'appointment-conflict']);
            }
        }

        $persist = function () use (
            $id,
            $repository,
            $appointments,
            $appointment,
            $appointmentConfirmed,
            $cancel,
            $reactivate,
            $shouldReschedule,
            $date
        ): array {
            if ($shouldReschedule
                && ((new CalendarDayRepository())->isBlocked($date)
                    || $appointments->existsOnDate($date, (int) $appointment['id']))) {
                return ['notice' => 'appointment-conflict'];
            }

            global $wpdb;
            $wpdb->query('START TRANSACTION');
            if (! $repository->updateDetails($id, (array) wp_unslash($_POST))) {
                $wpdb->query('ROLLBACK');
                return ['notice' => 'details-invalid'];
            }
            $updated = $repository->find($id);
            if (! $updated) {
                $wpdb->query('ROLLBACK');
                return ['notice' => 'details-invalid'];
            }

            $change = 'Anfragedaten aktualisiert.';
            if ($appointment) {
                $appointments->syncAddressFromRequest($updated);
                if ($cancel) {
                    $reason = sanitize_textarea_field((string) wp_unslash($_POST['cancellation_reason'] ?? ''));
                    if (! $appointments->cancel((int) $appointment['id'], $reason)) {
                        $wpdb->query('ROLLBACK');
                        return ['notice' => 'details-invalid'];
                    }
                    $repository->updateStatus($id, (string) $updated['main_status'], WorkState::CANCELLED, ['closed_at' => current_time('mysql')]);
                    $change = 'Termin storniert' . ($reason !== '' ? ': ' . $reason : '.');
                } elseif ($shouldReschedule) {
                    if (! $appointments->reschedule((int) $appointment['id'], $date)) {
                        $wpdb->query('ROLLBACK');
                        return ['notice' => 'appointment-conflict'];
                    }
                    if ($appointmentConfirmed) {
                        $change = 'Termin verschoben: ' . GermanDateFormatter::full((string) $appointment['appointment_date'])
                            . ' → ' . GermanDateFormatter::full($date) . '.';
                    } else {
                        $repository->updateStatus($id, RequestStatus::CONFIRMED, WorkState::ACCEPTED, [
                            'confirmed_at' => current_time('mysql'),
                            'closed_at' => null,
                        ]);
                        $change = 'Termin reaktiviert: ' . GermanDateFormatter::full($date) . '.';
                    }
                }
            }
            $wpdb->query('COMMIT');
            return [
                'notice' => 'details-saved',
                'updated' => $updated,
                'change' => $change,
            ];
        };

        $lockDate = $appointment && ($cancel || $shouldReschedule)
            ? ($shouldReschedule ? $date : (string) ($appointment['appointment_date'] ?? ''))
            : '';
        $result = $lockDate !== ''
            ? $appointments->withPlanningLocks($id, $lockDate, $persist)
            : $persist();
        if (! is_array($result)) {
            $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'appointment-conflict']);
        }
        if (($result['notice'] ?? '') !== 'details-saved') {
            $this->redirect('pov-requests', [
                'request_id' => $id,
                'pov_notice' => (string) ($result['notice'] ?? 'details-invalid'),
            ]);
        }

        $updated = (array) ($result['updated'] ?? []);
        $change = (string) ($result['change'] ?? 'Anfragedaten aktualisiert.');
        if (! $appointment && ! in_array((string) ($updated['work_state'] ?? ''), [WorkState::ACCEPTED, WorkState::REJECTED, WorkState::CANCELLED], true)) {
            (new InternalSuggestionService())->recalculate($id);
        }
        (new CommunicationRepository())->record($id, [
            'direction' => 'system',
            'communication_type' => 'details_updated',
            'subject' => 'Planung geändert',
            'message' => $change,
            'sender_name' => wp_get_current_user()->display_name,
            'delivery_status' => 'recorded',
        ]);
        $this->redirect('pov-requests', ['request_id' => $id, 'pov_notice' => 'details-saved']);
    }

    public function saveAppointmentOutcome(): void
    {
        $this->guard('pov_save_appointment_outcome', Capabilities::MANAGE_REQUESTS);
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $appointmentRepository = new AppointmentRepository();
        $appointment = $appointmentRepository->find($appointmentId);
        $requestId = (int) ($appointment['request_id'] ?? 0);
        $children = trim((string) ($_POST['participants_children'] ?? '')) === '' ? null : max(0, (int) $_POST['participants_children']);
        $adults = trim((string) ($_POST['participants_adults'] ?? '')) === '' ? null : max(0, (int) $_POST['participants_adults']);
        $eventType = sanitize_key((string) ($_POST['event_type'] ?? EventType::OTHER));
        if ($requestId <= 0 || ! $appointmentRepository->updateOutcome($appointmentId, $eventType, $children, $adults)) {
            $this->redirect('pov-requests', ['request_id' => $requestId, 'pov_notice' => 'outcome-invalid']);
        }
        (new CommunicationRepository())->record($requestId, [
            'direction' => 'system',
            'communication_type' => 'outcome_updated',
            'subject' => 'Einsatzdaten aktualisiert',
            'message' => 'Erreichte Personen: ' . (($children ?? 0) + ($adults ?? 0)) . '.',
            'sender_name' => wp_get_current_user()->display_name,
            'delivery_status' => 'recorded',
        ]);
        $this->redirect('pov-requests', ['request_id' => $requestId, 'pov_notice' => 'outcome-saved']);
    }

    public function saveTourExpense(): void
    {
        $this->guard('pov_save_tour_expense', Capabilities::MANAGE_TOURS);
        $id = (new TourExpenseRepository())->save((array) wp_unslash($_POST));
        $this->redirect('pov-routes', ['pov_notice' => $id > 0 ? 'expense-saved' : 'expense-invalid']);
    }

    public function deleteTourExpense(): void
    {
        $this->guard('pov_delete_tour_expense', Capabilities::MANAGE_TOURS);
        (new TourExpenseRepository())->delete((int) ($_POST['id'] ?? 0));
        $this->redirect('pov-routes', ['pov_notice' => 'expense-deleted']);
    }

    public function downloadAttachment(): void
    {
        $id = (int) ($_GET['attachment_id'] ?? 0);
        if ($id <= 0
            || (! current_user_can(Capabilities::VIEW_REQUESTS) && ! current_user_can('manage_options'))
            || ! check_admin_referer('pov_download_attachment_' . $id)
            || ! $this->isKnownAttachment($id)) {
            wp_die('Datei nicht verfügbar.', 'Datei nicht verfügbar', ['response' => 403]);
        }
        $path = (new AttachmentService())->path($id);
        if ($path === '' || ! is_readable($path)) {
            wp_die('Datei nicht gefunden.', 'Datei nicht gefunden', ['response' => 404]);
        }
        $name = sanitize_file_name(get_the_title($id) ?: basename($path));
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension !== '' && ! str_ends_with(strtolower($name), '.' . strtolower($extension))) {
            $name .= '.' . $extension;
        }
        nocache_headers();
        header('Content-Type: ' . (get_post_mime_type($id) ?: 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
        readfile($path);
        exit;
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
            if (isset($event['appointment']) && $state !== 'walk_in') {
                $state = 'confirmed';
            }
            $classes = 'pov-admin-calendar-day is-' . sanitize_html_class($state);
            $html .= $editable
                ? '<button type="button" class="' . esc_attr($classes) . '" data-pov-calendar-day data-date="' . esc_attr($date) . '" data-state="' . esc_attr((string) ($event['day']['availability_state'] ?? 'available')) . '" data-public-note="' . esc_attr((string) ($event['day']['public_note'] ?? '')) . '" data-public-title="' . esc_attr((string) ($event['day']['public_title'] ?? '')) . '" data-public-description="' . esc_attr((string) ($event['day']['public_description'] ?? '')) . '" data-public-location="' . esc_attr((string) ($event['day']['public_location'] ?? '')) . '" data-public-url="' . esc_attr((string) ($event['day']['public_url'] ?? '')) . '" data-public-event-group="' . esc_attr((string) ($event['day']['public_event_group'] ?? '')) . '" data-walk-in-children="' . esc_attr((string) ($event['day']['participants_children'] ?? '')) . '" data-walk-in-adults="' . esc_attr((string) ($event['day']['participants_adults'] ?? '')) . '" data-internal-note="' . esc_attr((string) ($event['day']['internal_note'] ?? '')) . '" data-start-label="' . esc_attr((string) ($event['day']['custom_start_label'] ?? '')) . '" data-start-lat="' . esc_attr((string) ($event['day']['custom_start_latitude'] ?? '')) . '" data-start-lon="' . esc_attr((string) ($event['day']['custom_start_longitude'] ?? '')) . '">'
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
            'walk_in' => 'Walk-in: ' . (string) ($event['day']['public_title'] ?? 'Vorbeikommen'),
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
        return 0;
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

    private function responseForm(array $request, array $suggestions, ?array $appointment = null): string
    {
        $accepted = (string) ($request['work_state'] ?? '') === WorkState::ACCEPTED;
        $value = $accepted && $appointment && (string) ($appointment['status'] ?? '') === 'confirmed'
            ? (string) ($appointment['appointment_date'] ?? '')
            : (string) ($suggestions[0]['suggestion_date'] ?? $request['specific_requested_date'] ?? $request['desired_date_from'] ?? '');
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
            'message' => 'vielen Dank für deine Nachricht. Wir melden uns mit diesem Zwischenstand:',
        ];
        $closed = in_array((string) ($request['work_state'] ?? ''), [WorkState::REJECTED, WorkState::CANCELLED], true);
        $defaultType = $closed ? 'message' : 'accept';
        $choices = $closed
            ? ['message' => 'Nachricht']
            : ($accepted
                ? ['accept' => 'Zusage erneut senden', 'message' => 'Nachricht']
                : ['accept' => 'Zusage', 'question' => 'Rückfrage', 'reject' => 'Absage', 'message' => 'Nachricht']);

        ob_start();
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form pov-response-form" data-pov-response-form data-template-accept="' . esc_attr($templates['accept']) . '" data-template-question="' . esc_attr($templates['question']) . '" data-template-reject="' . esc_attr($templates['reject']) . '" data-template-message="' . esc_attr($templates['message']) . '">';
        wp_nonce_field('pov_send_response');
        echo '<input type="hidden" name="action" value="pov_send_response"><input type="hidden" name="request_id" value="' . esc_attr((string) $request['id']) . '">';
        echo '<fieldset class="pov-response-choices"><legend class="screen-reader-text">Antwort wählen</legend>';
        foreach ($choices as $type => $label) {
            echo '<label><input type="radio" name="response_type" value="' . esc_attr($type) . '" ' . checked($defaultType, $type, false) . '><span>' . esc_html($label) . '</span></label>';
        }
        echo '</fieldset>';
        echo '<div class="pov-response-date" data-pov-response-date>';
        if ($accepted) {
            echo '<span>Termin <strong>' . esc_html(GermanDateFormatter::full($value)) . '</strong></span><input type="hidden" name="appointment_date" value="' . esc_attr($value) . '">';
        } else {
            echo '<label>Termin <input type="date" name="appointment_date" value="' . esc_attr($value) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" required></label>';
        }
        if ($suggestions && ! $accepted) {
            echo '<div class="pov-response-date-picks">';
            foreach ($suggestions as $suggestion) {
                $date = (string) $suggestion['suggestion_date'];
                echo '<button type="button" class="button" data-pov-response-date-value="' . esc_attr($date) . '">' . esc_html(GermanDateFormatter::short($date)) . '</button>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<label>Betreff <input name="subject" value="' . esc_attr('Ocean Van · ' . (string) $request['public_uuid']) . '" required></label>';
        echo '<label>Nachricht <textarea name="message" required>' . esc_textarea($templates[$defaultType]) . '</textarea></label>';
        echo '<label>Anhänge <input type="file" name="attachments[]" multiple><small>Bis zu 5 Dateien, jeweils maximal 10 MB.</small></label>';
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
        $candidates = array_values(array_filter($suggestions, static fn (array $row): bool => ! in_array((string) ($row['state'] ?? ''), [SuggestionState::REJECTED, SuggestionState::EXPIRED], true)));
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form">';
        wp_nonce_field('pov_send_proposals');
        echo '<input type="hidden" name="action" value="pov_send_proposals"><input type="hidden" name="request_id" value="' . esc_attr((string) $request['id']) . '">';
        echo '<label>Empfänger <input readonly value="' . esc_attr($request['contact_email']) . '"></label>';
        echo '<fieldset><legend>Termine</legend>';
        foreach ($candidates as $suggestion) {
            echo '<label><input type="checkbox" name="suggestion_ids[]" value="' . esc_attr((string) $suggestion['id']) . '" checked> ' . esc_html(GermanDateFormatter::full((string) $suggestion['suggestion_date'])) . '</label>';
        }
        echo '</fieldset>';
        echo '<label>Betreff <input name="subject" value="' . esc_attr((string) get_option('pov_proposal_email_subject')) . '"></label>';
        echo '<label>Nachricht <textarea name="message">' . esc_textarea((string) get_option('pov_proposal_email_body')) . '</textarea></label>';
        echo '<label>Anhänge <input type="file" name="attachments[]" multiple></label>';
        echo '<button class="button button-primary" ' . disabled(!$candidates, true, false) . '>Senden</button></form>';
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

    private function communicationHub(array $request): string
    {
        $messages = (new CommunicationRepository())->forRequest((int) $request['id']);
        $attachments = new AttachmentService();
        ob_start();
        echo '<section class="pov-admin-panel pov-communication-hub"><div class="pov-panel-heading"><div><span class="pov-admin-eyebrow">Verlauf</span><h2>Kommunikationshub</h2></div></div>';
        if (current_user_can(Capabilities::MANAGE_REQUESTS)) {
            echo '<details class="pov-communication-log-form"><summary>Eingegangene Rückmeldung dokumentieren</summary><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form">';
            wp_nonce_field('pov_log_communication');
            echo '<input type="hidden" name="action" value="pov_log_communication"><input type="hidden" name="request_id" value="' . esc_attr((string) $request['id']) . '">';
            echo '<div class="pov-admin-two"><label>Kanal <select name="communication_type"><option value="reply">E-Mail</option><option value="phone">Telefon</option><option value="meeting">Gespräch</option></select></label>';
            echo '<label>Von <input name="sender_name" value="' . esc_attr(trim((string) $request['contact_first_name'] . ' ' . (string) $request['contact_last_name'])) . '"></label></div>';
            echo '<label>Betreff <input name="subject"></label><label>Rückmeldung <textarea name="message" required></textarea></label>';
            echo '<label>Anhänge <input type="file" name="attachments[]" multiple></label><button class="button">Im Verlauf speichern</button></form></details>';
        }
        echo '<div class="pov-communication-timeline">';
        foreach ($messages as $message) {
            $direction = (string) $message['direction'];
            $label = ['incoming' => 'Eingang', 'outgoing' => 'Ausgang', 'internal' => 'Intern', 'system' => 'System'][$direction] ?? 'Verlauf';
            echo '<article class="pov-communication-item is-' . esc_attr($direction) . '"><header><span>' . esc_html($label) . '</span><time datetime="' . esc_attr((string) $message['created_at']) . '">' . esc_html(mysql2date('d.m.Y · H:i', (string) $message['created_at'])) . '</time></header>';
            if ((string) $message['subject'] !== '') {
                echo '<h3>' . esc_html((string) $message['subject']) . '</h3>';
            }
            echo '<p>' . nl2br(esc_html((string) $message['message'])) . '</p>';
            $fileRows = $attachments->rows((array) $message['attachment_ids']);
            if ($fileRows) {
                echo '<div class="pov-communication-files">';
                foreach ($fileRows as $file) {
                    echo '<a href="' . esc_url((string) $file['url']) . '" target="_blank" rel="noopener">📎 ' . esc_html((string) $file['name']) . '</a>';
                }
                echo '</div>';
            }
            $meta = trim((string) $message['sender_name']);
            if ((string) $message['recipient'] !== '') {
                $meta .= ($meta !== '' ? ' · ' : '') . (string) $message['recipient'];
            }
            echo '<footer><span>' . esc_html($meta) . '</span><span class="pov-delivery is-' . esc_attr((string) $message['delivery_status']) . '">' . esc_html((string) $message['delivery_status']) . '</span></footer></article>';
        }
        if (! $messages) {
            echo '<div class="pov-admin-empty"><strong>Noch keine Kommunikation dokumentiert.</strong></div>';
        }
        echo '</div></section>';
        return (string) ob_get_clean();
    }

    private function requestEditor(array $request, ?array $appointment): string
    {
        $targetGroup = (string) ($request['classes'][0]['class_name'] ?? '');
        $actualDate = $appointment ? (string) ($appointment['appointment_date'] ?? '') : '';
        $institutionType = (string) ($request['institution_type'] ?? '');
        $institutionTypes = ['Schule' => 'Schule', 'Veranstaltung' => 'Veranstaltung', 'Sonstiges' => 'Sonstiges'];
        if ($institutionType !== '' && ! isset($institutionTypes[$institutionType])) {
            $institutionTypes = [$institutionType => $institutionType] + $institutionTypes;
        }
        $children = max(0, (int) ($request['children_count'] ?? 0));
        $adults = max(0, (int) ($request['adult_count'] ?? 0));
        $weekdays = array_fill_keys(array_filter(explode(',', (string) ($request['possible_weekdays'] ?? ''))), true);
        $answers = ['' => 'Bitte wählen', 'yes' => 'Ja', 'no' => 'Nein'];
        $schoolGrades = ['' => 'Nicht erfasst', 'preschool' => 'Vorschule'];
        for ($grade = 1; $grade <= 13; $grade++) {
            $schoolGrades['grade_' . $grade] = $grade . '. Klasse';
        }
        $schoolGrades += ['vocational' => 'Berufsschule', 'mixed' => 'Altersgemischt'];
        $presentationEquipment = array_fill_keys(array_filter(explode(',', (string) ($request['presentation_equipment'] ?? ''))), true);
        $laptopConnections = array_fill_keys(array_filter(explode(',', (string) ($request['laptop_connections'] ?? ''))), true);
        ob_start();
        echo '<details class="pov-admin-panel pov-request-editor"><summary>Anfrage und Termin ändern</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form">';
        wp_nonce_field('pov_save_request_details');
        echo '<input type="hidden" name="action" value="pov_save_request_details"><input type="hidden" name="request_id" value="' . esc_attr((string) $request['id']) . '">';
        if ($appointment) {
            $confirmed = (string) ($appointment['status'] ?? '') === 'confirmed';
            echo '<label>' . ($confirmed ? 'Bestätigter Termin' : 'Stornierter Termin') . ' <input type="date" name="appointment_date" value="' . esc_attr($actualDate) . '"></label>';
            if (! $confirmed) {
                echo '<label><input type="checkbox" name="reactivate_appointment" value="1"> Termin mit diesem Datum reaktivieren</label>';
            }
        }
        echo '<label>Terminwunsch <select name="request_mode">' . $this->options(['specific_date' => 'Fester Tag', 'date_range' => 'Zeitraum'], (string) $request['request_mode']) . '</select></label>';
        echo '<label>Fester Tag <input type="date" name="specific_requested_date" value="' . esc_attr((string) ($request['specific_requested_date'] ?? '')) . '"></label>';
        echo '<div class="pov-admin-two"><label>Zeitraum von <input type="date" name="desired_date_from" value="' . esc_attr((string) ($request['desired_date_from'] ?? '')) . '"></label><label>bis <input type="date" name="desired_date_to" value="' . esc_attr((string) ($request['desired_date_to'] ?? '')) . '"></label></div>';
        echo '<fieldset><legend>Mögliche Wochentage</legend><div class="pov-choice-inline">';
        foreach (['mon' => 'Mo', 'tue' => 'Di', 'wed' => 'Mi', 'thu' => 'Do', 'fri' => 'Fr'] as $value => $label) {
            echo '<label><input type="checkbox" name="possible_weekdays[]" value="' . esc_attr($value) . '" ' . checked(isset($weekdays[$value]), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</div></fieldset>';
        echo '<label>Einrichtung <input name="institution_name" value="' . esc_attr((string) $request['institution_name']) . '" required></label>';
        echo '<label>Veranstaltungsart <select name="institution_type">' . $this->options($institutionTypes, $institutionType) . '</select></label>';
        echo '<div class="pov-admin-two"><label>Teilnehmende Kinder <input type="number" min="0" name="children_count" value="' . esc_attr((string) $children) . '" required></label><label>Teilnehmende Erwachsene <input type="number" min="0" name="adult_count" value="' . esc_attr((string) $adults) . '" required></label></div>';
        echo '<label>Zielgruppe <input name="target_group" value="' . esc_attr($targetGroup) . '"></label>';
        echo '<div class="pov-admin-two"><label>Vorname <input name="contact_first_name" value="' . esc_attr((string) $request['contact_first_name']) . '" required></label><label>Nachname <input name="contact_last_name" value="' . esc_attr((string) $request['contact_last_name']) . '" required></label></div>';
        echo '<label>E-Mail <input type="email" name="contact_email" value="' . esc_attr((string) $request['contact_email']) . '" required></label><label>Telefon <input name="contact_phone" value="' . esc_attr((string) $request['contact_phone']) . '" required></label>';
        echo '<div class="pov-admin-two"><label>Funktion <input name="contact_role" value="' . esc_attr((string) ($request['contact_role'] ?? '')) . '"></label><label>Website <input type="url" name="institution_website" value="' . esc_attr((string) ($request['institution_website'] ?? '')) . '"></label></div>';
        echo '<fieldset><legend>Schule</legend><div class="pov-admin-two"><label>Klassenstufe <select name="school_grade">' . $this->options($schoolGrades, (string) ($request['school_grade'] ?? '')) . '</select></label><label>Anzahl Klassen <input type="number" min="1" name="school_class_count" value="' . esc_attr((string) ($request['school_class_count'] ?? '')) . '"></label><label>Lehrpersonal / Klasse <input type="number" min="0" name="school_teachers_per_class" value="' . esc_attr((string) ($request['school_teachers_per_class'] ?? '')) . '"></label><label>Kinder / Klasse <input type="number" min="1" name="school_children_per_class" value="' . esc_attr((string) ($request['school_children_per_class'] ?? '')) . '"></label></div><label>Besondere Anforderungen <textarea name="school_needs">' . esc_textarea((string) ($request['school_needs'] ?? '')) . '</textarea></label><label>Hinweise / Wünsche zur zeitlichen Planung <textarea name="school_schedule_notes">' . esc_textarea((string) ($request['school_schedule_notes'] ?? '')) . '</textarea></label></fieldset>';
        echo '<label>Altersrange Kinder <input name="event_child_age_range" value="' . esc_attr((string) ($request['event_child_age_range'] ?? '')) . '"></label><label>Veranstaltungsart / Anlass <textarea name="occasion_description">' . esc_textarea((string) ($request['occasion_description'] ?? '')) . '</textarea></label>';
        echo '<label>Hinweise <textarea name="general_notes">' . esc_textarea((string) ($request['general_notes'] ?? '')) . '</textarea></label>';
        echo '<fieldset><legend>Vor Ort</legend><div class="pov-admin-two">';
        foreach (['bad_weather_option_available' => 'Schlechtwetter', 'electricity_outdoor_available' => 'Strom Außenbereich', 'electricity_charging_available' => 'Strom Technik', 'water_available' => 'Wasser', 'changing_room_available' => 'Umkleidekabine', 'shower_available' => 'Duschmöglichkeit', 'natural_water_nearby' => 'Fluss / See', 'wifi_available' => 'WLAN'] as $field => $label) {
            $answer = in_array((string) ($request[$field] ?? ''), ['yes', 'no'], true) ? (string) $request[$field] : '';
            echo '<label>' . esc_html($label) . '<select name="' . esc_attr($field) . '">' . $this->options($answers, $answer) . '</select></label>';
        }
        echo '</div><label>Zeitslot <select name="availability_window">' . $this->options(['' => 'Nicht erfasst', 'morning' => 'Vormittag', 'afternoon' => 'Nachmittag', 'full_day' => 'Ganztägig'], (string) ($request['availability_window'] ?? '')) . '</select></label><label>Einsatzbereich <select name="venue_type">' . $this->options(['' => 'Nicht erfasst', 'indoor' => 'Innenraum', 'outdoor' => 'Außenbereich', 'both' => 'Innen- und Außenbereich'], (string) ($request['venue_type'] ?? '')) . '</select></label><label>Beschreibung Räumlichkeit Innenraum-Veranstaltung <textarea name="indoor_room_description">' . esc_textarea((string) ($request['indoor_room_description'] ?? '')) . '</textarea></label><label>Beschreibung Räumlichkeit Außen-Veranstaltung <textarea name="outdoor_area_description">' . esc_textarea((string) ($request['outdoor_area_description'] ?? '')) . '</textarea></label><label>Stellplatzart <select name="parking_type">' . $this->options(['' => 'Nicht erfasst', 'schoolyard' => 'Schulhof', 'parking_lot' => 'Parkplatz', 'street' => 'Straßenrand / Ladezone', 'other' => 'Sonstiger Stellplatz'], (string) ($request['parking_type'] ?? '')) . '</select></label><label>Google-Maps-Link zum Stellplatz <input type="url" name="parking_location" value="' . esc_attr((string) ($request['parking_location'] ?? '')) . '"></label>';
        echo '<fieldset><legend>Präsentationstechnik</legend><div class="pov-choice-inline">';
        foreach (['chalkboard' => 'Kreidetafel', 'projector' => 'Beamer', 'digital_display' => 'Digitale Tafel / Screen', 'other' => 'Sonstiges'] as $value => $label) {
            echo '<label><input type="checkbox" name="presentation_equipment[]" value="' . esc_attr($value) . '" ' . checked(isset($presentationEquipment[$value]), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</div><label>Sonstige Präsentationstechnik <input name="presentation_equipment_other" value="' . esc_attr((string) ($request['presentation_equipment_other'] ?? '')) . '"></label></fieldset>';
        echo '<fieldset><legend>Laptop-Anschlüsse</legend><div class="pov-choice-inline">';
        foreach (['usb_c' => 'USB-C', 'usb_a' => 'USB Type A', 'other' => 'Sonstige'] as $value => $label) {
            echo '<label><input type="checkbox" name="laptop_connections[]" value="' . esc_attr($value) . '" ' . checked(isset($laptopConnections[$value]), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</div><label>Sonstiger Laptop-Anschluss <input name="laptop_connection_other" value="' . esc_attr((string) ($request['laptop_connection_other'] ?? '')) . '"></label></fieldset></fieldset>';
        if ($appointment && (string) ($appointment['status'] ?? '') === 'confirmed') {
            echo '<label class="pov-danger-option"><input type="checkbox" name="cancel_appointment" value="1"> Termin stornieren</label><label>Grund der Stornierung <textarea name="cancellation_reason"></textarea></label>';
        }
        echo '<button class="button button-primary">Änderungen speichern</button></form></details>';
        return (string) ob_get_clean();
    }

    private function appointmentOutcomePanel(array $appointment): string
    {
        $recorded = $appointment['participants_children'] !== null || $appointment['participants_adults'] !== null;
        ob_start();
        echo '<section class="pov-admin-panel"><span class="pov-admin-eyebrow">Statistik</span><h2>Einsatzdaten</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pov-admin-form">';
        wp_nonce_field('pov_save_appointment_outcome');
        echo '<input type="hidden" name="action" value="pov_save_appointment_outcome"><input type="hidden" name="appointment_id" value="' . esc_attr((string) $appointment['id']) . '"><input type="hidden" name="request_id" value="' . esc_attr((string) $appointment['request_id']) . '">';
        echo '<label>Veranstaltungsart <select name="event_type">' . $this->options(EventType::labels(), (string) $appointment['event_type']) . '</select></label>';
        echo '<div class="pov-admin-two"><label>Erreichte Kinder <input type="number" min="0" name="participants_children" value="' . esc_attr($recorded ? (string) ($appointment['participants_children'] ?? 0) : '') . '"></label>';
        echo '<label>Erreichte Erwachsene <input type="number" min="0" name="participants_adults" value="' . esc_attr($recorded ? (string) ($appointment['participants_adults'] ?? 0) : '') . '"></label></div>';
        echo '<button class="button">Einsatzdaten speichern</button></form></section>';
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
        echo '<p>' . esc_html((string) (($request['contact_role'] ?? '') ?: 'Funktion nicht erfasst')) . '<br><a href="mailto:' . esc_attr($request['contact_email']) . '">' . esc_html($request['contact_email']) . '</a><br><a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', (string) $request['contact_phone'])) . '">' . esc_html($request['contact_phone']) . '</a>';
        if (wp_http_validate_url((string) ($request['institution_website'] ?? ''))) {
            echo '<br><a href="' . esc_url((string) $request['institution_website']) . '" target="_blank" rel="noopener">Website öffnen</a>';
        }
        echo '</p>';
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

    private function requestPlanningPanel(array $request, bool $breakdownKnown): string
    {
        $availability = [
            'morning' => 'Vormittag',
            'afternoon' => 'Nachmittag',
            'full_day' => 'Ganztägig',
        ][(string) ($request['availability_window'] ?? '')] ?? 'Nicht erfasst';
        $venue = [
            'indoor' => 'Innenraum',
            'outdoor' => 'Außenbereich',
            'both' => 'Innen- und Außenbereich',
        ][(string) ($request['venue_type'] ?? '')] ?? 'Nicht erfasst';
        $facts = [
            'Kinder' => $breakdownKnown ? (string) ($request['children_count'] ?? 0) : 'Nicht erfasst',
            'Erwachsene' => $breakdownKnown ? (string) ($request['adult_count'] ?? 0) : 'Nicht erfasst',
            'Gesamt' => (string) ($request['participant_count'] ?? 0),
            'Zeitslot' => $availability,
            'Einsatzbereich' => $venue,
        ];
        $facts['Stellplatzart'] = [
            'schoolyard' => 'Schulhof',
            'parking_lot' => 'Parkplatz',
            'street' => 'Straßenrand / Ladezone',
            'other' => 'Sonstiger Stellplatz',
        ][(string) ($request['parking_type'] ?? '')] ?? 'Nicht erfasst';
        $facts += [
            'Strom Technik' => $this->answerLabel((string) ($request['electricity_charging_available'] ?? '')),
            'Wasser / Waschbecken' => $this->answerLabel((string) ($request['water_available'] ?? '')),
            'Umkleidekabine' => $this->answerLabel((string) ($request['changing_room_available'] ?? '')),
            'Duschmöglichkeit' => $this->answerLabel((string) ($request['shower_available'] ?? '')),
            'Fluss / See in Laufnähe' => $this->answerLabel((string) ($request['natural_water_nearby'] ?? '')),
            'WLAN' => $this->answerLabel((string) ($request['wifi_available'] ?? '')),
        ];
        if (in_array((string) ($request['venue_type'] ?? ''), ['outdoor', 'both'], true)) {
            $facts['Strom Außenbereich'] = $this->answerLabel((string) ($request['electricity_outdoor_available'] ?? ''));
            $facts['Schlechtwetteroption'] = $this->answerLabel((string) ($request['bad_weather_option_available'] ?? ''));
        }
        $presentation = $this->selectionLabels((string) ($request['presentation_equipment'] ?? ''), [
            'chalkboard' => 'Kreidetafel',
            'projector' => 'Beamer',
            'digital_display' => 'Digitale Tafel / Screen',
            'other' => 'Sonstiges',
        ]);
        if ($presentation !== '') {
            $facts['Präsentationstechnik'] = $presentation;
        }
        $connections = $this->selectionLabels((string) ($request['laptop_connections'] ?? ''), [
            'usb_c' => 'USB-C',
            'usb_a' => 'USB Type A',
            'other' => 'Sonstige',
        ]);
        if ($connections !== '') {
            $facts['Laptop-Anschlüsse'] = $connections;
        }
        $type = (string) ($request['institution_type'] ?? '');
        if ($type === 'Schule') {
            $facts += [
                'Klassenstufe' => $this->schoolGradeLabel((string) ($request['school_grade'] ?? '')),
                'Klassen' => (string) (($request['school_class_count'] ?? '') ?: 'Nicht erfasst'),
                'Lehrpersonal / Klasse' => (string) (($request['school_teachers_per_class'] ?? '') !== '' ? $request['school_teachers_per_class'] : 'Nicht erfasst'),
                'Kinder / Klasse' => (string) (($request['school_children_per_class'] ?? '') ?: 'Nicht erfasst'),
            ];
        } elseif ($type === 'Veranstaltung') {
            $facts['Altersrange Kinder'] = (string) (($request['event_child_age_range'] ?? '') ?: '–');
        }

        ob_start();
        echo '<section class="pov-admin-panel"><span class="pov-admin-eyebrow">Planungsdetails</span><h2>' . esc_html($type) . '</h2><dl class="pov-request-facts">';
        foreach ($facts as $label => $value) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
        }
        echo '</dl>';
        foreach ([
            'Anlass' => $request['occasion_description'] ?? '',
            'Besondere Anforderungen' => $request['school_needs'] ?? '',
            'Hinweise / Wünsche zur zeitlichen Planung' => $request['school_schedule_notes'] ?? '',
            'Innenraum' => $request['indoor_room_description'] ?? '',
            'Außenfläche' => $request['outdoor_area_description'] ?? '',
            'Sonstige Präsentationstechnik' => $request['presentation_equipment_other'] ?? '',
            'Sonstiger Laptop-Anschluss' => $request['laptop_connection_other'] ?? '',
        ] as $label => $value) {
            if (trim((string) $value) !== '') {
                echo '<p><strong>' . esc_html($label) . ':</strong><br>' . nl2br(esc_html((string) $value)) . '</p>';
            }
        }
        if (trim((string) ($request['parking_location'] ?? '')) !== '') {
            echo '<p><strong>Stellplatz:</strong><br><a href="' . esc_url((string) $request['parking_location']) . '" target="_blank" rel="noopener">Google Maps öffnen</a></p>';
        }
        echo $this->warnings($request) . '</section>';
        return (string) ob_get_clean();
    }

    private function schoolGradeLabel(string $value): string
    {
        if (preg_match('/^grade_(\d{1,2})$/', $value, $matches)) {
            return (string) ((int) $matches[1]) . '. Klasse';
        }
        return [
            'preschool' => 'Vorschule',
            'vocational' => 'Berufsschule',
            'mixed' => 'Altersgemischt',
        ][$value] ?? 'Nicht erfasst';
    }

    private function selectionLabels(string $csv, array $labels): string
    {
        $selected = array_values(array_filter(array_map('trim', explode(',', $csv))));
        return implode(', ', array_map(static fn (string $value): string => $labels[$value] ?? $value, $selected));
    }

    private function warnings(array $request): string
    {
        $fields = [
            'electricity_charging_available' => 'Strom Technik',
            'water_available' => 'Wasser / Waschbecken',
        ];
        if (in_array((string) ($request['venue_type'] ?? ''), ['outdoor', 'both'], true)) {
            $fields['bad_weather_option_available'] = 'Schlechtwetter';
            $fields['electricity_outdoor_available'] = 'Strom Außenbereich';
        }
        $out = '<div class="pov-chip-list">';
        foreach ($fields as $field => $label) {
            $value = (string) ($request[$field] ?? '');
            if (! in_array($value, ['yes', 'no'], true)) {
                continue;
            }
            $out .= '<span class="pov-admin-chip ' . ($value === 'yes' ? 'is-ok' : 'is-warn') . '">' . esc_html($label . ': ' . $this->answerLabel($value)) . '</span>';
        }
        return $out . '</div>';
    }

    private function answerLabel(string $value): string
    {
        return ['yes' => 'Ja', 'no' => 'Nein'][$value] ?? 'Nicht erfasst';
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

    private function defaultAttachmentSettings(): string
    {
        $service = new AttachmentService();
        $groups = [
            'confirmation' => [
                'label' => 'Anhänge Eingangsbestätigung',
                'option' => 'pov_confirmation_attachment_ids',
                'field' => 'confirmation_attachments',
            ],
            'proposal' => [
                'label' => 'Anhänge Terminvorschläge',
                'option' => 'pov_proposal_attachment_ids',
                'field' => 'proposal_attachments',
            ],
            'accept' => [
                'label' => 'Anhänge Zusage',
                'option' => 'pov_accept_attachment_ids',
                'field' => 'accept_attachments',
            ],
            'question' => [
                'label' => 'Anhänge Rückfrage',
                'option' => 'pov_question_attachment_ids',
                'field' => 'question_attachments',
            ],
            'reject' => [
                'label' => 'Anhänge Absage',
                'option' => 'pov_reject_attachment_ids',
                'field' => 'reject_attachments',
            ],
        ];
        $html = '<div class="pov-default-attachments"><h3>Standardanhänge</h3>';
        foreach ($groups as $key => $group) {
            $html .= '<div><label>' . esc_html($group['label']) . '<input type="file" name="' . esc_attr($group['field']) . '[]" multiple></label>';
            foreach ($service->rows($service->optionIds($group['option'])) as $file) {
                $html .= '<label class="pov-attachment-row"><input type="checkbox" name="remove_' . esc_attr($key) . '_attachment_ids[]" value="' . esc_attr((string) $file['id']) . '"> Entfernen: <a href="' . esc_url((string) $file['url']) . '" target="_blank" rel="noopener">' . esc_html((string) $file['name']) . '</a></label>';
            }
            $html .= '</div>';
        }
        return $html . '<p class="description">Neue Dateien werden ergänzt; markierte Dateien werden aus dem Standardmailing entfernt.</p></div>';
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

    private function isKnownAttachment(int $id): bool
    {
        $attachments = new AttachmentService();
        foreach ([
            'pov_confirmation_attachment_ids',
            'pov_proposal_attachment_ids',
            'pov_accept_attachment_ids',
            'pov_question_attachment_ids',
            'pov_reject_attachment_ids',
        ] as $option) {
            if (in_array($id, $attachments->optionIds($option), true)) {
                return true;
            }
        }

        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT attachment_ids FROM {$wpdb->prefix}pov_communications
             WHERE attachment_ids IS NOT NULL AND attachment_ids != ''"
        ) ?: [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row, true);
            if (is_array($decoded) && in_array($id, array_map('absint', $decoded), true)) {
                return true;
            }
        }
        return false;
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
            'response-message' => ['success', 'Nachricht gesendet.'],
            'response-invalid' => ['error', 'Bitte Antwort und Nachricht vollständig ausfüllen.'],
            'response-closed' => ['warning', 'Dieser Vorgang ist bereits abgeschlossen.'],
            'response-failed' => ['error', 'Die Antwort konnte nicht per E-Mail gesendet werden.'],
            'workflow-saved' => ['success', 'Arbeitsstand gespeichert.'],
            'note-saved' => ['success', 'Notiz gespeichert.'],
            'communication-saved' => ['success', 'Rückmeldung im Kommunikationsverlauf gespeichert.'],
            'communication-invalid' => ['error', 'Die Rückmeldung konnte nicht gespeichert werden.'],
            'attachment-invalid' => ['error', 'Dateien konnten nicht verarbeitet werden. Maximal fünf Dateien mit jeweils höchstens 10 MB.'],
            'details-saved' => ['success', 'Anfrage und Termin aktualisiert.'],
            'details-invalid' => ['error', 'Bitte die geänderten Anfragedaten prüfen.'],
            'appointment-conflict' => ['error', 'Der neue Termin ist belegt, gesperrt oder ungültig.'],
            'outcome-saved' => ['success', 'Einsatzdaten gespeichert.'],
            'outcome-invalid' => ['error', 'Die Einsatzdaten konnten nicht gespeichert werden.'],
            'expense-saved' => ['success', 'Übernachtungskosten gespeichert.'],
            'expense-deleted' => ['success', 'Übernachtungskosten entfernt.'],
            'expense-invalid' => ['error', 'Bitte Datum, Zuordnung und Kosten prüfen.'],
            'calendar-invalid' => ['error', 'Bitte einen gültigen Zeitraum mit höchstens 366 Tagen wählen.'],
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

        if (get_option('pov_demo_mode', '0') === '1'
            && get_option('pov_geocoding_provider') === 'demo'
            && get_option('pov_routing_provider') === 'null') {
            return;
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
