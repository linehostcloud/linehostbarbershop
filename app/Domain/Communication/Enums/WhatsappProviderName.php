<?php

namespace App\Domain\Communication\Enums;

enum WhatsappProviderName: string
{
    case Fake = 'fake';
    case FakeTransientFailure = 'fake-transient-failure';
    case WhatsAppCloud = 'whatsapp_cloud';
    case EvolutionApi = 'evolution_api';
    case GoWa = 'gowa';

    public function isTestingProvider(): bool
    {
        return in_array($this, [self::Fake, self::FakeTransientFailure], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $provider): string => $provider->value,
            self::cases(),
        );
    }
}
