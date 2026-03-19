<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Application\Actions\Automation\RecordWhatsappAutomationAdminAuditAction;
use App\Application\Actions\Automation\SummarizeWhatsappAutomationGovernanceAction;
use App\Application\Actions\Automation\UpdateWhatsappAutomationConfigurationAction;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAdminWhatsappAutomationRequest;
use App\Infrastructure\Automation\WhatsappAutomationConfigViewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWhatsappAutomationController extends Controller
{
    public function index(
        EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        SummarizeWhatsappAutomationGovernanceAction $summarizeAutomations,
        WhatsappAutomationConfigViewFactory $viewFactory,
    ): JsonResponse {
        $ensureDefaults->execute();
        $automations = Automation::query()
            ->where('channel', 'whatsapp')
            ->with('latestRun')
            ->orderBy('priority')
            ->orderBy('trigger_event')
            ->get();
        $metrics = $summarizeAutomations->execute($automations->pluck('id')->all());

        $data = $automations
            ->map(fn (Automation $automation): array => $viewFactory->governanceSummary(
                $automation,
                $metrics[$automation->id] ?? [],
                $automation->latestRun,
            ))
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
        ]);
    }

    public function show(
        string $type,
        EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        SummarizeWhatsappAutomationGovernanceAction $summarizeAutomations,
        WhatsappAutomationConfigViewFactory $viewFactory,
    ): JsonResponse {
        $ensureDefaults->execute();
        $automation = $this->findByType($type);

        return response()->json([
            'data' => $viewFactory->governanceSummary(
                $automation,
                $summarizeAutomations->execute([$automation->id])[$automation->id] ?? [],
                $automation->latestRun()->first(),
            ),
        ]);
    }

    public function update(
        string $type,
        UpdateAdminWhatsappAutomationRequest $request,
        EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        SummarizeWhatsappAutomationGovernanceAction $summarizeAutomations,
        UpdateWhatsappAutomationConfigurationAction $updateAutomation,
        RecordWhatsappAutomationAdminAuditAction $recordAudit,
        WhatsappAutomationConfigViewFactory $viewFactory,
    ): JsonResponse {
        $ensureDefaults->execute();
        $automation = $this->findByType($type);
        $before = $viewFactory->snapshot($automation);
        $validated = $request->validated();

        $automation = $updateAutomation->execute($automation, $validated);

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
            'data' => $viewFactory->governanceSummary(
                $automation,
                $summarizeAutomations->execute([$automation->id])[$automation->id] ?? [],
                $automation->latestRun()->first(),
            ),
        ]);
    }

    public function runs(
        Request $request,
        WhatsappAutomationConfigViewFactory $viewFactory,
    ): JsonResponse {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = AutomationRun::query()
            ->where('channel', 'whatsapp')
            ->when(
                isset($validated['type']) && $validated['type'] !== '',
                fn (Builder $query): Builder => $query->where('automation_type', $validated['type']),
            )
            ->latest('started_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (AutomationRun $run): array => $viewFactory->runSummary($run))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
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
