<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Application\Actions\Automation\RecordWhatsappAutomationAdminAuditAction;
use App\Domain\Automation\Models\Automation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAdminWhatsappAutomationRequest;
use App\Infrastructure\Automation\WhatsappAutomationConfigViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWhatsappAutomationController extends Controller
{
    public function index(
        EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        WhatsappAutomationConfigViewFactory $viewFactory,
    ): JsonResponse {
        $data = $ensureDefaults->execute()
            ->map(fn (Automation $automation): array => $viewFactory->summary($automation))
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
        ]);
    }

    public function show(
        string $type,
        EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        WhatsappAutomationConfigViewFactory $viewFactory,
    ): JsonResponse {
        $ensureDefaults->execute();

        return response()->json([
            'data' => $viewFactory->detail($this->findByType($type)),
        ]);
    }

    public function update(
        string $type,
        UpdateAdminWhatsappAutomationRequest $request,
        EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        RecordWhatsappAutomationAdminAuditAction $recordAudit,
        WhatsappAutomationConfigViewFactory $viewFactory,
    ): JsonResponse {
        $ensureDefaults->execute();
        $automation = $this->findByType($type);
        $before = $viewFactory->snapshot($automation);
        $validated = $request->validated();

        $automation->fill([
            'name' => $validated['name'] ?? $automation->name,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $automation->description,
            'status' => $validated['status'] ?? $automation->status,
            'conditions_json' => array_key_exists('conditions', $validated)
                ? array_replace_recursive($automation->conditions_json ?? [], $validated['conditions'])
                : $automation->conditions_json,
            'action_payload_json' => array_key_exists('message', $validated)
                ? array_replace_recursive($automation->action_payload_json ?? [], $validated['message'])
                : $automation->action_payload_json,
            'cooldown_hours' => $validated['cooldown_hours'] ?? $automation->cooldown_hours,
            'priority' => $validated['priority'] ?? $automation->priority,
        ])->save();

        $automation->refresh();

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_automation.updated',
            automation: $automation,
            before: $before,
            after: $viewFactory->snapshot($automation),
            metadata: [
                'request_payload' => $validated,
            ],
        );

        return response()->json([
            'data' => $viewFactory->detail($automation),
        ]);
    }

    private function findByType(string $type): Automation
    {
        return Automation::query()
            ->where('channel', 'whatsapp')
            ->where('trigger_event', $type)
            ->firstOrFail();
    }
}
