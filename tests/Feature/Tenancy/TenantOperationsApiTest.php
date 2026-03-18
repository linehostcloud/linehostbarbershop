<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantOperationsApiTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_creates_and_lists_clients_professionals_and_services_in_the_tenant_api(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-operacao',
            domain: 'barbearia-operacao.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant));

        $clientResponse = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => 'Joao da Silva',
            'phone_e164' => '+5511999990001',
            'email' => 'joao@cliente.test',
            'marketing_opt_in' => true,
            'whatsapp_opt_in' => true,
        ]);

        $clientResponse
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Joao da Silva');

        $professionalResponse = $this->postJson($this->tenantUrl($tenant, '/professionals'), [
            'display_name' => 'Carlos Barber',
            'role' => 'barber',
            'commission_model' => 'fixed_percent',
            'commission_percent' => 45,
            'active' => true,
        ]);

        $professionalResponse
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Carlos Barber');

        $serviceResponse = $this->postJson($this->tenantUrl($tenant, '/services'), [
            'category' => 'corte',
            'name' => 'Corte premium',
            'duration_minutes' => 45,
            'price_cents' => 4500,
            'commissionable' => true,
            'active' => true,
        ]);

        $serviceResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Corte premium');

        $this->getJson($this->tenantUrl($tenant, '/clients'))
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Joao da Silva');

        $this->getJson($this->tenantUrl($tenant, '/professionals'))
            ->assertOk()
            ->assertJsonPath('data.0.display_name', 'Carlos Barber');

        $this->getJson($this->tenantUrl($tenant, '/services'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Corte premium');
    }

    public function test_it_creates_an_appointment_and_blocks_overlapping_slots_for_the_same_professional(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-agenda',
            domain: 'barbearia-agenda.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant));

        $clientId = $this->createClient($tenant, 'Maria Cliente');
        $professionalId = $this->createProfessional($tenant, 'Roberto Agenda');
        $serviceId = $this->createService($tenant, 'Barba completa', 45, 3800);

        $appointmentResponse = $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => '2026-03-18 09:00:00',
            'source' => 'dashboard',
        ]);

        $appointmentResponse
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.duration_minutes', 45);

        $this->assertSame(
            '2026-03-18 09:45:00',
            Carbon::parse($appointmentResponse->json('data.ends_at'))->format('Y-m-d H:i:s'),
        );

        $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => '2026-03-18 09:15:00',
            'source' => 'dashboard',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['professional_id']);
    }

    public function test_it_opens_and_closes_an_order_with_totals_and_updates_the_appointment(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-comanda',
            domain: 'barbearia-comanda.test',
        );
        $this->withHeaders($this->tenantAuthHeaders($tenant));

        $clientId = $this->createClient($tenant, 'Pedro Caixa');
        $professionalId = $this->createProfessional($tenant, 'Anderson Caixa');
        $serviceId = $this->createService($tenant, 'Corte social', 30, 4500);

        $appointmentId = $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => '2026-03-18 11:00:00',
        ])->assertCreated()->json('data.id');

        $orderId = $this->postJson($this->tenantUrl($tenant, '/orders'), [
            'appointment_id' => $appointmentId,
            'notes' => 'Cliente aguardando finalizacao',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->json('data.id');

        $this->postJson($this->tenantUrl($tenant, "/orders/{$orderId}/close"), [
            'discount_cents' => 500,
            'fee_cents' => 200,
            'amount_paid_cents' => 6200,
            'items' => [
                [
                    'service_id' => $serviceId,
                    'professional_id' => $professionalId,
                    'type' => 'service',
                    'description' => 'Corte social',
                    'quantity' => 1,
                    'unit_price_cents' => 4500,
                    'commission_percent' => 45,
                ],
                [
                    'professional_id' => $professionalId,
                    'type' => 'product',
                    'description' => 'Pomada modeladora',
                    'quantity' => 2,
                    'unit_price_cents' => 1000,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.subtotal_cents', 6500)
            ->assertJsonPath('data.discount_cents', 500)
            ->assertJsonPath('data.fee_cents', 200)
            ->assertJsonPath('data.total_cents', 6200)
            ->assertJsonPath('data.amount_paid_cents', 6200)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.total_price_cents', 2000);

        $this->getJson($this->tenantUrl($tenant, "/appointments/{$appointmentId}"))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
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

    private function createProfessional(Tenant $tenant, string $name): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/professionals'), [
            'display_name' => $name,
            'role' => 'barber',
            'active' => true,
        ])
            ->assertCreated()
            ->json('data.id');
    }

    private function createService(Tenant $tenant, string $name, int $durationMinutes, int $priceCents): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/services'), [
            'category' => 'servico',
            'name' => $name,
            'duration_minutes' => $durationMinutes,
            'price_cents' => $priceCents,
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
