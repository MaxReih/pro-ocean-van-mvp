<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use DateTimeImmutable;
use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\SuggestionState;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CalendarDayRepository;
use ProOceanVan\Repository\RequestRepository;
use ProOceanVan\Repository\SuggestionRepository;

final class PublicSuggestionResponseService
{
    private const TOKEN_TTL_SECONDS = 14 * 24 * 60 * 60;

    public function register(): void
    {
        add_action('admin_post_nopriv_pov_accept_suggestion', [$this, 'accept']);
        add_action('admin_post_pov_accept_suggestion', [$this, 'accept']);
    }

    public function accept(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $input = $method === 'POST' ? $_POST : $_GET;
        $context = $this->validatedContext((array) $input);
        if (! $context) {
            wp_die('Dieser Annahmelink ist nicht gültig oder bereits abgelaufen.', 'Ocean Van Termin', ['response' => 403]);
        }

        $request = $context['request'];
        $suggestion = $context['suggestion'];
        $alreadyAccepted = $context['already_accepted'];

        if ($method !== 'POST') {
            if ($alreadyAccepted) {
                $this->renderResultPage('Terminwunsch bereits übernommen', 'Dieser Terminwunsch wurde bereits an das Ocean-Van-Team übermittelt.');
            }

            $availabilityError = $this->availabilityError($request, $suggestion);
            if ($availabilityError !== '') {
                $this->renderResultPage('Termin nicht mehr verfügbar', $availabilityError, 409);
            }

            $this->renderConfirmationPage($request, $suggestion, $context['expires'], $context['token']);
        }

        if ($alreadyAccepted) {
            $this->renderResultPage('Terminwunsch bereits übernommen', 'Dieser Terminwunsch wurde bereits an das Ocean-Van-Team übermittelt.');
        }

        if ((string) $suggestion['state'] !== SuggestionState::SENT) {
            wp_die('Dieser Terminvorschlag kann nicht mehr angenommen werden.', 'Ocean Van Termin', ['response' => 409]);
        }

        $availabilityError = $this->availabilityError($request, $suggestion);
        if ($availabilityError !== '') {
            $this->renderResultPage('Termin nicht mehr verfügbar', $availabilityError, 409);
        }

        $requests = new RequestRepository();
        $suggestions = new SuggestionRepository();
        $suggestions->acceptPublicChoice((int) $request['id'], (int) $suggestion['id']);
        $requests->updateStatus((int) $request['id'], RequestStatus::PROPOSAL_SENT, WorkState::IN_REVIEW);

        $this->renderResultPage('Terminwunsch übernommen', 'Danke, der Terminwunsch wurde übernommen. Wir melden uns mit der finalen Bestätigung.');
    }

    public function acceptUrl(array $request, array $suggestion): string
    {
        $expires = time() + self::TOKEN_TTL_SECONDS;
        return add_query_arg([
            'action' => 'pov_accept_suggestion',
            'request_id' => (int) $request['id'],
            'suggestion_id' => (int) $suggestion['id'],
            'expires' => $expires,
            'token' => $this->token($request, $suggestion, $expires),
        ], admin_url('admin-post.php'));
    }

    private function validatedContext(array $input): ?array
    {
        $requestId = absint($input['request_id'] ?? 0);
        $suggestionId = absint($input['suggestion_id'] ?? 0);
        $expires = absint($input['expires'] ?? 0);
        $token = sanitize_text_field((string) ($input['token'] ?? ''));
        if ($requestId <= 0 || $suggestionId <= 0 || $expires < time() || $token === '') {
            return null;
        }

        $request = (new RequestRepository())->find($requestId);
        $suggestion = (new SuggestionRepository())->find($suggestionId);
        if (! $request || ! $suggestion || (int) $suggestion['request_id'] !== $requestId) {
            return null;
        }

        if (! hash_equals($this->token($request, $suggestion, $expires), $token)) {
            return null;
        }

        $alreadyAccepted = (string) $suggestion['state'] === SuggestionState::ACCEPTED
            && ! empty($request['proposal_sent_at'])
            && (string) $request['work_state'] === WorkState::IN_REVIEW;
        if ((string) $suggestion['state'] !== SuggestionState::SENT && ! $alreadyAccepted) {
            return null;
        }

        return compact('request', 'suggestion', 'expires', 'token', 'alreadyAccepted');
    }

    private function token(array $request, array $suggestion, int $expires): string
    {
        $payload = implode('|', [
            (int) $request['id'],
            (int) $suggestion['id'],
            (string) $request['public_uuid'],
            (string) $suggestion['suggestion_date'],
            $expires,
        ]);
        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    private function availabilityError(array $request, array $suggestion): string
    {
        if ((string) ($request['main_status'] ?? '') === RequestStatus::CONFIRMED
            || in_array((string) ($request['work_state'] ?? ''), [WorkState::ACCEPTED, WorkState::REJECTED, WorkState::CANCELLED], true)
            || (new AppointmentRepository())->existsForRequest((int) $request['id'])) {
            return 'Für diese Anfrage wurde bereits eine abschließende Entscheidung getroffen.';
        }

        $date = (string) ($suggestion['suggestion_date'] ?? '');
        if (! $this->dateAllowedForRequest($date, $request)) {
            return 'Der vorgeschlagene Termin liegt nicht mehr im möglichen Anfragezeitraum.';
        }
        if ((new CalendarDayRepository())->isBlocked($date) || (new AppointmentRepository())->existsOnDate($date)) {
            return 'Der vorgeschlagene Termin ist inzwischen anderweitig belegt oder gesperrt.';
        }

        return '';
    }

    private function dateAllowedForRequest(string $date, array $request): bool
    {
        if (! $this->validDate($date)) {
            return false;
        }

        $day = new DateTimeImmutable($date, wp_timezone());
        if ($day < new DateTimeImmutable('today', wp_timezone()) || (int) $day->format('N') >= 6) {
            return false;
        }

        if ((string) ($request['request_mode'] ?? '') === 'specific_date') {
            return $date === (string) ($request['specific_requested_date'] ?? '');
        }

        $from = (string) ($request['desired_date_from'] ?? '');
        $to = (string) ($request['desired_date_to'] ?? '');
        if (! $this->validDate($from) || ! $this->validDate($to) || $date < $from || $date > $to) {
            return false;
        }

        $weekdays = array_filter(explode(',', (string) ($request['possible_weekdays'] ?? '')));
        return ! $weekdays || in_array(strtolower($day->format('D')), $weekdays, true);
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private function renderConfirmationPage(array $request, array $suggestion, int $expires, string $token): never
    {
        $dateLabel = mysql2date('l, d. F Y', (string) $suggestion['suggestion_date']);
        $action = admin_url('admin-post.php');
        $html = '<p>Bitte bestätige, dass dieser Termin für euch passt:</p><p><strong>' . esc_html($dateLabel) . '</strong><br>' . esc_html((string) $request['institution_name']) . '</p>';
        $html .= '<form method="post" action="' . esc_url($action) . '">';
        foreach ([
            'action' => 'pov_accept_suggestion',
            'request_id' => (int) $request['id'],
            'suggestion_id' => (int) $suggestion['id'],
            'expires' => $expires,
            'token' => $token,
        ] as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }
        $html .= '<button type="submit" style="padding:10px 16px;background:#135476;color:#fff;border:0;border-radius:6px;font-weight:700;cursor:pointer">Terminwunsch bestätigen</button></form>';

        wp_die($html, 'Ocean Van Termin bestätigen', ['response' => 200]);
    }

    private function renderResultPage(string $title, string $message, int $status = 200): never
    {
        wp_die(esc_html($message), esc_html($title), ['response' => $status]);
    }
}
