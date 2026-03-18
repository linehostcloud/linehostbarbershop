<?php

namespace App\Http\Controllers\Api;

use App\Domain\Finance\Models\Payment;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PaymentResource::collection(
            Payment::query()
                ->with('client')
                ->latest('paid_at')
                ->latest()
                ->paginate(15),
        );
    }

    public function show(string $payment): PaymentResource
    {
        return new PaymentResource(
            Payment::query()
                ->with('client')
                ->findOrFail($payment),
        );
    }
}
