<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Finance\RecordCashMovementAction;
use App\Domain\Finance\Models\CashRegisterSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RecordCashMovementRequest;
use App\Http\Resources\TransactionResource;
use Illuminate\Http\JsonResponse;

class CashRegisterMovementController extends Controller
{
    public function __invoke(
        string $cashRegisterSession,
        RecordCashMovementRequest $request,
        RecordCashMovementAction $recordCashMovement,
    ): JsonResponse {
        return (new TransactionResource(
            $recordCashMovement->execute(
                CashRegisterSession::query()->findOrFail($cashRegisterSession),
                $request->validated(),
            ),
        ))->response()->setStatusCode(201);
    }
}
