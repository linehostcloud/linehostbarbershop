<?php

namespace App\Domain\Order\Events;

use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderClosed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order)
    {
    }
}
