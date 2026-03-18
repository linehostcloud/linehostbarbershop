<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Tenant\Models\Tenant;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantFinanceApiTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_records_manual_cash_movements_and_updates_the_open_cash_balance(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-movimentos',
            domain: 'barbearia-movimentos.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant));

        $cashRegisterSessionId = $this->postJson($this->tenantUrl($tenant, '/cash-register-sessions'), [
            'label' => 'Caixa principal',
            'opening_balance_cents' => 1000,
        ])
            ->assertStatus(201)
            ->json('data.id');

        $this->postJson($this->tenantUrl($tenant, "/cash-register-sessions/{$cashRegisterSessionId}/movements"), [
            'kind' => 'supply',
            'amount_cents' => 500,
            'description' => 'Reforco para troco',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.category', 'cash_supply')
            ->assertJsonPath('data.amount_cents', 500)
            ->assertJsonPath('data.balance_direction', 'credit');

        $this->postJson($this->tenantUrl($tenant, "/cash-register-sessions/{$cashRegisterSessionId}/movements"), [
            'kind' => 'withdrawal',
            'amount_cents' => 200,
            'description' => 'Sangria de seguranca',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.category', 'cash_withdrawal')
            ->assertJsonPath('data.balance_direction', 'debit');

        $this->getJson($this->tenantUrl($tenant, "/cash-register-sessions/{$cashRegisterSessionId}"))
            ->assertOk()
            ->assertJsonPath('data.expected_balance_cents', 1300)
            ->assertJsonPath('data.transactions_count', 2);
    }

    public function test_it_records_payments_revenue_transactions_and_professional_commission_when_closing_an_order(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-financeiro',
            domain: 'barbearia-financeiro.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant));

        $clientId = $this->createClient($tenant, 'Cliente Financeiro');
        $professionalId = $this->createProfessional($tenant, 'Profissional Comissao', 45);
        $serviceId = $this->createService($tenant, 'Corte executivo', 30, 4500, 40);

        $cashRegisterSessionId = $this->postJson($this->tenantUrl($tenant, '/cash-register-sessions'), [
            'label' => 'Caixa principal',
            'opening_balance_cents' => 1000,
        ])
            ->assertStatus(201)
            ->json('data.id');

        $appointmentId = $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => '2026-03-18 15:00:00',
        ])->assertCreated()->json('data.id');

        $orderId = $this->postJson($this->tenantUrl($tenant, '/orders'), [
            'appointment_id' => $appointmentId,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, "/orders/{$orderId}/close"), [
            'discount_cents' => 500,
            'fee_cents' => 200,
            'items' => [
                [
                    'service_id' => $serviceId,
                    'professional_id' => $professionalId,
                    'type' => 'service',
                    'description' => 'Corte executivo',
                    'quantity' => 1,
                    'unit_price_cents' => 4500,
                ],
                [
                    'professional_id' => $professionalId,
                    'type' => 'product',
                    'description' => 'Pomada modeladora',
                    'quantity' => 2,
                    'unit_price_cents' => 1000,
                ],
            ],
            'payments' => [
                [
                    'provider' => 'cash',
                    'amount_cents' => 3000,
                    'cash_register_session_id' => $cashRegisterSessionId,
                ],
                [
                    'provider' => 'pix',
                    'amount_cents' => 3200,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.amount_paid_cents', 6200)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonCount(3, 'data.transactions');

        $this->getJson($this->tenantUrl($tenant, '/payments'))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson($this->tenantUrl($tenant, '/transactions'))
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $cashPayment = Payment::query()
            ->where('provider', 'cash')
            ->firstOrFail();

        $cashRevenueTransaction = Transaction::query()
            ->where('payment_id', $cashPayment->id)
            ->firstOrFail();

        $this->assertSame('income', $cashRevenueTransaction->type);
        $this->assertSame('order_revenue', $cashRevenueTransaction->category);
        $this->assertSame($cashRegisterSessionId, $cashRevenueTransaction->cash_register_session_id);
        $this->assertSame(3000, $cashRevenueTransaction->amount_cents);

        $commissionTransaction = Transaction::query()
            ->where('type', 'commission')
            ->firstOrFail();

        $this->assertSame('professional_commission', $commissionTransaction->category);
        $this->assertSame('debit', $commissionTransaction->balance_direction);
        $this->assertSame($professionalId, $commissionTransaction->professional_id);
        $this->assertSame(1800, $commissionTransaction->amount_cents);

        $this->getJson($this->tenantUrl($tenant, "/cash-register-sessions/{$cashRegisterSessionId}"))
            ->assertOk()
            ->assertJsonPath('data.expected_balance_cents', 4000);
    }

    public function test_it_records_commission_payouts_and_returns_professional_outstanding_balance(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-comissao',
            domain: 'barbearia-comissao.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant));

        $clientId = $this->createClient($tenant, 'Cliente Comissao');
        $professionalId = $this->createProfessional($tenant, 'Profissional Repasse', 45);
        $serviceId = $this->createService($tenant, 'Corte assinatura', 30, 4500, 40);

        $cashRegisterSessionId = $this->postJson($this->tenantUrl($tenant, '/cash-register-sessions'), [
            'label' => 'Caixa principal',
            'opening_balance_cents' => 5000,
        ])
            ->assertStatus(201)
            ->json('data.id');

        $appointmentId = $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => '2026-03-18 17:00:00',
        ])->assertCreated()->json('data.id');

        $orderId = $this->postJson($this->tenantUrl($tenant, '/orders'), [
            'appointment_id' => $appointmentId,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, "/orders/{$orderId}/close"), [
            'items' => [
                [
                    'service_id' => $serviceId,
                    'professional_id' => $professionalId,
                    'type' => 'service',
                    'description' => 'Corte assinatura',
                    'quantity' => 1,
                    'unit_price_cents' => 4500,
                ],
            ],
            'payments' => [
                [
                    'provider' => 'cash',
                    'amount_cents' => 4500,
                    'cash_register_session_id' => $cashRegisterSessionId,
                ],
            ],
        ])->assertOk();

        $this->getJson($this->tenantUrl($tenant, "/professionals/{$professionalId}/commission-summary"))
            ->assertOk()
            ->assertJsonPath('data.provisioned_cents', 1800)
            ->assertJsonPath('data.paid_cents', 0)
            ->assertJsonPath('data.outstanding_cents', 1800);

        $this->postJson($this->tenantUrl($tenant, "/professionals/{$professionalId}/commission-payouts"), [
            'provider' => 'cash',
            'cash_register_session_id' => $cashRegisterSessionId,
            'amount_cents' => 1200,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.transaction.category', 'commission_payout')
            ->assertJsonPath('data.transaction.amount_cents', 1200)
            ->assertJsonPath('data.commission_balance.provisioned_cents', 1800)
            ->assertJsonPath('data.commission_balance.paid_cents', 1200)
            ->assertJsonPath('data.commission_balance.outstanding_cents', 600);

        $this->getJson($this->tenantUrl($tenant, "/cash-register-sessions/{$cashRegisterSessionId}"))
            ->assertOk()
            ->assertJsonPath('data.expected_balance_cents', 8300);
    }

    public function test_it_returns_financial_summary_for_the_requested_period(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-resumo',
            domain: 'barbearia-resumo.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant));

        $clientId = $this->createClient($tenant, 'Cliente Resumo');
        $professionalId = $this->createProfessional($tenant, 'Profissional Resumo', 45);
        $serviceId = $this->createService($tenant, 'Corte completo', 30, 4500, 40);

        $cashRegisterSessionId = $this->postJson($this->tenantUrl($tenant, '/cash-register-sessions'), [
            'label' => 'Caixa principal',
            'opening_balance_cents' => 2000,
            'opened_at' => '2026-03-18 08:00:00',
        ])
            ->assertStatus(201)
            ->json('data.id');

        $appointmentId = $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => '2026-03-18 10:00:00',
        ])->assertCreated()->json('data.id');

        $orderId = $this->postJson($this->tenantUrl($tenant, '/orders'), [
            'appointment_id' => $appointmentId,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, "/orders/{$orderId}/close"), [
            'closed_at' => '2026-03-18 10:45:00',
            'items' => [
                [
                    'service_id' => $serviceId,
                    'professional_id' => $professionalId,
                    'type' => 'service',
                    'description' => 'Corte completo',
                    'quantity' => 1,
                    'unit_price_cents' => 4500,
                ],
            ],
            'payments' => [
                [
                    'provider' => 'cash',
                    'amount_cents' => 4500,
                    'cash_register_session_id' => $cashRegisterSessionId,
                ],
            ],
        ])->assertOk();

        $this->postJson($this->tenantUrl($tenant, "/cash-register-sessions/{$cashRegisterSessionId}/movements"), [
            'kind' => 'withdrawal',
            'amount_cents' => 300,
            'description' => 'Sangria de meio turno',
            'occurred_on' => '2026-03-18',
        ])->assertStatus(201);

        $this->postJson($this->tenantUrl($tenant, "/professionals/{$professionalId}/commission-payouts"), [
            'provider' => 'cash',
            'cash_register_session_id' => $cashRegisterSessionId,
            'amount_cents' => 1000,
            'occurred_on' => '2026-03-18',
        ])->assertStatus(201);

        $this->getJson($this->tenantUrl($tenant, '/finance/summary?date_from=2026-03-18&date_to=2026-03-18'))
            ->assertOk()
            ->assertJsonPath('data.period.date_from', '2026-03-18')
            ->assertJsonPath('data.period.date_to', '2026-03-18')
            ->assertJsonPath('data.orders_closed_count', 1)
            ->assertJsonPath('data.gross_revenue_cents', 4500)
            ->assertJsonPath('data.payments_received_cents', 4500)
            ->assertJsonPath('data.manual_outflows_cents', 300)
            ->assertJsonPath('data.commission_provisioned_cents', 1800)
            ->assertJsonPath('data.commission_paid_cents', 1000)
            ->assertJsonPath('data.outstanding_commission_cents', 800)
            ->assertJsonPath('data.average_ticket_cents', 4500)
            ->assertJsonPath('data.open_cash_register_session.expected_balance_cents', 5200);
    }

    public function test_it_closes_cash_register_sessions_with_expected_balance_and_reconciles_transactions(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-caixa',
            domain: 'barbearia-caixa.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant));

        $clientId = $this->createClient($tenant, 'Cliente Caixa');
        $professionalId = $this->createProfessional($tenant, 'Profissional Caixa', 40);
        $serviceId = $this->createService($tenant, 'Barba premium', 30, 4500, 40);

        $cashRegisterSessionId = $this->postJson($this->tenantUrl($tenant, '/cash-register-sessions'), [
            'label' => 'Caixa principal',
            'opening_balance_cents' => 1000,
        ])
            ->assertStatus(201)
            ->json('data.id');

        $appointmentId = $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => '2026-03-18 16:00:00',
        ])->assertCreated()->json('data.id');

        $orderId = $this->postJson($this->tenantUrl($tenant, '/orders'), [
            'appointment_id' => $appointmentId,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, "/orders/{$orderId}/close"), [
            'items' => [
                [
                    'service_id' => $serviceId,
                    'professional_id' => $professionalId,
                    'type' => 'service',
                    'description' => 'Barba premium',
                    'quantity' => 1,
                    'unit_price_cents' => 4500,
                ],
            ],
            'payments' => [
                [
                    'provider' => 'cash',
                    'amount_cents' => 4500,
                    'cash_register_session_id' => $cashRegisterSessionId,
                ],
            ],
        ])->assertOk();

        $this->postJson($this->tenantUrl($tenant, "/cash-register-sessions/{$cashRegisterSessionId}/close"), [
            'counted_cash_cents' => 5600,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected_balance_cents', 5500)
            ->assertJsonPath('data.counted_cash_cents', 5600)
            ->assertJsonPath('data.difference_cents', 100);

        $this->getJson($this->tenantUrl($tenant, "/cash-register-sessions/{$cashRegisterSessionId}"))
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.transactions_count', 1);

        $cashRegisterSession = CashRegisterSession::query()
            ->with('transactions')
            ->findOrFail($cashRegisterSessionId);

        $this->assertNotNull($cashRegisterSession->closed_at);
        $this->assertTrue($cashRegisterSession->transactions->every(
            fn (Transaction $transaction): bool => $transaction->reconciled,
        ));
    }

    private function createClient(Tenant $tenant, string $name): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => $name,
            'phone_e164' => '+5511999991234',
        ])
            ->assertCreated()
            ->json('data.id');
    }

    private function createProfessional(Tenant $tenant, string $name, int $commissionPercent): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/professionals'), [
            'display_name' => $name,
            'role' => 'barber',
            'commission_model' => 'fixed_percent',
            'commission_percent' => $commissionPercent,
            'active' => true,
        ])
            ->assertCreated()
            ->json('data.id');
    }

    private function createService(
        Tenant $tenant,
        string $name,
        int $durationMinutes,
        int $priceCents,
        int $defaultCommissionPercent,
    ): string {
        return $this->postJson($this->tenantUrl($tenant, '/services'), [
            'category' => 'servico',
            'name' => $name,
            'duration_minutes' => $durationMinutes,
            'price_cents' => $priceCents,
            'default_commission_percent' => $defaultCommissionPercent,
            'active' => true,
        ])
            ->assertCreated()
            ->json('data.id');
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
    }
}
