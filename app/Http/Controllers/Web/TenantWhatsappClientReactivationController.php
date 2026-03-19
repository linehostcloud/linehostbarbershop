<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Client\QueueManualClientReactivationAction;
use App\Application\Actions\Communication\RecordWhatsappProductAuditAction;
use App\Domain\Client\Models\Client;
use App\Http\Controllers\Controller;
use App\Infrastructure\Auth\TenantAuthContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantWhatsappClientReactivationController extends Controller
{
    public function __invoke(
        Request $request,
        Client $client,
        QueueManualClientReactivationAction $queueReactivation,
        RecordWhatsappProductAuditAction $recordAudit,
        TenantAuthContext $tenantAuthContext,
    ): RedirectResponse {
        $before = [
            'retention_status' => $client->retention_status,
            'last_visit_at' => $client->last_visit_at?->toIso8601String(),
            'inactive_since' => $client->inactive_since?->toIso8601String(),
        ];

        try {
            $result = $queueReactivation->execute($client, $tenantAuthContext->user($request)?->id);
        } catch (ValidationException $exception) {
            return redirect()
                ->back()
                ->withErrors($exception->errors())
                ->withInput();
        }

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_product.client_reactivation.manual_queued',
            auditable: $client,
            before: $before,
            after: [
                'retention_status' => $client->retention_status,
                'last_visit_at' => $client->last_visit_at?->toIso8601String(),
                'inactive_since' => $client->inactive_since?->toIso8601String(),
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
            ->with('status', 'Reativação acionada com sucesso pelo fluxo oficial do WhatsApp.');
    }
}
