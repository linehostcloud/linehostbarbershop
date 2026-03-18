<?php

namespace App\Application\Actions\Finance;

use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class BuildFinanceSummaryAction
{
    /**
     * @var list<string>
     */
    private const SETTLED_PAYMENT_STATUSES = ['paid', 'captured', 'settled'];

    public function __construct(
        private readonly SyncCashRegisterSessionBalanceAction $syncCashRegisterSessionBalance,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters = []): array
    {
        $dateFrom = isset($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : null;
        $dateTo = isset($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : null;

        $transactions = Transaction::query()
            ->when($dateFrom, fn (Builder $query) => $query->whereDate('occurred_on', '>=', $dateFrom->toDateString()))
            ->when($dateTo, fn (Builder $query) => $query->whereDate('occurred_on', '<=', $dateTo->toDateString()));

        $orders = Order::query()
            ->where('status', 'closed')
            ->when($dateFrom, fn (Builder $query) => $query->where('closed_at', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query) => $query->where('closed_at', '<=', $dateTo));

        $payments = Payment::query()
            ->whereIn('status', self::SETTLED_PAYMENT_STATUSES)
            ->when($dateFrom, fn (Builder $query) => $query->where(function (Builder $subQuery) use ($dateFrom): void {
                $subQuery->where('paid_at', '>=', $dateFrom)
                    ->orWhere(function (Builder $nullPaidAtQuery) use ($dateFrom): void {
                        $nullPaidAtQuery->whereNull('paid_at')->where('created_at', '>=', $dateFrom);
                    });
            }))
            ->when($dateTo, fn (Builder $query) => $query->where(function (Builder $subQuery) use ($dateTo): void {
                $subQuery->where('paid_at', '<=', $dateTo)
                    ->orWhere(function (Builder $nullPaidAtQuery) use ($dateTo): void {
                        $nullPaidAtQuery->whereNull('paid_at')->where('created_at', '<=', $dateTo);
                    });
            }));

        $openCashRegisterSession = CashRegisterSession::query()
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        if ($openCashRegisterSession !== null) {
            $openCashRegisterSession = $this->syncCashRegisterSessionBalance->execute($openCashRegisterSession);
        }

        $ordersClosedCount = (int) $orders->count();
        $ordersTotalCents = (int) $orders->sum('total_cents');
        $commissionProvisionedCents = (int) (clone $transactions)
            ->where('type', 'commission')
            ->where('category', 'professional_commission')
            ->sum('amount_cents');
        $commissionPaidCents = (int) (clone $transactions)
            ->where('category', 'commission_payout')
            ->sum('amount_cents');

        return [
            'period' => [
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
            ],
            'orders_closed_count' => $ordersClosedCount,
            'gross_revenue_cents' => (int) (clone $transactions)
                ->where('type', 'income')
                ->where('category', 'order_revenue')
                ->sum('amount_cents'),
            'payments_received_cents' => (int) $payments->sum('amount_cents'),
            'manual_inflows_cents' => (int) (clone $transactions)
                ->whereIn('category', ['cash_supply', 'cash_income'])
                ->sum('amount_cents'),
            'manual_outflows_cents' => (int) (clone $transactions)
                ->whereIn('category', ['cash_withdrawal', 'cash_expense'])
                ->sum('amount_cents'),
            'commission_provisioned_cents' => $commissionProvisionedCents,
            'commission_paid_cents' => $commissionPaidCents,
            'outstanding_commission_cents' => max(0, $commissionProvisionedCents - $commissionPaidCents),
            'average_ticket_cents' => $ordersClosedCount > 0
                ? (int) round($ordersTotalCents / $ordersClosedCount)
                : 0,
            'open_cash_register_session' => $openCashRegisterSession === null
                ? null
                : [
                    'id' => $openCashRegisterSession->id,
                    'label' => $openCashRegisterSession->label,
                    'opened_at' => $openCashRegisterSession->opened_at?->toIso8601String(),
                    'expected_balance_cents' => $openCashRegisterSession->expected_balance_cents,
                ],
        ];
    }
}
