<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Finance\OpenCashRegisterSessionAction;
use App\Domain\Finance\Models\CashRegisterSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OpenCashRegisterSessionRequest;
use App\Http\Resources\CashRegisterSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashRegisterSessionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CashRegisterSessionResource::collection(
            CashRegisterSession::query()
                ->withCount('transactions')
                ->latest('opened_at')
                ->paginate(15),
        );
    }

    public function store(
        OpenCashRegisterSessionRequest $request,
        OpenCashRegisterSessionAction $openCashRegisterSession,
    ): JsonResponse {
        return (new CashRegisterSessionResource(
            $openCashRegisterSession->execute($request->validated()),
        ))->response()->setStatusCode(201);
    }

    public function show(string $cashRegisterSession): CashRegisterSessionResource
    {
        return new CashRegisterSessionResource(
            CashRegisterSession::query()
                ->withCount('transactions')
                ->with([
                    'transactions.payment',
                    'transactions.professional',
                ])
                ->findOrFail($cashRegisterSession),
        );
    }
}
