<?php

use App\Http\Controllers\Webhooks\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/whatsapp/{provider}', WhatsappWebhookController::class)
    ->middleware('tenant.resolve')
    ->name('webhooks.whatsapp');
