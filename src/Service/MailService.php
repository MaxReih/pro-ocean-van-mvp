<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use ProOceanVan\Portal\OperationsPortal;
use ProOceanVan\Repository\CommunicationRepository;
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

        $template = (string) get_option('pov_confirmation_email_body');
        $body = $this->render($template, $request, []);
        if (! str_contains($template, '{Buchungsübersicht}')) {
            $body = rtrim($body) . "\n\n" . $this->bookingSummary($request);
        }
        $subject = (string) get_option('pov_confirmation_email_subject', 'Deine Anfrage für den Ocean Van ist eingegangen');
        $attachments = (new AttachmentService())->optionIds('pov_confirmation_attachment_ids');
        $sent = $this->send((string) $request['contact_email'], $subject, $body, [], $attachments);
        $repo->updateMailAttempt($requestId, $sent, $sent ? '' : 'wp_mail returned false');
        $this->record($request, 'outgoing', 'confirmation', $subject, $body, (string) $request['contact_email'], $attachments, $sent);

        return $sent;
    }

    public function sendProposal(array $request, array $suggestions, string $subject = '', string $message = '', array $attachmentIds = []): bool
    {
        $subject = $subject !== '' ? $subject : (string) get_option('pov_proposal_email_subject', 'Terminvorschläge für den Ocean Van');
        $message = $message !== '' ? $message : (string) get_option('pov_proposal_email_body', '');
        $html = $this->renderProposalHtml($message, $request, $suggestions);
        $attachmentIds = array_values(array_unique(array_merge(
            (new AttachmentService())->optionIds('pov_proposal_attachment_ids'),
            array_map('absint', $attachmentIds)
        )));
        $sent = $this->send(
            (string) $request['contact_email'],
            $subject,
            $html,
            ['Content-Type: text/html; charset=UTF-8'],
            $attachmentIds
        );
        $this->record($request, 'outgoing', 'proposal', $subject, wp_strip_all_tags($html), (string) $request['contact_email'], $attachmentIds, $sent);
        return $sent;
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
        $this->record($request, 'system', 'team_notification', $subject, $body, implode(', ', $recipients), [], $allSent);
        return $allSent;
    }

    public function sendResponse(
        array $request,
        string $type,
        string $message,
        string $date = '',
        string $subject = '',
        array $attachmentIds = []
    ): bool
    {
        if (! in_array($type, ['accept', 'question', 'reject', 'teacher', 'parents', 'followup', 'message'], true) || ! is_email((string) ($request['contact_email'] ?? ''))) {
            return false;
        }

        $dateLabel = $date !== '' ? GermanDateFormatter::full($date) : '';
        $subjects = [
            'accept' => 'Zusage für den Ocean Van' . ($dateLabel !== '' ? ' · ' . $dateLabel : ''),
            'question' => 'Rückfrage zu deiner Ocean-Van-Anfrage',
            'reject' => 'Deine Ocean-Van-Anfrage',
            'message' => 'Nachricht zu deiner Ocean-Van-Anfrage',
            'teacher' => 'Informationen für Lehrkräfte zum Ocean Van',
            'parents' => 'Informationen für Eltern zum Ocean Van',
            'followup' => 'Ocean Van · Nachbereitung und Feedback',
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

        $subject = trim($subject) !== '' ? sanitize_text_field($subject) : $subjects[$type];
        $body = implode("\n", $lines);
        if (in_array($type, ['accept', 'question', 'reject', 'teacher', 'parents', 'followup'], true)) {
            $attachmentIds = array_values(array_unique(array_merge(
                (new AttachmentService())->optionIds('pov_' . $type . '_attachment_ids'),
                array_map('absint', $attachmentIds)
            )));
        }
        $sent = $this->send((string) $request['contact_email'], $subject, $body, [], $attachmentIds);
        $this->record($request, 'outgoing', $type, $subject, $body, (string) $request['contact_email'], $attachmentIds, $sent);
        return $sent;
    }

    private function send(string $recipient, string $subject, string $message, array $headers = [], array $attachmentIds = []): bool
    {
        $configuredName = (string) get_option('pov_email_sender_name', '');
        $configuredAddress = (string) get_option('pov_email_sender_address', '');
        $nameFilter = static fn (string $name): string => $configuredName !== '' ? $configuredName : $name;
        $addressFilter = static fn (string $address): string => is_email($configuredAddress) ? $configuredAddress : $address;

        add_filter('wp_mail_from_name', $nameFilter);
        add_filter('wp_mail_from', $addressFilter);
        try {
            return wp_mail($recipient, $subject, $message, $headers, (new AttachmentService())->paths($attachmentIds));
        } finally {
            remove_filter('wp_mail_from_name', $nameFilter);
            remove_filter('wp_mail_from', $addressFilter);
        }
    }

    private function record(
        array $request,
        string $direction,
        string $type,
        string $subject,
        string $message,
        string $recipient,
        array $attachmentIds,
        bool $sent
    ): void {
        (new CommunicationRepository())->record((int) ($request['id'] ?? 0), [
            'direction' => $direction,
            'communication_type' => $type,
            'subject' => $subject,
            'message' => $message,
            'sender_name' => (string) get_option('pov_email_sender_name', 'Pro Ocean'),
            'recipient' => $recipient,
            'attachment_ids' => $attachmentIds,
            'delivery_status' => $sent ? 'sent' : 'failed',
        ]);
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
        $from = (string) ($request['desired_date_from'] ?? '');
        $to = (string) ($request['desired_date_to'] ?? '');
        if ($from === $to) {
            return GermanDateFormatter::full($from);
        }
        return GermanDateFormatter::short($from) . ' bis ' . GermanDateFormatter::short($to);
    }

    private function bookingSummary(array $request): string
    {
        $location = trim(trim((string) ($request['street'] ?? '') . ' ' . (string) ($request['house_number'] ?? '')) . ', '
            . trim((string) ($request['postal_code'] ?? '') . ' ' . (string) ($request['city'] ?? '')), ', ');
        $slot = ['morning' => 'Vormittag', 'afternoon' => 'Nachmittag', 'full_day' => 'Ganztägig'][(string) ($request['availability_window'] ?? '')] ?? '';
        $adults = (int) ($request['adult_count'] ?? 0);
        $lines = [
            'Eure Anfrage im Überblick',
            'Einrichtung: ' . (string) ($request['institution_name'] ?? ''),
            'Veranstaltungsart: ' . (string) ($request['institution_type'] ?? ''),
            'Terminwunsch: ' . $this->requestDate($request) . ($slot !== '' ? ' · ' . $slot : ''),
            'Einsatzort: ' . $location,
            'Gruppe: ' . (int) ($request['children_count'] ?? 0) . ' Kinder, ' . $adults . ($adults === 1 ? ' erwachsene Person' : ' Erwachsene'),
        ];
        if (($request['request_mode'] ?? '') === 'date_range' && ! empty($request['possible_weekdays'])) {
            $keys = ['mon' => 'Mo', 'tue' => 'Di', 'wed' => 'Mi', 'thu' => 'Do', 'fri' => 'Fr', 'sat' => 'Sa', 'sun' => 'So'];
            $days = array_intersect_key($keys, array_flip(explode(',', (string) $request['possible_weekdays'])));
            if ($days && count($days) < 7) {
                $lines[] = 'Mögliche Wochentage: ' . implode(', ', $days);
            }
        }
        foreach ((array) ($request['classes'] ?? []) as $class) {
            if (isset($class['children'])) {
                $lines[] = (string) $class['class_name'] . ' (' . (int) $class['grade'] . '. Stufe): ' . (int) $class['children']
                    . ' Kinder, ' . (int) $class['teachers'] . ' Lehrkräfte, ' . (int) $class['adults'] . ' weitere Erwachsene';
            }
        }
        $venue = ['indoor' => 'Innenraum', 'outdoor' => 'Außenbereich', 'both' => 'Innen- und Außenbereich'][(string) ($request['venue_type'] ?? '')] ?? '';
        $parking = ['schoolyard' => 'Schulhof', 'parking_lot' => 'Parkplatz', 'street' => 'Straßenrand / Ladezone', 'other' => 'Sonstiger Stellplatz'][(string) ($request['parking_type'] ?? '')] ?? '';
        foreach ([
            'Einsatzbereich' => $venue,
            'Stellplatz' => $parking,
            'Stellplatz-Link' => $request['parking_location'] ?? '',
            'Unterrichts- und Pausenzeiten' => $request['school_schedule_notes'] ?? '',
        ] as $label => $value) {
            if ($value !== '') {
                $lines[] = $label . ': ' . (string) $value;
            }
        }
        return implode("\n", $lines);
    }

    public function render(string $template, array $request, array $suggestions): string
    {
        $date = $this->requestDate($request);
        $suggestionLines = [];
        foreach ($suggestions as $suggestion) {
            $suggestionLines[] = GermanDateFormatter::full((string) $suggestion['suggestion_date']);
        }

        return strtr($template, [
            '{Vorname}' => (string) $request['contact_first_name'],
            '{Anfragekennung}' => (string) $request['public_uuid'],
            '{Termin oder Zeitraum}' => $date,
            '{Ort}' => (string) $request['city'],
            '{Buchungsübersicht}' => str_contains($template, '{Buchungsübersicht}') ? $this->bookingSummary($request) : '',
            '{Terminvorschlaege}' => implode("\n", $suggestionLines),
            '{Terminvorschläge}' => implode("\n", $suggestionLines),
        ]);
    }

    private function renderProposalHtml(string $template, array $request, array $suggestions): string
    {
        if ($template === '') {
            $template = "Hallo {Vorname},\n\nfür deine Anfrage {Anfragekennung} passen diese Termine gut:\n\n{Terminvorschläge}\n\nViele Grüße\nPro Ocean";
        }

        $date = $this->requestDate($request);
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
