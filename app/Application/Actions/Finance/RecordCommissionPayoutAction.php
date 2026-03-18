<?php

namespace App\Application\Actions\Finance;

use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Professional\Models\Professional;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordCommissionPayoutAction
{
    public function __construct(
        private readonly CalculateProfessionalCommissionBalanceAction $calculateProfessionalCommissionBalance,
        private readonly SyncCashRegisterSessionBalanceAction $syncCashRegisterSessionBalance,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(Professional $professional, array $payload): Transaction
    {
        $provider = $payload['provider'] ?? 'manual';
        $cashRegisterSession = $this->resolveCashRegisterSession(
            $provider,
            $payload['cash_register_session_id'] ?? null,
        );
        $balance = $this->calculateProfessionalCommissionBalance->execute($professional);

        if ($balance['outstanding_cents'] <= 0) {
            throw ValidationException::withMessages([
                'amount_cents' => 'Este profissional nao possui comissoes pendentes para repasse.',
            ]);
        }

        $amountCents = (int) ($payload['amount_cents'] ?? $balance['outstanding_cents']);

        if ($amountCents <= 0 || $amountCents > $balance['outstanding_cents']) {
            throw ValidationException::withMessages([
                'amount_cents' => 'O valor do repasse deve ser maior que zero e menor ou igual ao saldo pendente.',
            ]);
        }

        return DB::connection(config('tenancy.tenant_connection', 'tenant'))
            ->transaction(function () use ($professional, $payload, $provider, $cashRegisterSession, $balance, $amountCents): Transaction {
                $transaction = Transaction::query()->create([
                    'professional_id' => $professional->id,
                    'cash_register_session_id' => $cashRegisterSession?->id,
                    'occurred_on' => isset($payload['occurred_on'])
                        ? Carbon::parse($payload['occurred_on'])->toDateString()
                        : now()->toDateString(),
                    'type' => 'expense',
                    'category' => 'commission_payout',
                    'description' => $payload['description']
                        ?? sprintf('Repasse de comissao para %s', $professional->display_name),
                    'amount_cents' => $amountCents,
                    'balance_direction' => 'debit',
                    'reconciled' => false,
                    'metadata_json' => [
                        'provider' => $provider,
                        'notes' => $payload['notes'] ?? null,
                        'outstanding_before_cents' => $balance['outstanding_cents'],
                        'outstanding_after_cents' => $balance['outstanding_cents'] - $amountCents,
                    ],
                ]);

                if ($cashRegisterSession !== null) {
                    $this->syncCashRegisterSessionBalance->execute($cashRegisterSession);
                }

                return $transaction->fresh([
                    'professional',
                    'cashRegisterSession',
                ]);
            });
    }

    private function resolveCashRegisterSession(
        string $provider,
        ?string $cashRegisterSessionId,
    ): ?CashRegisterSession {
        if ($provider === 'cash' && $cashRegisterSessionId === null) {
            throw ValidationException::withMessages([
                'cash_register_session_id' => 'Repasse em dinheiro exige uma sessao de caixa aberta.',
            ]);
        }

        if ($cashRegisterSessionId === null) {
            return null;
        }

        $cashRegisterSession = CashRegisterSession::query()->findOrFail($cashRegisterSessionId);

        if ($cashRegisterSession->status !== 'open') {
            throw ValidationException::withMessages([
                'cash_register_session_id' => 'A sessao de caixa vinculada ao repasse precisa estar aberta.',
            ]);
        }

        return $cashRegisterSession;
    }
}
