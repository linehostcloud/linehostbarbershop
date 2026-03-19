<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Client\SnoozeClientReactivationAction;
use App\Application\Actions\Communication\RecordWhatsappProductAuditAction;
use App\Domain\Client\Models\Client;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantWhatsappClientReactivationSnoozeController extends Controller
{
    public function __invoke(
        Request $request,
        Client $client,
        SnoozeClientReactivationAction $snoozeReactivation,
        RecordWhatsappProductAuditAction $recordAudit,
    ): RedirectResponse {
        $before = [
            'whatsapp_reactivation_snoozed_until' => $client->whatsapp_reactivation_snoozed_until?->toIso8601String(),
        ];

        try {
            $result = $snoozeReactivation->execute($client);
        } catch (ValidationException $exception) {
            return redirect()
                ->back()
                ->withErrors($exception->errors())
                ->withInput();
        }

        $client->refresh();

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_product.client_reactivation.snoozed',
            auditable: $client,
            before: $before,
            after: [
                'whatsapp_reactivation_snoozed_until' => $client->whatsapp_reactivation_snoozed_until?->toIso8601String(),
            ],
            metadata: [
                'automation_id' => $result['automation']->id,
                'surface' => 'manager_relationship_panel',
                'days' => $result['days'],
            ],
        );

        return redirect()
            ->back()
            ->with('status', sprintf(
                'Cliente ignorado para reativação até %s.',
                $result['snoozed_until']->format('d/m/Y H:i')
            ));
    }
}
