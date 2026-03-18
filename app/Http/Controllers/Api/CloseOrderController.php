<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Order\CloseOrderAction;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CloseOrderRequest;
use App\Http\Resources\OrderResource;

class CloseOrderController extends Controller
{
    public function __invoke(string $order, CloseOrderRequest $request, CloseOrderAction $closeOrder): OrderResource
    {
        return new OrderResource(
            $closeOrder->execute(
                Order::query()->findOrFail($order),
                $request->validated(),
            ),
        );
    }
}
