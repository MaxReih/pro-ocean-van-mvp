<?php

declare(strict_types=1);

namespace ProOceanVan\Routing;

final class FallbackRoutingProvider implements RoutingProviderInterface
{
    public function __construct(
        private readonly RoutingProviderInterface $primary,
        private readonly RoutingProviderInterface $fallback,
        private readonly string $circuitKey = 'pov_heigit_unavailable',
        private readonly int $cooldownSeconds = 300
    ) {
    }

    public function calculateRoute(array $coordinates): RouteResult
    {
        if ($this->circuitOpen()) {
            return $this->fallback->calculateRoute($coordinates);
        }

        $primary = $this->primary->calculateRoute($coordinates);
        if ($primary->ok) {
            $this->closeCircuit();
            return $primary;
        }

        $this->openCircuit();
        $fallback = $this->fallback->calculateRoute($coordinates);
        if ($fallback->ok) {
            return $fallback;
        }

        return RouteResult::failure($this->combinedError($primary->error, $fallback->error));
    }

    public function calculateMatrix(array $sources, array $destinations): array
    {
        if ($this->circuitOpen()) {
            $fallback = $this->fallback->calculateMatrix($sources, $destinations);
            if (! empty($fallback['ok'])) {
                $fallback['fallback'] = true;
            }
            return $fallback;
        }

        $primary = $this->primary->calculateMatrix($sources, $destinations);
        if (! empty($primary['ok'])) {
            $this->closeCircuit();
            return $primary;
        }

        $this->openCircuit();
        $fallback = $this->fallback->calculateMatrix($sources, $destinations);
        if (! empty($fallback['ok'])) {
            $fallback['fallback'] = true;
            $fallback['primary_error'] = (string) ($primary['error'] ?? '');
            return $fallback;
        }

        $fallback['error'] = $this->combinedError(
            (string) ($primary['error'] ?? ''),
            (string) ($fallback['error'] ?? '')
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

    private function combinedError(string $primary, string $fallback): string
    {
        return trim('HeiGIT: ' . $primary . ' Ausweichdienst: ' . $fallback);
    }
}
