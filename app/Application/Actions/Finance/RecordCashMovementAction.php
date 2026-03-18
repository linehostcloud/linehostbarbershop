<?php

namespace App\Application\Actions\Finance;

use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Finance\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordCashMovementAction
{
    /**
     * @var array<string, array{type: string, category: string, balance_direction: string}>
     */
    private const MOVEMENT_MAP = [
        'supply' => [
            'type' => 'adjustment',
            'category' => 'cash_supply',
            'balance_direction' => 'credit',
        ],
        'withdrawal' => [
            'type' => 'adjustment',
            'category' => 'cash_withdrawal',
            'balance_direction' => 'debit',
        ],
        'expense' => [
            'type' => 'expense',
            'category' => 'cash_expense',
            'balance_direction' => 'debit',
        ],
        'income' => [
            'type' => 'income',
            'category' => 'cash_income',
            'balance_direction' => 'credit',
        ],
    ];

    public function __construct(
        private readonly SyncCashRegisterSessionBalanceAction $syncCashRegisterSessionBalance,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(CashRegisterSession $cashRegisterSession, array $payload): Transaction
    {
        if ($cashRegisterSession->status !== 'open') {
            throw ValidationException::withMessages([
                'status' => 'A sessão de caixa precisa estar aberta para registrar movimentações.',
            ]);
        }

        $movement = self::MOVEMENT_MAP[$payload['kind']];

        return DB::connection(config('tenancy.tenant_connection', 'tenant'))
            ->transaction(function () use ($cashRegisterSession, $payload, $movement): Transaction {
                $transaction = $cashRegisterSession->transactions()->create([
                    'occurred_on' => isset($payload['occurred_on'])
                        ? Carbon::parse($payload['occurred_on'])->toDateString()
                        : now()->toDateString(),
                    'type' => $movement['type'],
                    'category' => $movement['category'],
                    'description' => $payload['description'],
                    'amount_cents' => (int) $payload['amount_cents'],
                    'balance_direction' => $movement['balance_direction'],
                    'reconciled' => false,
                    'metadata_json' => [
                        'kind' => $payload['kind'],
                        'notes' => $payload['notes'] ?? null,
                        ...($payload['metadata_json'] ?? []),
                    ],
                ]);

                $this->syncCashRegisterSessionBalance->execute($cashRegisterSession);

                return $transaction->fresh([
                    'cashRegisterSession',
                ]);
            });
    }
}
