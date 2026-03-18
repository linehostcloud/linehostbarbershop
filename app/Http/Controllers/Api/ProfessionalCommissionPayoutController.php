<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Finance\CalculateProfessionalCommissionBalanceAction;
use App\Application\Actions\Finance\RecordCommissionPayoutAction;
use App\Domain\Professional\Models\Professional;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RecordCommissionPayoutRequest;
use App\Http\Resources\TransactionResource;
use Illuminate\Http\JsonResponse;

class ProfessionalCommissionPayoutController extends Controller
{
    public function __invoke(
        string $professional,
        RecordCommissionPayoutRequest $request,
        RecordCommissionPayoutAction $recordCommissionPayout,
        CalculateProfessionalCommissionBalanceAction $calculateProfessionalCommissionBalance,
    ): JsonResponse {
        $professionalModel = Professional::query()->findOrFail($professional);
        $transaction = $recordCommissionPayout->execute($professionalModel, $request->validated());

        return response()->json([
            'data' => [
                'transaction' => (new TransactionResource($transaction))->resolve(),
                'commission_balance' => $calculateProfessionalCommissionBalance->execute($professionalModel),
            ],
        ], 201);
    }
}
