<?php

namespace App\Application\Actions\Finance;

use App\Domain\Finance\Models\Transaction;
use App\Domain\Professional\Models\Professional;
use Illuminate\Support\Carbon;

class CalculateProfessionalCommissionBalanceAction
{
    /**
     * @return array<string, int>
     */
    public function execute(Professional $professional, ?Carbon $asOf = null): array
    {
        $commissionQuery = Transaction::query()
            ->where('professional_id', $professional->id)
            ->where('type', 'commission')
            ->where('category', 'professional_commission');

        $payoutQuery = Transaction::query()
            ->where('professional_id', $professional->id)
            ->where('category', 'commission_payout');

        if ($asOf !== null) {
            $date = $asOf->toDateString();

            $commissionQuery->whereDate('occurred_on', '<=', $date);
            $payoutQuery->whereDate('occurred_on', '<=', $date);
        }

        $provisionedCents = (int) $commissionQuery->sum('amount_cents');
        $paidCents = (int) $payoutQuery->sum('amount_cents');

        return [
            'provisioned_cents' => $provisionedCents,
            'paid_cents' => $paidCents,
            'outstanding_cents' => max(0, $provisionedCents - $paidCents),
        ];
    }
}
