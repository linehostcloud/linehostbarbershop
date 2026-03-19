<?php

namespace App\Domain\Communication\Enums;

enum WhatsappBoundaryRejectionCode: string
{
    case ProviderInvalid = 'provider_invalid';
    case TenantUnresolved = 'tenant_unresolved';
    case ProviderConfigMissing = 'provider_config_missing';
    case ProviderConfigInvalid = 'provider_config_invalid';
    case CapabilityNotSupported = 'capability_not_supported';
    case CapabilityNotEnabled = 'capability_not_enabled';
    case WebhookSignatureInvalid = 'webhook_signature_invalid';
    case EndpointMismatch = 'endpoint_mismatch';
    case PayloadInvalid = 'payload_invalid';
    case SecurityPolicyViolation = 'security_policy_violation';
    case AuthenticationFailed = 'authentication_failed';
    case AuthorizationFailed = 'authorization_failed';
    case UnknownBoundaryError = 'unknown_boundary_error';
}
