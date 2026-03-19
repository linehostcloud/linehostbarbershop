<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AcceptTenantInvitationController;
use App\Http\Controllers\Api\AdminWhatsappProviderController;
use App\Http\Controllers\Api\AdminWhatsappAutomationController;
use App\Http\Controllers\Api\AdminWhatsappAgentController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\BoundaryRejectionAuditController;
use App\Http\Controllers\Api\CashRegisterMovementController;
use App\Http\Controllers\Api\CashRegisterSessionController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CloseCashRegisterSessionController;
use App\Http\Controllers\Api\CloseOrderController;
use App\Http\Controllers\Api\FinanceSummaryController;
use App\Http\Controllers\Api\EventLogController;
use App\Http\Controllers\Api\IntegrationAttemptController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OutboxEventController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProfessionalController;
use App\Http\Controllers\Api\ProfessionalCommissionPayoutController;
use App\Http\Controllers\Api\ProfessionalCommissionSummaryController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\TenantMembershipAuditController;
use App\Http\Controllers\Api\TenantContextController;
use App\Http\Controllers\Api\TenantUserController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\InviteTenantUserController;
use App\Http\Controllers\Api\ResetTenantUserPasswordController;
use App\Http\Controllers\Api\UpdateTenantMembershipController;
use App\Http\Controllers\Api\WhatsappOperationsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/ping', fn (Request $request) => response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'host' => $request->getHost(),
    ]));

    Route::middleware('tenant.resolve')->group(function (): void {
        Route::get('/tenant/context', TenantContextController::class)
            ->name('api.tenant.context');

        Route::post('/auth/login', LoginController::class);
        Route::post('/tenant-users/invitations/accept', AcceptTenantInvitationController::class);

        Route::middleware('tenant.auth')->group(function (): void {
            Route::get('/auth/me', MeController::class);
            Route::post('/auth/logout', LogoutController::class);

            Route::get('/tenant-users', [TenantUserController::class, 'index'])
                ->middleware('tenant.ability:tenant.users.read');
            Route::get('/tenant-users/audits', TenantMembershipAuditController::class)
                ->middleware('tenant.ability:tenant.users.read');
            Route::post('/tenant-users/invitations', InviteTenantUserController::class)
                ->middleware('tenant.ability:tenant.users.write');
            Route::patch('/tenant-users/{membership}', UpdateTenantMembershipController::class)
                ->middleware('tenant.ability:tenant.users.write');
            Route::post('/tenant-users/{membership}/reset-password', ResetTenantUserPasswordController::class)
                ->middleware('tenant.ability:tenant.users.write');

            Route::get('/clients', [ClientController::class, 'index'])
                ->middleware('tenant.ability:clients.read');
            Route::post('/clients', [ClientController::class, 'store'])
                ->middleware('tenant.ability:clients.write');
            Route::get('/clients/{client}', [ClientController::class, 'show'])
                ->middleware('tenant.ability:clients.read');

            Route::get('/professionals', [ProfessionalController::class, 'index'])
                ->middleware('tenant.ability:professionals.read');
            Route::post('/professionals', [ProfessionalController::class, 'store'])
                ->middleware('tenant.ability:professionals.write');
            Route::get('/professionals/{professional}', [ProfessionalController::class, 'show'])
                ->middleware('tenant.ability:professionals.read');
            Route::get('/professionals/{professional}/commission-summary', ProfessionalCommissionSummaryController::class)
                ->middleware('tenant.ability:finance.read');
            Route::post('/professionals/{professional}/commission-payouts', ProfessionalCommissionPayoutController::class)
                ->middleware('tenant.ability:finance.write');

            Route::get('/services', [ServiceController::class, 'index'])
                ->middleware('tenant.ability:services.read');
            Route::post('/services', [ServiceController::class, 'store'])
                ->middleware('tenant.ability:services.write');
            Route::get('/services/{service}', [ServiceController::class, 'show'])
                ->middleware('tenant.ability:services.read');

            Route::get('/appointments', [AppointmentController::class, 'index'])
                ->middleware('tenant.ability:appointments.read');
            Route::post('/appointments', [AppointmentController::class, 'store'])
                ->middleware('tenant.ability:appointments.write');
            Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])
                ->middleware('tenant.ability:appointments.read');

            Route::get('/orders', [OrderController::class, 'index'])
                ->middleware('tenant.ability:orders.read');
            Route::post('/orders', [OrderController::class, 'store'])
                ->middleware('tenant.ability:orders.write');
            Route::get('/orders/{order}', [OrderController::class, 'show'])
                ->middleware('tenant.ability:orders.read');
            Route::post('/orders/{order}/close', CloseOrderController::class)
                ->middleware('tenant.ability:orders.write');

            Route::get('/payments', [PaymentController::class, 'index'])
                ->middleware('tenant.ability:finance.read');
            Route::get('/payments/{payment}', [PaymentController::class, 'show'])
                ->middleware('tenant.ability:finance.read');

            Route::get('/transactions', [TransactionController::class, 'index'])
                ->middleware('tenant.ability:finance.read');
            Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])
                ->middleware('tenant.ability:finance.read');

            Route::get('/cash-register-sessions', [CashRegisterSessionController::class, 'index'])
                ->middleware('tenant.ability:finance.read');
            Route::post('/cash-register-sessions', [CashRegisterSessionController::class, 'store'])
                ->middleware('tenant.ability:finance.write');
            Route::get('/cash-register-sessions/{cashRegisterSession}', [CashRegisterSessionController::class, 'show'])
                ->middleware('tenant.ability:finance.read');
            Route::post('/cash-register-sessions/{cashRegisterSession}/movements', CashRegisterMovementController::class)
                ->middleware('tenant.ability:finance.write');
            Route::post('/cash-register-sessions/{cashRegisterSession}/close', CloseCashRegisterSessionController::class)
                ->middleware('tenant.ability:finance.write');

            Route::get('/finance/summary', FinanceSummaryController::class)
                ->middleware('tenant.ability:finance.read');

            Route::get('/messages', [MessageController::class, 'index'])
                ->middleware('tenant.ability:messages.read');
            Route::post('/messages/whatsapp', [MessageController::class, 'storeWhatsapp'])
                ->middleware('tenant.ability:messages.write');
            Route::get('/messages/{message}', [MessageController::class, 'show'])
                ->middleware('tenant.ability:messages.read');

            Route::get('/admin/whatsapp-providers', [AdminWhatsappProviderController::class, 'index'])
                ->middleware('tenant.ability:whatsapp.providers.read');
            Route::get('/admin/whatsapp-providers/{slot}', [AdminWhatsappProviderController::class, 'show'])
                ->middleware('tenant.ability:whatsapp.providers.read');
            Route::post('/admin/whatsapp-providers', [AdminWhatsappProviderController::class, 'store'])
                ->middleware('tenant.ability:whatsapp.providers.write');
            Route::patch('/admin/whatsapp-providers/{slot}', [AdminWhatsappProviderController::class, 'update'])
                ->middleware('tenant.ability:whatsapp.providers.write');
            Route::post('/admin/whatsapp-providers/{slot}/activate', [AdminWhatsappProviderController::class, 'activate'])
                ->middleware('tenant.ability:whatsapp.providers.write');
            Route::post('/admin/whatsapp-providers/{slot}/deactivate', [AdminWhatsappProviderController::class, 'deactivate'])
                ->middleware('tenant.ability:whatsapp.providers.write');
            Route::post('/admin/whatsapp-providers/{slot}/healthcheck', [AdminWhatsappProviderController::class, 'healthcheck'])
                ->middleware('tenant.ability:whatsapp.providers.healthcheck');

            Route::get('/admin/whatsapp-automations', [AdminWhatsappAutomationController::class, 'index'])
                ->middleware('tenant.ability:whatsapp.automations.read');
            Route::get('/admin/whatsapp-automations/runs', [AdminWhatsappAutomationController::class, 'runs'])
                ->middleware('tenant.ability:whatsapp.automations.read');
            Route::get('/admin/whatsapp-automations/{type}', [AdminWhatsappAutomationController::class, 'show'])
                ->middleware('tenant.ability:whatsapp.automations.read');
            Route::patch('/admin/whatsapp-automations/{type}', [AdminWhatsappAutomationController::class, 'update'])
                ->middleware('tenant.ability:whatsapp.automations.write');

            Route::get('/admin/whatsapp-agent/insights', [AdminWhatsappAgentController::class, 'index'])
                ->middleware('tenant.ability:whatsapp.agent.read');
            Route::get('/admin/whatsapp-agent/runs/latest', [AdminWhatsappAgentController::class, 'latestRun'])
                ->middleware('tenant.ability:whatsapp.agent.read');
            Route::get('/admin/whatsapp-agent/runs', [AdminWhatsappAgentController::class, 'runs'])
                ->middleware('tenant.ability:whatsapp.agent.read');
            Route::post('/admin/whatsapp-agent/insights/{insight}/resolve', [AdminWhatsappAgentController::class, 'resolve'])
                ->middleware('tenant.ability:whatsapp.agent.write');
            Route::post('/admin/whatsapp-agent/insights/{insight}/ignore', [AdminWhatsappAgentController::class, 'ignore'])
                ->middleware('tenant.ability:whatsapp.agent.write');
            Route::post('/admin/whatsapp-agent/insights/{insight}/execute', [AdminWhatsappAgentController::class, 'execute'])
                ->middleware('tenant.ability:whatsapp.agent.write');

            Route::get('/operations/whatsapp/summary', [WhatsappOperationsController::class, 'summary'])
                ->middleware('tenant.ability:whatsapp.operations.read');
            Route::get('/operations/whatsapp/providers', [WhatsappOperationsController::class, 'providers'])
                ->middleware('tenant.ability:whatsapp.operations.read');
            Route::get('/operations/whatsapp/agent', [WhatsappOperationsController::class, 'agent'])
                ->middleware('tenant.ability:whatsapp.operations.read');
            Route::get('/operations/whatsapp/queue', [WhatsappOperationsController::class, 'queue'])
                ->middleware('tenant.ability:whatsapp.operations.read');
            Route::get('/operations/whatsapp/boundary-rejections/summary', [WhatsappOperationsController::class, 'boundarySummary'])
                ->middleware('tenant.ability:whatsapp.operations.read');
            Route::get('/operations/whatsapp/boundary-rejections', [WhatsappOperationsController::class, 'boundaryRejections'])
                ->middleware('tenant.ability:whatsapp.operations.read');
            Route::get('/operations/whatsapp/feed', [WhatsappOperationsController::class, 'feed'])
                ->middleware('tenant.ability:whatsapp.operations.read');

            Route::get('/event-logs', EventLogController::class)
                ->middleware('tenant.ability:observability.read');
            Route::get('/outbox-events', OutboxEventController::class)
                ->middleware('tenant.ability:observability.read');
            Route::get('/integration-attempts', IntegrationAttemptController::class)
                ->middleware('tenant.ability:observability.read');
            Route::get('/boundary-rejection-audits', BoundaryRejectionAuditController::class)
                ->middleware('tenant.ability:observability.read');
        });
    });
});
