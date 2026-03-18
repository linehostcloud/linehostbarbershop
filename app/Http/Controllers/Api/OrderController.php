<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Order\OpenOrderAction;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return OrderResource::collection(
            Order::query()
                ->with([
                    'client',
                    'appointment',
                    'primaryProfessional',
                    'items',
                    'payments',
                    'transactions.payment',
                    'transactions.professional',
                ])
                ->latest('opened_at')
                ->paginate(15),
        );
    }

    public function store(StoreOrderRequest $request, OpenOrderAction $openOrder): OrderResource
    {
        return new OrderResource(
            $openOrder->execute($request->validated()),
        );
    }

    public function show(string $order): OrderResource
    {
        return new OrderResource(
            Order::query()
                ->with([
                    'client',
                    'appointment',
                    'primaryProfessional',
                    'items.service',
                    'items.professional',
                    'payments',
                    'transactions.payment',
                    'transactions.professional',
                ])
                ->findOrFail($order),
        );
    }
}
