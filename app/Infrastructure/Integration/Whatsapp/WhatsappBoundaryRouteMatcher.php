<?php

namespace App\Infrastructure\Integration\Whatsapp;

use Illuminate\Http\Request;

class WhatsappBoundaryRouteMatcher
{
    public function matches(Request $request): bool
    {
        return $this->isOutbound($request) || $this->isWebhook($request);
    }

    public function isOutbound(Request $request): bool
    {
        return $request->is('api/v1/messages/whatsapp');
    }

    public function isWebhook(Request $request): bool
    {
        return $request->is('webhooks/whatsapp/*');
    }

    public function direction(Request $request): string
    {
        return $this->isWebhook($request) ? 'webhook' : 'outbound';
    }
}
