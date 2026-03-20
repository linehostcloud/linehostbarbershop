<?php

use App\Http\Controllers\Web\LandlordSessionController;
use App\Http\Controllers\Web\LandlordTenantController;
use App\Http\Controllers\Web\TenantWhatsappAgentGovernanceController;
use App\Http\Controllers\Web\TenantWhatsappAppointmentConfirmationController;
use App\Http\Controllers\Web\TenantWhatsappAppointmentReminderController;
use App\Http\Controllers\Web\TenantWhatsappAutomationGovernanceController;
use App\Http\Controllers\Web\TenantWhatsappClientReactivationController;
use App\Http\Controllers\Web\TenantWhatsappClientReactivationSnoozeController;
use App\Http\Controllers\Web\TenantWhatsappGovernancePanelController;
use App\Http\Controllers\Web\TenantWhatsappOperationsPanelController;
use App\Http\Controllers\Web\TenantWhatsappOperationsPanelLoginController;
use App\Http\Controllers\Web\TenantWhatsappOperationsPanelLogoutController;
use App\Http\Controllers\Web\TenantWhatsappRelationshipPanelController;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('landlord.central')->prefix((string) config('landlord.panel.path_prefix', 'painel/saas'))->group(function (): void {
    Route::get('/login', [LandlordSessionController::class, 'create'])->name('login');
    Route::post('/login', [LandlordSessionController::class, 'store'])->name('landlord.login.store');
    Route::post('/logout', [LandlordSessionController::class, 'destroy'])
        ->middleware('auth')
        ->name('landlord.logout');

    Route::middleware(['auth', 'landlord.admin'])->group(function (): void {
        Route::get('/tenants', [LandlordTenantController::class, 'index'])->name('landlord.tenants.index');
        Route::get('/tenants/novo', [LandlordTenantController::class, 'create'])->name('landlord.tenants.create');
        Route::post('/tenants', [LandlordTenantController::class, 'store'])->name('landlord.tenants.store');
        Route::get('/tenants/{tenant}', [LandlordTenantController::class, 'show'])->name('landlord.tenants.show');
        Route::patch('/tenants/{tenant}/dados-basicos', [LandlordTenantController::class, 'updateBasics'])
            ->name('landlord.tenants.update-basics');
        Route::patch('/tenants/{tenant}/status', [LandlordTenantController::class, 'changeStatus'])
            ->name('landlord.tenants.change-status');
        Route::patch('/tenants/{tenant}/onboarding', [LandlordTenantController::class, 'transitionOnboardingStage'])
            ->name('landlord.tenants.transition-onboarding-stage');
        Route::post('/tenants/{tenant}/dominios', [LandlordTenantController::class, 'storeDomain'])
            ->name('landlord.tenants.domains.store');
        Route::post('/tenants/{tenant}/dominios/{domain}/principal', [LandlordTenantController::class, 'setPrimaryDomain'])
            ->name('landlord.tenants.domains.set-primary');
        Route::post('/tenants/{tenant}/schema/sincronizar', [LandlordTenantController::class, 'syncSchema'])
            ->name('landlord.tenants.sync-schema');
        Route::post('/tenants/{tenant}/automacoes/defaults', [LandlordTenantController::class, 'ensureDefaultAutomations'])
            ->name('landlord.tenants.ensure-default-automations');
    });
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

    Route::get('/painel/gestao/whatsapp', TenantWhatsappRelationshipPanelController::class)
        ->middleware('tenant.auth')
        ->name('tenant.panel.whatsapp.relationship');
    Route::post('/painel/gestao/whatsapp/agendamentos/{appointment}/lembrete', TenantWhatsappAppointmentReminderController::class)
        ->middleware(['tenant.auth', 'tenant.ability:appointments.read', 'tenant.ability:messages.write'])
        ->name('tenant.panel.whatsapp.relationship.appointments.reminder');
    Route::post('/painel/gestao/whatsapp/agendamentos/{appointment}/confirmacao', TenantWhatsappAppointmentConfirmationController::class)
        ->middleware(['tenant.auth', 'tenant.ability:appointments.read', 'tenant.ability:messages.write'])
        ->name('tenant.panel.whatsapp.relationship.appointments.confirmation');
    Route::post('/painel/gestao/whatsapp/clientes/{client}/reativacao', TenantWhatsappClientReactivationController::class)
        ->middleware(['tenant.auth', 'tenant.ability:clients.read', 'tenant.ability:messages.write'])
        ->name('tenant.panel.whatsapp.relationship.clients.reactivation');
    Route::post('/painel/gestao/whatsapp/clientes/{client}/reativacao/ignorar', TenantWhatsappClientReactivationSnoozeController::class)
        ->middleware(['tenant.auth', 'tenant.ability:clients.read', 'tenant.ability:messages.write'])
        ->name('tenant.panel.whatsapp.relationship.clients.reactivation.snooze');

    Route::get('/painel/operacoes/whatsapp/governanca', TenantWhatsappGovernancePanelController::class)
        ->middleware('tenant.auth')
        ->name('tenant.panel.whatsapp.governance');
    Route::patch('/painel/operacoes/whatsapp/governanca/automacoes/{type}', [TenantWhatsappAutomationGovernanceController::class, 'update'])
        ->middleware(['tenant.auth', 'tenant.ability:whatsapp.automations.write'])
        ->name('tenant.panel.whatsapp.governance.automations.update');
    Route::post('/painel/operacoes/whatsapp/governanca/agente/insights/{insight}/resolve', [TenantWhatsappAgentGovernanceController::class, 'resolve'])
        ->middleware(['tenant.auth', 'tenant.ability:whatsapp.agent.write'])
        ->name('tenant.panel.whatsapp.governance.agent.resolve');
    Route::post('/painel/operacoes/whatsapp/governanca/agente/insights/{insight}/ignore', [TenantWhatsappAgentGovernanceController::class, 'ignore'])
        ->middleware(['tenant.auth', 'tenant.ability:whatsapp.agent.write'])
        ->name('tenant.panel.whatsapp.governance.agent.ignore');
    Route::post('/painel/operacoes/whatsapp/governanca/agente/insights/{insight}/execute', [TenantWhatsappAgentGovernanceController::class, 'execute'])
        ->middleware(['tenant.auth', 'tenant.ability:whatsapp.agent.write'])
        ->name('tenant.panel.whatsapp.governance.agent.execute');
});
