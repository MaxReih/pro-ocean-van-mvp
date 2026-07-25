<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

final class FallbackGeocodingProvider implements GeocodingProviderInterface
{
    public function __construct(
        private readonly GeocodingProviderInterface $primary,
        private readonly GeocodingProviderInterface $fallback,
        private readonly string $circuitKey = 'pov_heigit_unavailable',
        private readonly int $cooldownSeconds = 300
    ) {
    }

    public function geocodeAddress(array $address): array
    {
        if ($this->circuitOpen()) {
            $fallback = $this->fallback->geocodeAddress($address);
            if (! empty($fallback['ok'])) {
                $fallback['fallback'] = true;
            }
            return $fallback;
        }

        $primary = $this->primary->geocodeAddress($address);
        if (! empty($primary['ok'])) {
            $this->closeCircuit();
            return $primary;
        }

        $this->openCircuit();
        $fallback = $this->fallback->geocodeAddress($address);
        if (! empty($fallback['ok'])) {
            $fallback['fallback'] = true;
            $fallback['primary_error'] = (string) ($primary['error'] ?? '');
            return $fallback;
        }

        $fallback['error'] = trim(
            'HeiGIT: ' . (string) ($primary['error'] ?? '')
            . ' Ausweichdienst: ' . (string) ($fallback['error'] ?? '')
        );
        return $fallback;
    }

    private function circuitOpen(): bool
    {
        return function_exists('get_transient') && get_transient($this->circuitKey) !== false;
    }

    private function openCircuit(): void
    {
        if (function_exists('set_transient')) {
            set_transient($this->circuitKey, '1', max(30, $this->cooldownSeconds));
        }
    }

    private function closeCircuit(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient($this->circuitKey);
        }
    }
}
