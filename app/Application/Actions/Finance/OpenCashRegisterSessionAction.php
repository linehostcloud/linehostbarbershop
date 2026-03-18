<?php

namespace App\Application\Actions\Finance;

use App\Domain\Finance\Models\CashRegisterSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenCashRegisterSessionAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload): CashRegisterSession
    {
        if (CashRegisterSession::query()->where('status', 'open')->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Já existe uma sessão de caixa aberta para este tenant.',
            ]);
        }

        return DB::connection(config('tenancy.tenant_connection', 'tenant'))
            ->transaction(function () use ($payload): CashRegisterSession {
                $openedAt = isset($payload['opened_at']) ? Carbon::parse($payload['opened_at']) : now();
                $openingBalance = (int) ($payload['opening_balance_cents'] ?? 0);

                return CashRegisterSession::query()->create([
                    'label' => $payload['label'] ?? 'caixa-principal',
                    'opened_by_user_id' => $payload['opened_by_user_id'] ?? null,
                    'status' => 'open',
                    'opening_balance_cents' => $openingBalance,
                    'expected_balance_cents' => $openingBalance,
                    'opened_at' => $openedAt,
                    'notes' => $payload['notes'] ?? null,
                ])->fresh();
            });
    }
}
