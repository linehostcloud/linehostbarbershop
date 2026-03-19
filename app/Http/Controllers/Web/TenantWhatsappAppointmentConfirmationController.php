<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Appointment\QueueManualAppointmentConfirmationAction;
use App\Application\Actions\Communication\RecordWhatsappProductAuditAction;
use App\Domain\Appointment\Models\Appointment;
use App\Http\Controllers\Controller;
use App\Infrastructure\Auth\TenantAuthContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantWhatsappAppointmentConfirmationController extends Controller
{
    public function __invoke(
        Request $request,
        Appointment $appointment,
        QueueManualAppointmentConfirmationAction $queueConfirmation,
        RecordWhatsappProductAuditAction $recordAudit,
        TenantAuthContext $tenantAuthContext,
    ): RedirectResponse {
        $before = [
            'confirmation_status' => $appointment->confirmation_status,
            'status' => $appointment->status,
        ];

        try {
            $result = $queueConfirmation->execute($appointment, $tenantAuthContext->user($request)?->id);
        } catch (ValidationException $exception) {
            return redirect()
                ->back()
                ->withErrors($exception->errors())
                ->withInput();
        }

        $appointment->refresh();

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_product.appointment_confirmation.manual_queued',
            auditable: $appointment,
            before: $before,
            after: [
                'confirmation_status' => $appointment->confirmation_status,
            ],
            metadata: [
                'automation_id' => $result['automation']->id,
                'message_id' => $result['message']->id,
                'automation_run_id' => $result['run_id'],
                'surface' => 'manager_relationship_panel',
            ],
        );

        return redirect()
            ->back()
            ->with('status', 'Solicitação de confirmação enfileirada com sucesso pelo fluxo oficial do WhatsApp.');
    }
}
