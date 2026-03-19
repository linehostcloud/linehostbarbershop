<?php

namespace App\Application\Listeners\Observability;

use App\Application\Actions\Observability\RecordEventLogAction;
use App\Domain\Order\Events\OrderClosed;

class RecordOrderClosedDomainEvent
{
    public function __construct(
        private readonly RecordEventLogAction $recordEventLog,
    ) {}

    public function handle(OrderClosed $event): void
    {
        $order = $event->order->loadMissing('items.professional', 'items.service', 'payments');

        $this->recordEventLog->execute(
            eventName: 'order.closed',
            aggregateType: 'order',
            aggregateId: $order->id,
            triggerSource: 'domain_event',
            payload: [
                'order_id' => $order->id,
                'client_id' => $order->client_id,
                'appointment_id' => $order->appointment_id,
                'status' => $order->status,
                'subtotal_cents' => $order->subtotal_cents,
                'discount_cents' => $order->discount_cents,
                'fee_cents' => $order->fee_cents,
                'total_cents' => $order->total_cents,
                'amount_paid_cents' => $order->amount_paid_cents,
                'closed_at' => $order->closed_at?->toIso8601String(),
                'items' => $order->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'service_id' => $item->service_id,
                    'professional_id' => $item->professional_id,
                    'type' => $item->type,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'total_price_cents' => $item->total_price_cents,
                    'commission_percent' => $item->commission_percent,
                ])->values()->all(),
                'payments' => $order->payments->map(fn ($payment): array => [
                    'id' => $payment->id,
                    'provider' => $payment->provider,
                    'amount_cents' => $payment->amount_cents,
                    'status' => $payment->status,
                ])->values()->all(),
            ],
            context: [
                'payments_count' => $order->payments->count(),
                'items_count' => $order->items->count(),
            ],
        );
    }
}
