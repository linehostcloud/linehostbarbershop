<?php

namespace App\Http\Controllers\Api;

use App\Domain\Finance\Models\Transaction;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TransactionResource::collection(
            Transaction::query()
                ->with(['payment', 'professional'])
                ->latest('occurred_on')
                ->latest()
                ->paginate(30),
        );
    }

    public function show(string $transaction): TransactionResource
    {
        return new TransactionResource(
            Transaction::query()
                ->with(['payment', 'professional'])
                ->findOrFail($transaction),
        );
    }
}
