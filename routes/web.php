<?php

use App\Http\Controllers\Web\TenantWhatsappOperationsPanelController;
use App\Http\Controllers\Web\TenantWhatsappOperationsPanelLoginController;
use App\Http\Controllers\Web\TenantWhatsappOperationsPanelLogoutController;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('tenant.resolve')->group(function (): void {
    Route::get('/painel/operacoes/whatsapp/login', function (TenantContext $tenantContext) {
        return view('tenant.panel.whatsapp.login', [
            'tenant' => $tenantContext->current(),
        ]);
    })->name('tenant.panel.whatsapp.operations.login');
    Route::post('/painel/operacoes/whatsapp/login', TenantWhatsappOperationsPanelLoginController::class)
        ->name('tenant.panel.whatsapp.operations.login.submit');
    Route::post('/painel/operacoes/whatsapp/logout', TenantWhatsappOperationsPanelLogoutController::class)
        ->name('tenant.panel.whatsapp.operations.logout');

    Route::get('/painel/operacoes/whatsapp', TenantWhatsappOperationsPanelController::class)
        ->middleware(['tenant.auth', 'tenant.ability:whatsapp.operations.read'])
        ->name('tenant.panel.whatsapp.operations');
});
