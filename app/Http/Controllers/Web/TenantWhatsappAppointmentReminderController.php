<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Appointment\QueueManualAppointmentReminderAction;
use App\Application\Actions\Communication\RecordWhatsappProductAuditAction;
use App\Domain\Appointment\Models\Appointment;
use App\Http\Controllers\Controller;
use App\Infrastructure\Auth\TenantAuthContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantWhatsappAppointmentReminderController extends Controller
{
    public function __invoke(
        Request $request,
        Appointment $appointment,
        QueueManualAppointmentReminderAction $queueReminder,
        RecordWhatsappProductAuditAction $recordAudit,
        TenantAuthContext $tenantAuthContext,
    ): RedirectResponse {
        $before = [
            'reminder_sent_at' => $appointment->reminder_sent_at?->toIso8601String(),
            'confirmation_status' => $appointment->confirmation_status,
            'status' => $appointment->status,
        ];

        try {
            $result = $queueReminder->execute($appointment, $tenantAuthContext->user($request)?->id);
        } catch (ValidationException $exception) {
            return redirect()
                ->back()
                ->withErrors($exception->errors())
                ->withInput();
        }

        $appointment->refresh();

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_product.appointment_reminder.manual_queued',
            auditable: $appointment,
            before: $before,
            after: [
                'reminder_sent_at' => $appointment->reminder_sent_at?->toIso8601String(),
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
            ->with('status', 'Lembrete reenfileirado com sucesso pelo fluxo oficial do WhatsApp.');
    }
}
