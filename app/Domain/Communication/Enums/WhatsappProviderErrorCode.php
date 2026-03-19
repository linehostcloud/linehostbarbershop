<?php

namespace App\Domain\Communication\Enums;

enum WhatsappProviderErrorCode: string
{
    case AuthenticationError = 'authentication_error';
    case AuthorizationError = 'authorization_error';
    case ValidationError = 'validation_error';
    case RateLimit = 'rate_limit';
    case TransientNetworkError = 'transient_network_error';
    case TimeoutError = 'timeout_error';
    case ProviderUnavailable = 'provider_unavailable';
    case UnsupportedFeature = 'unsupported_feature';
    case PermanentProviderError = 'permanent_provider_error';
    case WebhookSignatureInvalid = 'webhook_signature_invalid';
    case DuplicateWebhook = 'duplicate_webhook';
    case UnknownError = 'unknown_error';
}
