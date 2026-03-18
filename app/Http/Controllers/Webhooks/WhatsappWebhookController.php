<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        return response()->json([
            'status' => 'accepted',
            'provider' => $provider,
            'received_at' => now()->toIso8601String(),
            'payload_keys' => array_keys($request->all()),
        ], 202);
    }
}
