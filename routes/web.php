<?php

use App\Http\Controllers\Web\TenantWhatsappAppointmentReminderController;
use App\Http\Controllers\Web\TenantWhatsappAppointmentConfirmationController;
use App\Http\Controllers\Web\TenantWhatsappAgentGovernanceController;
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
