<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

final class IcsService
{
    public function appointment(array $appointment, array $request = []): string
    {
        $date = str_replace('-', '', (string) $appointment['appointment_date']);
        $summary = 'Ocean Van - ' . (string) $appointment['institution_name'];
        $location = trim((string) $appointment['street'] . ' ' . (string) $appointment['house_number'] . ', ' . (string) $appointment['postal_code'] . ' ' . (string) $appointment['city']);
        $contact = $this->contact($appointment, $request);
        $description = implode("\\n", array_filter([
            'Kontakt: ' . $contact['name'],
            'Telefon: ' . $contact['phone'],
            'E-Mail: ' . $contact['email'],
            'Anfrage: ' . (string) ($request['public_uuid'] ?? $appointment['request_id'] ?? ''),
            (string) ($appointment['internal_notes'] ?? ''),
        ]));

        return implode("\r\n", array_filter([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Pro Ocean//Ocean Van Planner//DE',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:pov-' . (string) $appointment['id'] . '@' . wp_parse_url(home_url(), PHP_URL_HOST),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:' . $date,
            'DTEND;VALUE=DATE:' . date('Ymd', strtotime((string) $appointment['appointment_date'] . ' +1 day')),
            'SUMMARY:' . $this->escape($summary),
            'LOCATION:' . $this->escape($location),
            'CONTACT:' . $this->escape(trim($contact['name'] . ' ' . $contact['phone'] . ' ' . $contact['email'])),
            $contact['email'] !== '' ? 'ORGANIZER;CN=' . $this->escape($contact['name'] ?: 'Kontakt') . ':MAILTO:' . $this->escape($contact['email']) : '',
            'DESCRIPTION:' . $this->escape($description),
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ], static fn (string $line): bool => $line !== ''));
    }

    public function googleLink(array $appointment, array $request = []): string
    {
        $date = str_replace('-', '', (string) $appointment['appointment_date']);
        $end = date('Ymd', strtotime((string) $appointment['appointment_date'] . ' +1 day'));
        $location = trim((string) $appointment['street'] . ' ' . (string) $appointment['house_number'] . ', ' . (string) $appointment['postal_code'] . ' ' . (string) $appointment['city']);
        $contact = $this->contact($appointment, $request);
        $details = implode("\n", array_filter([
            'Kontakt: ' . $contact['name'],
            'Telefon: ' . $contact['phone'],
            'E-Mail: ' . $contact['email'],
            'Anfrage: ' . (string) ($request['public_uuid'] ?? ''),
        ]));

        return add_query_arg([
            'action' => 'TEMPLATE',
            'text' => 'Ocean Van - ' . (string) $appointment['institution_name'],
            'dates' => $date . '/' . $end,
            'location' => $location,
            'details' => $details,
        ], 'https://calendar.google.com/calendar/render');
    }

    public function escape(string $value): string
    {
        $value = str_replace(["\\", "\r\n", "\r", "\n", ',', ';'], ['\\\\', '\n', '\n', '\n', '\,', '\;'], $value);
        return preg_replace('/(.{72})/u', "$1\r\n ", $value) ?: $value;
    }

    private function contact(array $appointment, array $request): array
    {
        $name = trim((string) ($appointment['contact_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($request['contact_first_name'] ?? '') . ' ' . (string) ($request['contact_last_name'] ?? ''));
        }
        return [
            'name' => $name,
            'phone' => (string) (($appointment['contact_phone'] ?? '') ?: ($request['contact_phone'] ?? '')),
            'email' => (string) (($appointment['contact_email'] ?? '') ?: ($request['contact_email'] ?? '')),
        ];
    }
}
