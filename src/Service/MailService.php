<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use ProOceanVan\Portal\OperationsPortal;
use ProOceanVan\Repository\RequestRepository;

final class MailService
{
    public function sendConfirmation(int $requestId): bool
    {
        $repo = new RequestRepository();
        $request = $repo->find($requestId);
        if (! $request) {
            return false;
        }

        $body = $this->render((string) get_option('pov_confirmation_email_body'), $request, []);
        $subject = (string) get_option('pov_confirmation_email_subject', 'Deine Anfrage für den Ocean Van ist eingegangen');
        $sent = $this->send((string) $request['contact_email'], $subject, $body);
        $repo->updateMailAttempt($requestId, $sent, $sent ? '' : 'wp_mail returned false');

        return $sent;
    }

    public function sendProposal(array $request, array $suggestions, string $subject = '', string $message = ''): bool
    {
        $subject = $subject !== '' ? $subject : (string) get_option('pov_proposal_email_subject', 'Terminvorschläge für den Ocean Van');
        $message = $message !== '' ? $message : (string) get_option('pov_proposal_email_body', '');
        return $this->send(
            (string) $request['contact_email'],
            $subject,
            $this->renderProposalHtml($message, $request, $suggestions),
            ['Content-Type: text/html; charset=UTF-8']
        );
    }

    public function sendTeamNotification(int $requestId): bool
    {
        $request = (new RequestRepository())->find($requestId);
        $recipients = $this->teamRecipients();
        if (! $request || ! $recipients) {
            return false;
        }

        $date = $this->requestDate($request);
        $route = is_numeric($request['route_distance_km'] ?? null)
            ? number_format((float) $request['route_distance_km'], 0, ',', '.') . ' km'
            : 'noch offen';
        $subject = 'Neue Ocean-Van-Anfrage · ' . (string) $request['city'] . ' · ' . $date;
        $body = implode("\n", [
            'Neue Ocean-Van-Anfrage',
            '',
            (string) $request['institution_name'],
            (string) $request['postal_code'] . ' ' . (string) $request['city'],
            $date,
            'Route: ' . $route,
            'Gruppe: ' . (string) $request['participant_count'],
            '',
            OperationsPortal::url('pov-requests', ['request_id' => $requestId]),
        ]);

        $allSent = true;
        foreach ($recipients as $recipient) {
            $allSent = $this->send($recipient, $subject, $body) && $allSent;
        }
        return $allSent;
    }

    public function sendResponse(array $request, string $type, string $message, string $date = ''): bool
    {
        if (! in_array($type, ['accept', 'question', 'reject'], true) || ! is_email((string) ($request['contact_email'] ?? ''))) {
            return false;
        }

        $dateLabel = $date !== '' ? GermanDateFormatter::full($date) : '';
        $subjects = [
            'accept' => 'Zusage für den Ocean Van' . ($dateLabel !== '' ? ' · ' . $dateLabel : ''),
            'question' => 'Rückfrage zu deiner Ocean-Van-Anfrage',
            'reject' => 'Deine Ocean-Van-Anfrage',
        ];
        $lines = [
            'Hallo ' . (string) $request['contact_first_name'] . ',',
            '',
            trim($message),
        ];
        if ($type === 'accept' && $dateLabel !== '') {
            $lines[] = '';
            $lines[] = 'Termin: ' . $dateLabel;
            $lines[] = 'Ort: ' . (string) $request['postal_code'] . ' ' . (string) $request['city'];
        }
        $lines[] = '';
        $lines[] = 'Viele Grüße';
        $lines[] = 'Pro Ocean';

        return $this->send((string) $request['contact_email'], $subjects[$type], implode("\n", $lines));
    }

    private function send(string $recipient, string $subject, string $message, array $headers = []): bool
    {
        $configuredName = (string) get_option('pov_email_sender_name', '');
        $configuredAddress = (string) get_option('pov_email_sender_address', '');
        $nameFilter = static fn (string $name): string => $configuredName !== '' ? $configuredName : $name;
        $addressFilter = static fn (string $address): string => is_email($configuredAddress) ? $configuredAddress : $address;

        add_filter('wp_mail_from_name', $nameFilter);
        add_filter('wp_mail_from', $addressFilter);
        try {
            return wp_mail($recipient, $subject, $message, $headers);
        } finally {
            remove_filter('wp_mail_from_name', $nameFilter);
            remove_filter('wp_mail_from', $addressFilter);
        }
    }

    private function teamRecipients(): array
    {
        $raw = (string) get_option('pov_team_notification_emails', get_option('admin_email', ''));
        $items = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter(array_map('sanitize_email', $items), 'is_email')));
    }

    private function requestDate(array $request): string
    {
        if (($request['request_mode'] ?? '') === 'specific_date') {
            return GermanDateFormatter::full((string) ($request['specific_requested_date'] ?? ''));
        }
        return mysql2date('d.m.Y', (string) ($request['desired_date_from'] ?? ''))
            . ' – ' . mysql2date('d.m.Y', (string) ($request['desired_date_to'] ?? ''));
    }

    public function render(string $template, array $request, array $suggestions): string
    {
        $date = $request['request_mode'] === 'specific_date'
            ? (string) $request['specific_requested_date']
            : trim((string) $request['desired_date_from'] . ' bis ' . (string) $request['desired_date_to']);
        $suggestionLines = [];
        foreach ($suggestions as $suggestion) {
            $suggestionLines[] = GermanDateFormatter::full((string) $suggestion['suggestion_date']);
        }

        return strtr($template, [
            '{Vorname}' => (string) $request['contact_first_name'],
            '{Anfragekennung}' => (string) $request['public_uuid'],
            '{Termin oder Zeitraum}' => $date,
            '{Ort}' => (string) $request['city'],
            '{Terminvorschlaege}' => implode("\n", $suggestionLines),
            '{Terminvorschläge}' => implode("\n", $suggestionLines),
        ]);
    }

    private function renderProposalHtml(string $template, array $request, array $suggestions): string
    {
        if ($template === '') {
            $template = "Hallo {Vorname},\n\nfür deine Anfrage {Anfragekennung} passen diese Termine gut:\n\n{Terminvorschläge}\n\nViele Grüße\nPro Ocean";
        }

        $date = $request['request_mode'] === 'specific_date'
            ? (string) $request['specific_requested_date']
            : trim((string) $request['desired_date_from'] . ' bis ' . (string) $request['desired_date_to']);
        $html = nl2br(esc_html($template));
        $suggestionHtml = $this->proposalButtons($request, $suggestions);

        return strtr($html, [
            '{Vorname}' => esc_html((string) $request['contact_first_name']),
            '{Anfragekennung}' => esc_html((string) $request['public_uuid']),
            '{Termin oder Zeitraum}' => esc_html($date),
            '{Ort}' => esc_html((string) $request['city']),
            '{Terminvorschlaege}' => $suggestionHtml,
            '{Terminvorschläge}' => $suggestionHtml,
        ]);
    }

    private function proposalButtons(array $request, array $suggestions): string
    {
        $links = new PublicSuggestionResponseService();
        $rows = [];
        foreach ($suggestions as $suggestion) {
            $label = GermanDateFormatter::full((string) $suggestion['suggestion_date']);
            $reason = trim((string) ($suggestion['reason_summary'] ?? ''));
            $url = $links->acceptUrl($request, $suggestion);
            $rows[] = '<p style="margin:16px 0"><strong>' . esc_html($label) . '</strong>'
                . ($reason !== '' ? '<br><span>' . esc_html($reason) . '</span>' : '')
                . '<br><a href="' . esc_url($url) . '" style="display:inline-block;margin-top:8px;padding:10px 14px;background:#135476;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:700">Diesen Termin annehmen</a></p>';
        }

        return implode("\n", $rows);
    }
}
