<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Application\Actions\Automation\RecordWhatsappAutomationAdminAuditAction;
use App\Application\Actions\Automation\UpdateWhatsappAutomationConfigurationAction;
use App\Domain\Automation\Models\Automation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAdminWhatsappAutomationRequest;
use App\Infrastructure\Automation\WhatsappAutomationConfigViewFactory;
use Illuminate\Http\RedirectResponse;

class TenantWhatsappAutomationGovernanceController extends Controller
{
    public function update(
        string $type,
        UpdateAdminWhatsappAutomationRequest $request,
        EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        UpdateWhatsappAutomationConfigurationAction $updateAutomation,
        RecordWhatsappAutomationAdminAuditAction $recordAudit,
        WhatsappAutomationConfigViewFactory $viewFactory,
    ): RedirectResponse {
        $ensureDefaults->execute();
        $automation = $this->findByType($type);
        $before = $viewFactory->snapshot($automation);
        $validated = $request->validated();
        $automation = $updateAutomation->execute($automation, $validated);

        $statusChanged = array_key_exists('status', $validated) && $before['status'] !== $automation->status;
        $otherChanges = array_diff(array_keys($validated), ['status']) !== [];
        $action = match (true) {
            $statusChanged && ! $otherChanges && $automation->status === 'active' => 'whatsapp_automation.activated',
            $statusChanged && ! $otherChanges && $automation->status === 'inactive' => 'whatsapp_automation.deactivated',
            default => 'whatsapp_automation.updated',
        };

        $recordAudit->execute(
            request: $request,
            action: $action,
            automation: $automation,
            before: $before,
            after: $viewFactory->snapshot($automation),
            metadata: [
                'request_payload' => $validated,
                'status_transition' => $statusChanged ? [
                    'from' => $before['status'],
                    'to' => $automation->status,
                ] : null,
            ],
        );

        return redirect()->back()->with('status', sprintf('Automação %s atualizada com sucesso.', $automation->trigger_event));
    }

    private function findByType(string $type): Automation
    {
        return Automation::query()
            ->where('channel', 'whatsapp')
            ->where('trigger_event', $type)
            ->firstOrFail();
    }
}
