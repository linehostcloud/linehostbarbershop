<?php

use App\Http\Controllers\Webhooks\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/whatsapp/{provider}', WhatsappWebhookController::class)
    ->name('webhooks.whatsapp');
