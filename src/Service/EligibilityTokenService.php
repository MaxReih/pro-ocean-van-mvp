<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

final class EligibilityTokenService
{
    private const LIFETIME_SECONDS = 7200;

    public function issue(string $postalCode, string $stateCode, array $dates): string
    {
        $payload = [
            'postal_code' => preg_replace('/\D+/', '', $postalCode),
            'state_code' => strtoupper(sanitize_key($stateCode)),
            'dates' => array_values(array_unique(array_filter(array_map('strval', $dates)))),
            'expires_at' => time() + self::LIFETIME_SECONDS,
        ];
        $encoded = $this->encode((string) wp_json_encode($payload));
        return $encoded . '.' . $this->encode(hash_hmac('sha256', $encoded, wp_salt('auth'), true));
    }

    public function allows(string $token, string $postalCode, string $stateCode, string $date): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$encoded, $signature] = $parts;
        $expected = $this->encode(hash_hmac('sha256', $encoded, wp_salt('auth'), true));
        if (! hash_equals($expected, $signature)) {
            return false;
        }
        $decoded = $this->decode($encoded);
        $payload = $decoded !== '' ? json_decode($decoded, true) : null;
        if (! is_array($payload)
            || (int) ($payload['expires_at'] ?? 0) < time()
            || (string) ($payload['postal_code'] ?? '') !== preg_replace('/\D+/', '', $postalCode)
            || (string) ($payload['state_code'] ?? '') !== strtoupper(sanitize_key($stateCode))) {
            return false;
        }
        return in_array($date, array_map('strval', (array) ($payload['dates'] ?? [])), true);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return is_string($decoded) ? $decoded : '';
    }
}
