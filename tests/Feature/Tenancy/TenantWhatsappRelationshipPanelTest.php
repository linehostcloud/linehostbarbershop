<?php

namespace Tests\Feature\Tenancy;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Domain\Auth\Models\AuditLog;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\AutomationRunTarget;
use App\Domain\Communication\Models\Message;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenantWhatsappPanel;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappRelationshipPanelTest extends TestCase
{
    use RefreshTenantDatabases;
    use InteractsWithTenantWhatsappPanel;

    public function test_relationship_page_lists_tenant_scoped_appointment_statuses(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-agenda-a', 'barbearia-relacionamento-agenda-a.test');
            $otherTenant = $this->provisionTenant('barbearia-relacionamento-agenda-b', 'barbearia-relacionamento-agenda-b.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-agenda@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $this->activateAutomation($tenant, 'appointment_reminder', [
                'status' => 'active',
            ]);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Agenda', '+5511999998100');
            $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:05:00');

            $this->withHeaders($this->tenantAuthHeaders($otherTenant, role: 'manager'));
            [$otherClientId, $otherProfessionalId, $otherServiceId] = $this->createOperationalContext($otherTenant, 'Cliente Outro Tenant', '+5511999998101');
            $this->createAppointment($otherTenant, $otherClientId, $otherProfessionalId, $otherServiceId, '2026-03-20 10:05:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('WhatsApp na agenda e na carteira de clientes')
                ->assertSee('Lembretes elegíveis agora')
                ->assertSee('Agendamentos com WhatsApp')
                ->assertSee('Cliente Agenda')
                ->assertSee('Lembrete pendente')
                ->assertSee('Ainda não disparado')
                ->assertSee('Relacionamento')
                ->assertDontSee('Cliente Outro Tenant');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_appointment_reminder_uses_official_pipeline_and_audits(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-reminder', 'barbearia-relacionamento-reminder.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-reminder@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $automationId = $this->activateAutomation($tenant, 'appointment_reminder', [
                'status' => 'inactive',
            ]);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Lembrete', '+5511999998200');
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 16:00:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

            $this->from($this->panelRelationshipUrl($tenant))
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->withUnencryptedCookie((string) config('session.cookie'), $session)
                ->post($this->panelRelationshipAppointmentReminderUrl($tenant, $appointmentId), [
                    '_token' => $csrf,
                ])
                ->assertRedirect($this->panelRelationshipUrl($tenant));

            $this->withTenantConnection($tenant, function () use ($appointmentId, $automationId): void {
                $message = Message::query()->sole();
                $run = AutomationRun::query()->sole();
                $target = AutomationRunTarget::query()->sole();

                $this->assertSame($appointmentId, $message->appointment_id);
                $this->assertSame($automationId, $message->automation_id);
                $this->assertSame('appointment_reminder', data_get($message->payload_json, 'automation.type'));
                $this->assertSame('manager_relationship_panel', data_get($message->payload_json, 'product.surface'));
                $this->assertSame('queued', $target->status);
                $this->assertSame('manual_appointment_reminder', $target->trigger_reason);
                $this->assertSame('completed', $run->status);
                $this->assertSame(1, OutboxEvent::query()->where('message_id', $message->id)->count());
                $this->assertSame('product_panel', EventLog::query()->where('event_name', 'whatsapp.message.queued')->sole()->trigger_source);
            });

            $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_product.appointment_reminder.manual_queued')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_appointment_reminder_respects_permission(): void
    {
        $tenant = $this->provisionTenant('barbearia-relacionamento-reminder-perm', 'barbearia-relacionamento-reminder-perm.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'barber',
            email: 'barbeiro-relacionamento-reminder@test.local',
            password: 'password123',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));
        $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
        [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Sem Permissão', '+5511999998201');
        $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 16:00:00');
        $this->flushHeaders();

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
        ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

        $this->from($this->panelRelationshipUrl($tenant))
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->withUnencryptedCookie((string) config('session.cookie'), $session)
            ->post($this->panelRelationshipAppointmentReminderUrl($tenant, $appointmentId), [
                '_token' => $csrf,
            ])
            ->assertForbidden();
    }

    public function test_relationship_page_shows_clients_eligible_for_reactivation(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-reativacao', 'barbearia-relacionamento-reativacao.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-reativacao@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $this->activateAutomation($tenant, 'inactive_client_reactivation', [
                'status' => 'active',
                'conditions_json' => [
                    'inactivity_days' => 30,
                    'minimum_completed_visits' => 1,
                    'require_marketing_opt_in' => true,
                    'exclude_with_future_appointments' => true,
                ],
            ]);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Reativação', '+5511999998300', marketingOptIn: true);
            $this->seedCompletedVisit($tenant, $clientId, $professionalId, $serviceId, '2026-01-10 14:00:00', '2026-01-10 15:00:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Clientes para Reativação')
                ->assertSee('Cliente Reativação')
                ->assertSee('Elegível para reativação')
                ->assertSee('dias')
                ->assertSee('Reativação automática');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_client_reactivation_uses_official_pipeline_and_audits(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-reativacao-manual', 'barbearia-relacionamento-reativacao-manual.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-reativacao-manual@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $automationId = $this->activateAutomation($tenant, 'inactive_client_reactivation', [
                'status' => 'inactive',
                'conditions_json' => [
                    'inactivity_days' => 30,
                    'minimum_completed_visits' => 1,
                    'require_marketing_opt_in' => true,
                    'exclude_with_future_appointments' => true,
                ],
            ]);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Manual Reativação', '+5511999998301', marketingOptIn: true);
            $this->seedCompletedVisit($tenant, $clientId, $professionalId, $serviceId, '2026-01-10 14:00:00', '2026-01-10 15:00:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

            $this->from($this->panelRelationshipUrl($tenant))
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->withUnencryptedCookie((string) config('session.cookie'), $session)
                ->post($this->panelRelationshipClientReactivationUrl($tenant, $clientId), [
                    '_token' => $csrf,
                ])
                ->assertRedirect($this->panelRelationshipUrl($tenant));

            $this->withTenantConnection($tenant, function () use ($clientId, $automationId): void {
                $message = Message::query()->sole();
                $run = AutomationRun::query()->sole();
                $target = AutomationRunTarget::query()->sole();

                $this->assertSame($clientId, $message->client_id);
                $this->assertSame($automationId, $message->automation_id);
                $this->assertNull($message->appointment_id);
                $this->assertSame('inactive_client_reactivation', data_get($message->payload_json, 'automation.type'));
                $this->assertSame('manager_relationship_panel', data_get($message->payload_json, 'product.surface'));
                $this->assertSame('queued', $target->status);
                $this->assertSame('manual_client_reactivation', $target->trigger_reason);
                $this->assertSame('completed', $run->status);
                $this->assertSame(1, OutboxEvent::query()->where('message_id', $message->id)->count());
            });

            $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_product.client_reactivation.manual_queued')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_client_reactivation_respects_permission(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-reativacao-perm', 'barbearia-relacionamento-reativacao-perm.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'barber',
                email: 'barbeiro-relacionamento-reativacao@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));
            $this->activateAutomation($tenant, 'inactive_client_reactivation', [
                'status' => 'active',
                'conditions_json' => [
                    'inactivity_days' => 30,
                    'minimum_completed_visits' => 1,
                    'require_marketing_opt_in' => true,
                    'exclude_with_future_appointments' => true,
                ],
            ]);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Sem Permissão Reativação', '+5511999998302', marketingOptIn: true);
            $this->seedCompletedVisit($tenant, $clientId, $professionalId, $serviceId, '2026-01-10 14:00:00', '2026-01-10 15:00:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

            $this->from($this->panelRelationshipUrl($tenant))
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->withUnencryptedCookie((string) config('session.cookie'), $session)
                ->post($this->panelRelationshipClientReactivationUrl($tenant, $clientId), [
                    '_token' => $csrf,
                ])
                ->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function createOperationalContext(
        Tenant $tenant,
        string $clientName = 'Cliente Produto',
        string $clientPhone = '+5511999998000',
        bool $marketingOptIn = false,
    ): array {
        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => $clientName,
            'phone_e164' => $clientPhone,
            'whatsapp_opt_in' => true,
            'marketing_opt_in' => $marketingOptIn,
        ])->assertCreated()->json('data.id');

        $professionalId = $this->createProfessional($tenant, 'Profissional Produto');
        $serviceId = $this->createService($tenant, 'Serviço Produto', 45, 6500);

        return [$clientId, $professionalId, $serviceId];
    }

    private function createProfessional(Tenant $tenant, string $displayName): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/professionals'), [
            'display_name' => $displayName,
            'role' => 'barber',
            'active' => true,
        ])->assertCreated()->json('data.id');
    }

    private function createService(Tenant $tenant, string $name, int $durationMinutes, int $priceCents): string
    {
        return $this->postJson($this->tenantUrl($tenant, '/services'), [
            'name' => $name,
            'category' => 'servico',
            'duration_minutes' => $durationMinutes,
            'price_cents' => $priceCents,
            'active' => true,
        ])->assertCreated()->json('data.id');
    }

    private function createAppointment(
        Tenant $tenant,
        string $clientId,
        string $professionalId,
        string $serviceId,
        string $startsAt,
    ): string {
        return $this->postJson($this->tenantUrl($tenant, '/appointments'), [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'primary_service_id' => $serviceId,
            'starts_at' => $startsAt,
        ])->assertCreated()->json('data.id');
    }

    private function seedCompletedVisit(
        Tenant $tenant,
        string $clientId,
        string $professionalId,
        string $serviceId,
        string $appointmentStartsAt,
        string $orderClosedAt,
    ): void {
        $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, $appointmentStartsAt);
        $orderId = $this->postJson($this->tenantUrl($tenant, '/orders'), [
            'appointment_id' => $appointmentId,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl($tenant, sprintf('/orders/%s/close', $orderId)), [
            'closed_at' => $orderClosedAt,
            'items' => [
                [
                    'service_id' => $serviceId,
                    'professional_id' => $professionalId,
                    'type' => 'service',
                    'description' => 'Serviço Produto',
                    'quantity' => 1,
                    'unit_price_cents' => 6500,
                ],
            ],
            'payments' => [
                [
                    'provider' => 'pix',
                    'amount_cents' => 6500,
                    'status' => 'paid',
                ],
            ],
        ])->assertOk();
    }

    private function activateAutomation(Tenant $tenant, string $type, array $attributes = []): string
    {
        return $this->withTenantConnection($tenant, function () use ($type, $attributes): string {
            app(EnsureDefaultWhatsappAutomationsAction::class)->execute();

            $automation = Automation::query()
                ->where('channel', 'whatsapp')
                ->where('trigger_event', $type)
                ->firstOrFail();

            $automation->forceFill(array_merge([
                'status' => 'active',
            ], $attributes))->save();

            return $automation->id;
        });
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
    }
}
