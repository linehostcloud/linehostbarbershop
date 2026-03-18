<?php

namespace App\Application\Actions\Order;

use App\Application\Actions\Finance\SyncCashRegisterSessionBalanceAction;
use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Order\Events\OrderClosed;
use App\Domain\Order\Models\Order;
use App\Domain\Professional\Models\Professional;
use App\Domain\Service\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseOrderAction
{
    public function __construct(
        private readonly SyncCashRegisterSessionBalanceAction $syncCashRegisterSessionBalance,
    ) {}

    /**
     * @var list<string>
     */
    private const SETTLED_PAYMENT_STATUSES = ['paid', 'captured', 'settled'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(Order $order, array $payload): Order
    {
        if ($order->status !== 'open') {
            throw ValidationException::withMessages([
                'status' => 'Apenas comandas abertas podem ser fechadas.',
            ]);
        }

        $closedAt = isset($payload['closed_at']) ? Carbon::parse($payload['closed_at']) : now();

        $closedOrder = DB::connection(config('tenancy.tenant_connection', 'tenant'))
            ->transaction(function () use ($order, $payload, $closedAt) {
                foreach ($payload['items'] ?? [] as $itemPayload) {
                    $service = isset($itemPayload['service_id']) ? Service::query()->findOrFail($itemPayload['service_id']) : null;
                    $professional = isset($itemPayload['professional_id']) ? Professional::query()->findOrFail($itemPayload['professional_id']) : null;
                    $quantity = (float) ($itemPayload['quantity'] ?? 1);
                    $unitPrice = (int) $itemPayload['unit_price_cents'];
                    $totalPrice = (int) round($quantity * $unitPrice);
                    $commissionPercent = $this->resolveCommissionPercent(
                        $itemPayload,
                        $service,
                        $professional,
                    );

                    $order->items()->create([
                        'service_id' => $service?->id,
                        'professional_id' => $professional?->id,
                        'subscription_id' => $itemPayload['subscription_id'] ?? null,
                        'type' => $itemPayload['type'] ?? 'service',
                        'description' => $itemPayload['description'],
                        'quantity' => $quantity,
                        'unit_price_cents' => $unitPrice,
                        'total_price_cents' => $totalPrice,
                        'commission_percent' => $commissionPercent,
                        'metadata_json' => $itemPayload['metadata_json'] ?? null,
                    ]);
                }

                $order->load('items', 'appointment');

                $subtotal = (int) $order->items->sum('total_price_cents');
                $discount = (int) ($payload['discount_cents'] ?? 0);
                $fee = (int) ($payload['fee_cents'] ?? 0);
                $total = max(0, $subtotal - $discount + $fee);
                $amountPaid = $this->resolveAmountPaid($payload, $total);

                $order->fill([
                    'closed_by_user_id' => $payload['closed_by_user_id'] ?? null,
                    'subtotal_cents' => $subtotal,
                    'discount_cents' => $discount,
                    'fee_cents' => $fee,
                    'total_cents' => $total,
                    'amount_paid_cents' => $amountPaid,
                    'status' => 'closed',
                    'closed_at' => $closedAt,
                    'notes' => $payload['notes'] ?? $order->notes,
                ])->save();

                $this->recordPaymentsAndTransactions(
                    $order,
                    $payload,
                    $closedAt,
                    $amountPaid,
                );

                $this->recordCommissionTransactions($order, $closedAt);

                if (($payload['mark_appointment_completed'] ?? true) && $order->appointment !== null) {
                    $order->appointment->forceFill([
                        'status' => 'completed',
                        'completed_at' => now(),
                    ])->save();
                }

                return $order->fresh([
                    'client',
                    'appointment',
                    'primaryProfessional',
                    'items.service',
                    'items.professional',
                    'payments',
                    'transactions.payment',
                    'transactions.professional',
                ]);
            });

        event(new OrderClosed($closedOrder));

        return $closedOrder;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveAmountPaid(array $payload, int $total): int
    {
        $requestedAmountPaid = isset($payload['amount_paid_cents'])
            ? (int) $payload['amount_paid_cents']
            : null;
        $payments = $payload['payments'] ?? [];

        if ($payments === []) {
            return $requestedAmountPaid ?? $total;
        }

        $settledAmount = 0;

        foreach ($payments as $payment) {
            if ($this->isSettledPaymentStatus($payment['status'] ?? 'paid')) {
                $settledAmount += (int) $payment['amount_cents'];
            }
        }

        if ($requestedAmountPaid !== null && $requestedAmountPaid !== $settledAmount) {
            throw ValidationException::withMessages([
                'amount_paid_cents' => 'O valor pago deve corresponder à soma dos pagamentos liquidados.',
            ]);
        }

        return $requestedAmountPaid ?? $settledAmount;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordPaymentsAndTransactions(
        Order $order,
        array $payload,
        Carbon $closedAt,
        int $amountPaid,
    ): void {
        /** @var array<string, CashRegisterSession> $touchedCashRegisterSessions */
        $touchedCashRegisterSessions = [];

        foreach ($this->normalizePayments($payload, $closedAt, $amountPaid) as $paymentPayload) {
            $cashRegisterSession = $this->resolveCashRegisterSession(
                $paymentPayload['provider'],
                $paymentPayload['cash_register_session_id'],
            );

            $payment = $order->payments()->create([
                'client_id' => $order->client_id,
                'provider' => $paymentPayload['provider'],
                'gateway' => $paymentPayload['gateway'],
                'external_reference' => $paymentPayload['external_reference'],
                'amount_cents' => $paymentPayload['amount_cents'],
                'currency' => $paymentPayload['currency'],
                'installment_count' => $paymentPayload['installment_count'],
                'status' => $paymentPayload['status'],
                'paid_at' => $paymentPayload['paid_at'],
                'due_at' => $paymentPayload['due_at'],
                'failure_reason' => $paymentPayload['failure_reason'],
                'metadata_json' => $paymentPayload['metadata_json'],
            ]);

            if (! $this->isSettledPaymentStatus($payment->status)) {
                continue;
            }

            $order->transactions()->create([
                'payment_id' => $payment->id,
                'cash_register_session_id' => $cashRegisterSession?->id,
                'occurred_on' => $closedAt->toDateString(),
                'type' => 'income',
                'category' => 'order_revenue',
                'description' => sprintf('Receita da comanda %s via %s', $order->id, $payment->provider),
                'amount_cents' => $payment->amount_cents,
                'balance_direction' => 'credit',
                'reconciled' => false,
                'metadata_json' => [
                    'order_id' => $order->id,
                    'payment_provider' => $payment->provider,
                ],
            ]);

            if ($cashRegisterSession !== null) {
                $touchedCashRegisterSessions[$cashRegisterSession->id] = $cashRegisterSession;
            }
        }

        foreach ($touchedCashRegisterSessions as $cashRegisterSession) {
            $this->syncCashRegisterSessionBalance->execute($cashRegisterSession);
        }
    }

    private function recordCommissionTransactions(Order $order, Carbon $closedAt): void
    {
        $order->loadMissing('items.service', 'items.professional');

        $commissionsByProfessional = [];

        foreach ($order->items as $item) {
            if ($item->professional_id === null || $item->commission_percent === null) {
                continue;
            }

            $commissionAmount = (int) round(
                $item->total_price_cents * (((float) $item->commission_percent) / 100),
            );

            if ($commissionAmount <= 0) {
                continue;
            }

            $commissionsByProfessional[$item->professional_id] = ($commissionsByProfessional[$item->professional_id] ?? 0)
                + $commissionAmount;
        }

        foreach ($commissionsByProfessional as $professionalId => $amountCents) {
            $order->transactions()->create([
                'professional_id' => $professionalId,
                'occurred_on' => $closedAt->toDateString(),
                'type' => 'commission',
                'category' => 'professional_commission',
                'description' => sprintf('Comissão provisionada da comanda %s', $order->id),
                'amount_cents' => $amountCents,
                'balance_direction' => 'debit',
                'reconciled' => false,
                'metadata_json' => [
                    'order_id' => $order->id,
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function normalizePayments(array $payload, Carbon $closedAt, int $amountPaid): array
    {
        $payments = $payload['payments'] ?? [];

        if ($payments === []) {
            if ($amountPaid <= 0) {
                return [];
            }

            return [[
                'provider' => 'cash',
                'gateway' => null,
                'external_reference' => null,
                'amount_cents' => $amountPaid,
                'currency' => 'BRL',
                'installment_count' => 1,
                'status' => 'paid',
                'paid_at' => $closedAt,
                'due_at' => null,
                'failure_reason' => null,
                'metadata_json' => null,
                'cash_register_session_id' => null,
            ]];
        }

        return array_map(function (array $payment) use ($closedAt): array {
            $status = $payment['status'] ?? 'paid';

            return [
                'provider' => $payment['provider'],
                'gateway' => $payment['gateway'] ?? null,
                'external_reference' => $payment['external_reference'] ?? null,
                'amount_cents' => (int) $payment['amount_cents'],
                'currency' => $payment['currency'] ?? 'BRL',
                'installment_count' => (int) ($payment['installment_count'] ?? 1),
                'status' => $status,
                'paid_at' => isset($payment['paid_at'])
                    ? Carbon::parse($payment['paid_at'])
                    : ($this->isSettledPaymentStatus($status) ? $closedAt : null),
                'due_at' => isset($payment['due_at']) ? Carbon::parse($payment['due_at']) : null,
                'failure_reason' => $payment['failure_reason'] ?? null,
                'metadata_json' => $payment['metadata_json'] ?? null,
                'cash_register_session_id' => $payment['cash_register_session_id'] ?? null,
            ];
        }, $payments);
    }

    private function resolveCashRegisterSession(
        string $provider,
        ?string $cashRegisterSessionId,
    ): ?CashRegisterSession {
        if ($cashRegisterSessionId === null) {
            return null;
        }

        if ($provider !== 'cash') {
            throw ValidationException::withMessages([
                'payments' => 'Apenas pagamentos em dinheiro podem ser vinculados a uma sessão de caixa.',
            ]);
        }

        $cashRegisterSession = CashRegisterSession::query()->findOrFail($cashRegisterSessionId);

        if ($cashRegisterSession->status !== 'open') {
            throw ValidationException::withMessages([
                'payments' => 'A sessão de caixa vinculada ao pagamento em dinheiro precisa estar aberta.',
            ]);
        }

        return $cashRegisterSession;
    }

    /**
     * @param  array<string, mixed>  $itemPayload
     */
    private function resolveCommissionPercent(
        array $itemPayload,
        ?Service $service,
        ?Professional $professional,
    ): ?float {
        if (array_key_exists('commission_percent', $itemPayload) && $itemPayload['commission_percent'] !== null) {
            return (float) $itemPayload['commission_percent'];
        }

        if ($service === null) {
            return null;
        }

        if (! $service->commissionable) {
            return null;
        }

        if ($service->default_commission_percent !== null) {
            return (float) $service->default_commission_percent;
        }

        if (
            $professional !== null
            && $professional->commission_model === 'fixed_percent'
            && $professional->commission_percent !== null
        ) {
            return (float) $professional->commission_percent;
        }

        return null;
    }

    private function isSettledPaymentStatus(string $status): bool
    {
        return in_array($status, self::SETTLED_PAYMENT_STATUSES, true);
    }
}
