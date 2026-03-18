<?php

namespace App\Application\Actions\Finance;

use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Finance\Models\Transaction;

class SyncCashRegisterSessionBalanceAction
{
    public function execute(CashRegisterSession $cashRegisterSession): CashRegisterSession
    {
        $expectedBalance = $cashRegisterSession->opening_balance_cents + $cashRegisterSession->transactions()
            ->get(['amount_cents', 'balance_direction'])
            ->sum(function (Transaction $transaction): int {
                return $transaction->balance_direction === 'debit'
                    ? (-1 * $transaction->amount_cents)
                    : $transaction->amount_cents;
            });

        $cashRegisterSession->forceFill([
            'expected_balance_cents' => $expectedBalance,
        ])->save();

        return $cashRegisterSession->fresh();
    }
}
