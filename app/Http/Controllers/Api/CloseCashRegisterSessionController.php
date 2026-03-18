<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Finance\CloseCashRegisterSessionAction;
use App\Domain\Finance\Models\CashRegisterSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CloseCashRegisterSessionRequest;
use App\Http\Resources\CashRegisterSessionResource;

class CloseCashRegisterSessionController extends Controller
{
    public function __invoke(
        string $cashRegisterSession,
        CloseCashRegisterSessionRequest $request,
        CloseCashRegisterSessionAction $closeCashRegisterSession,
    ): CashRegisterSessionResource {
        return new CashRegisterSessionResource(
            $closeCashRegisterSession->execute(
                CashRegisterSession::query()->findOrFail($cashRegisterSession),
                $request->validated(),
            ),
        );
    }
}
