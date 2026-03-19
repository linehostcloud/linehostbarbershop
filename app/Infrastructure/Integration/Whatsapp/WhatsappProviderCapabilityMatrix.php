<?php

namespace App\Infrastructure\Integration\Whatsapp;

use App\Domain\Communication\Enums\WhatsappCapability;

class WhatsappProviderCapabilityMatrix
{
    /**
     * @var array<string, array{implemented:list<string>,prepared:list<string>}>
     */
    private array $matrix = [
        'fake' => [
            'implemented' => [
                'text',
                'template',
                'media',
                'inbound_webhook',
                'delivery_status',
                'read_receipt',
                'healthcheck',
            ],
            'prepared' => [],
        ],
        'fake-transient-failure' => [
            'implemented' => [
                'text',
                'template',
                'media',
                'inbound_webhook',
                'delivery_status',
                'read_receipt',
                'healthcheck',
            ],
            'prepared' => [],
        ],
        'whatsapp_cloud' => [
            'implemented' => [
                'text',
                'template',
                'media',
                'inbound_webhook',
                'delivery_status',
                'read_receipt',
                'healthcheck',
                'official_templates',
            ],
            'prepared' => [],
        ],
        'evolution_api' => [
            'implemented' => [
                'text',
                'inbound_webhook',
                'delivery_status',
                'read_receipt',
                'healthcheck',
            ],
            'prepared' => [
                'instance_management',
                'qr_bootstrap',
            ],
        ],
        'gowa' => [
            'implemented' => [
                'text',
                'inbound_webhook',
                'delivery_status',
                'read_receipt',
                'healthcheck',
            ],
            'prepared' => [],
        ],
    ];

    /**
     * @return list<string>
     */
    public function implementedFor(string $provider): array
    {
        return $this->matrix[$provider]['implemented'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function preparedFor(string $provider): array
    {
        return $this->matrix[$provider]['prepared'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function unsupportedFor(string $provider): array
    {
        $known = array_map(
            static fn (WhatsappCapability $capability): string => $capability->value,
            WhatsappCapability::cases(),
        );

        return array_values(array_diff(
            $known,
            $this->implementedFor($provider),
            $this->preparedFor($provider),
        ));
    }

    public function isImplemented(string $provider, string $capability): bool
    {
        return in_array($capability, $this->implementedFor($provider), true);
    }

    public function isPrepared(string $provider, string $capability): bool
    {
        return in_array($capability, $this->preparedFor($provider), true);
    }
}
