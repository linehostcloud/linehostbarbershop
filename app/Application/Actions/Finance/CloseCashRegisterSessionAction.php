<?php

namespace App\Application\Actions\Finance;

use App\Domain\Finance\Models\CashRegisterSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseCashRegisterSessionAction
{
    public function __construct(
        private readonly SyncCashRegisterSessionBalanceAction $syncCashRegisterSessionBalance,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(CashRegisterSession $cashRegisterSession, array $payload): CashRegisterSession
    {
        if ($cashRegisterSession->status !== 'open') {
            throw ValidationException::withMessages([
                'status' => 'Apenas sessões de caixa abertas podem ser fechadas.',
            ]);
        }

        return DB::connection(config('tenancy.tenant_connection', 'tenant'))
            ->transaction(function () use ($cashRegisterSession, $payload): CashRegisterSession {
                $cashRegisterSession = $this->syncCashRegisterSessionBalance->execute($cashRegisterSession);
                $expectedBalance = $cashRegisterSession->expected_balance_cents;
                $countedCash = (int) $payload['counted_cash_cents'];
                $closedAt = isset($payload['closed_at']) ? Carbon::parse($payload['closed_at']) : now();

                $cashRegisterSession->fill([
                    'closed_by_user_id' => $payload['closed_by_user_id'] ?? null,
                    'status' => 'closed',
                    'expected_balance_cents' => $expectedBalance,
                    'counted_cash_cents' => $countedCash,
                    'difference_cents' => $countedCash - $expectedBalance,
                    'closed_at' => $closedAt,
                    'notes' => $payload['notes'] ?? $cashRegisterSession->notes,
                ])->save();

                $cashRegisterSession->transactions()->update([
                    'reconciled' => true,
                ]);

                return $cashRegisterSession->fresh([
                    'transactions.payment',
                    'transactions.professional',
                ])->loadCount('transactions');
            });
    }
}
