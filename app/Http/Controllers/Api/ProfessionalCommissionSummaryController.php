<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Finance\CalculateProfessionalCommissionBalanceAction;
use App\Domain\Professional\Models\Professional;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ProfessionalCommissionSummaryController extends Controller
{
    public function __invoke(
        string $professional,
        Request $request,
        CalculateProfessionalCommissionBalanceAction $calculateProfessionalCommissionBalance,
    ): JsonResponse {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);

        $professionalModel = Professional::query()->findOrFail($professional);

        return response()->json([
            'data' => [
                'professional_id' => $professionalModel->id,
                ...$calculateProfessionalCommissionBalance->execute(
                    $professionalModel,
                    isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : null,
                ),
            ],
        ]);
    }
}
