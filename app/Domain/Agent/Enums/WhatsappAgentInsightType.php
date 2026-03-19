<?php

namespace App\Domain\Agent\Enums;

enum WhatsappAgentInsightType: string
{
    case ProviderHealthAlert = 'provider_health_alert';
    case AutomationOpportunityReactivation = 'automation_opportunity_reactivation';
    case AutomationOpportunityReminder = 'automation_opportunity_reminder';
    case DuplicateRiskAlert = 'duplicate_risk_alert';
    case DeliveryInstabilityAlert = 'delivery_instability_alert';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
