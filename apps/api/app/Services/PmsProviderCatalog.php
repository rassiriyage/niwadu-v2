<?php

namespace App\Services;

use InvalidArgumentException;

class PmsProviderCatalog
{
    /**
     * @return array<string, mixed>
     */
    public function definition(string $provider): array
    {
        return match ($provider) {
            'surge' => [
                'provider' => 'surge',
                'label' => 'Surge PMS',
                'environments' => ['sandbox', 'production'],
                'capabilities' => [
                    'availability_read' => 'unsupported',
                    'booking_mode' => 'post_sale_import',
                    'hold_confirm' => false,
                    'inventory_assurance' => 'none',
                    'price_guarantee' => 'none',
                    'outgoing_events' => false,
                ],
                'checkout_enabled' => false,
                'source_contract' => 'surge-channel-extension-proposal',
            ],
            'frappe' => [
                'provider' => 'frappe',
                'label' => 'Frappe PMS',
                'environments' => ['sandbox', 'production'],
                'capabilities' => [
                    'availability_read' => 'unsupported',
                    'booking_mode' => 'unsupported',
                    'hold_confirm' => false,
                    'inventory_assurance' => 'none',
                    'price_guarantee' => 'none',
                    'outgoing_events' => false,
                ],
                'checkout_enabled' => false,
                'source_contract' => null,
            ],
            default => throw new InvalidArgumentException('Unsupported PMS provider.'),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(fn (string $provider): array => $this->definition($provider), ['surge', 'frappe']);
    }
}
