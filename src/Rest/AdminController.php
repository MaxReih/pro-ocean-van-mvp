<?php

declare(strict_types=1);

namespace ProOceanVan\Rest;

use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\SuggestionState;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\SuggestionRepository;
use ProOceanVan\Service\InternalSuggestionService;
use ProOceanVan\Service\MailService;
use ProOceanVan\Service\WeeklyClusterService;
use WP_Error;
use WP_REST_Request;

final class AdminController
{
    private string $namespace = 'pro-ocean-van/v1';

    public function register_routes(): void
    {
        $routes = [
            ['/admin/requests', 'GET', 'requests'],
            ['/admin/requests/(?P<id>\d+)', 'GET', 'request'],
            ['/admin/requests/(?P<id>\d+)/suggestions/recalculate', 'POST', 'recalculate'],
            ['/admin/suggestions/(?P<id>\d+)/accept', 'POST', 'accept'],
            ['/admin/suggestions/(?P<id>\d+)/reject', 'POST', 'reject'],
            ['/admin/requests/(?P<id>\d+)/send-proposals', 'POST', 'sendProposals'],
            ['/admin/requests/(?P<id>\d+)/confirm', 'POST', 'confirm'],
            ['/admin/weeks', 'GET', 'weeks'],
            ['/admin/routes/recalculate', 'POST', 'routesRecalculate'],
        ];

        foreach ($routes as [$route, $method, $callback]) {
            register_rest_route($this->namespace, $route, [
                'methods' => $method,
                'callback' => [$this, $callback],
                'permission_callback' => [$this, 'canManage'],
            ]);
        }
    }

    public function canManage(): bool
    {
        return current_user_can(apply_filters('pov_manage_capability', 'manage_options'));
    }

    public function requests(WP_REST_Request $request): array
    {
        return (new RequestRepository())->list($request->get_params(), 100);
    }

    public function request(WP_REST_Request $request): array|WP_Error
    {
        $item = (new RequestRepository())->find((int) $request['id']);
        return $item ?: new WP_Error('pov_not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
    }

    public function recalculate(WP_REST_Request $request): array
    {
        return ['suggestions' => (new InternalSuggestionService())->recalculate((int) $request['id'])];
    }

    public function accept(WP_REST_Request $request): array
    {
        (new SuggestionRepository())->setState((int) $request['id'], SuggestionState::ACCEPTED);
        return ['ok' => true];
    }

    public function reject(WP_REST_Request $request): array
    {
        (new SuggestionRepository())->setState((int) $request['id'], SuggestionState::REJECTED);
        return ['ok' => true];
    }

    public function sendProposals(WP_REST_Request $request): array|WP_Error
    {
        $id = (int) $request['id'];
        $repo = new RequestRepository();
        $item = $repo->find($id);
        if (! $item) {
            return new WP_Error('pov_not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
        }
        $suggestions = (new SuggestionRepository())->acceptedForRequest($id);
        foreach ($suggestions as $index => $suggestion) {
            if (! $this->dateAllowedForRequest((string) $suggestion['suggestion_date'], $item)
                || (new CalendarDayRepository())->isBlocked((string) $suggestion['suggestion_date'])
                || (new AppointmentRepository())->existsOnDate((string) $suggestion['suggestion_date'])) {
                (new SuggestionRepository())->setState((int) $suggestion['id'], SuggestionState::EXPIRED);
                unset($suggestions[$index]);
            }
        }
        $suggestions = array_values($suggestions);
        if (! $suggestions) {
            return new WP_Error('pov_no_suggestions', 'Keine akzeptierten Vorschläge vorhanden.', ['status' => 422]);
        }
        $sent = (new MailService())->sendProposal($item, $suggestions, (string) $request->get_param('subject'), (string) $request->get_param('message'));
        if (! $sent) {
            return new WP_Error('pov_mail_failed', 'E-Mail konnte nicht gesendet werden.', ['status' => 500]);
        }
        (new SuggestionRepository())->markSent(array_column($suggestions, 'id'));
        $repo->updateStatus($id, RequestStatus::PROPOSAL_SENT, WorkState::AWAITING_RESPONSE, ['proposal_sent_at' => current_time('mysql')]);
        return ['ok' => true];
    }

    public function confirm(WP_REST_Request $request): array|WP_Error
    {
        $id = (int) $request['id'];
        $item = (new RequestRepository())->find($id);
        if (! $item) {
            return new WP_Error('pov_not_found', 'Anfrage nicht gefunden.', ['status' => 404]);
        }
        $date = sanitize_text_field((string) $request->get_param('date'));
        $city = sanitize_text_field((string) $request->get_param('public_city'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $city === '') {
            return new WP_Error('pov_validation', 'Datum und öffentliche Stadt sind erforderlich.', ['status' => 422]);
        }
        if (! $this->dateAllowedForRequest($date, $item)
            || (int) (new \DateTimeImmutable($date))->format('N') >= 6
            || (new CalendarDayRepository())->isBlocked($date)) {
            return new WP_Error('pov_conflict', 'Der Termin liegt außerhalb der Anfrage oder ist nicht mehr verfügbar.', ['status' => 409]);
        }
        $appointmentId = (new AppointmentRepository())->createFromRequest($item, $date, $city, [], (string) $request->get_param('internal_notes'));
        if ($appointmentId <= 0) {
            return new WP_Error('pov_conflict', 'An diesem Tag existiert bereits ein bestätigter Termin.', ['status' => 409]);
        }
        (new RequestRepository())->updateStatus($id, RequestStatus::CONFIRMED, WorkState::ACCEPTED, ['confirmed_at' => current_time('mysql')]);
        return ['ok' => true, 'appointment_id' => $appointmentId];
    }

    public function weeks(): array
    {
        return (new WeeklyClusterService())->clusters();
    }

    public function routesRecalculate(): array
    {
        $clusters = (new WeeklyClusterService())->clusters();
        return [
            'ok' => true,
            'message' => count($clusters) . ' Tourwochen kostenoptimiert berechnet.',
            'clusters' => $clusters,
        ];
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
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
            && $date >= $from
            && $date <= $to;
    }
}
