<?php

namespace Tests\Feature\Tenancy;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Application\Actions\Communication\BuildWhatsappRelationshipPanelDataAction;
use App\Domain\Auth\Models\AuditLog;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\AutomationRunTarget;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
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
                ->assertSee('Indicadores do WhatsApp')
                ->assertSee('Cliente Agenda')
                ->assertSee('Lembrete pendente')
                ->assertSee('Nenhuma solicitação enviada')
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
                $appointment = \App\Domain\Appointment\Models\Appointment::query()->findOrFail($appointmentId);
                $message = Message::query()->sole();
                $run = AutomationRun::query()->sole();
                $target = AutomationRunTarget::query()->sole();

                $this->assertNull($appointment->reminder_sent_at);
                $this->assertSame('not_sent', $appointment->confirmation_status);
                $this->assertSame($appointmentId, $message->appointment_id);
                $this->assertSame($automationId, $message->automation_id);
                $this->assertSame('queued', $message->status);
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

    public function test_manual_appointment_confirmation_uses_official_pipeline_and_audits(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-confirmacao', 'barbearia-relacionamento-confirmacao.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-confirmacao@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $automationId = $this->activateAutomation($tenant, 'appointment_reminder', [
                'status' => 'inactive',
            ]);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Confirmação', '+5511999998210');
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 16:00:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

            $this->from($this->panelRelationshipUrl($tenant))
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->withUnencryptedCookie((string) config('session.cookie'), $session)
                ->post($this->panelRelationshipAppointmentConfirmationUrl($tenant, $appointmentId), [
                    '_token' => $csrf,
                ])
                ->assertRedirect($this->panelRelationshipUrl($tenant));

            $this->withTenantConnection($tenant, function () use ($appointmentId, $automationId): void {
                $appointment = \App\Domain\Appointment\Models\Appointment::query()->findOrFail($appointmentId);
                $message = Message::query()->sole();
                $run = AutomationRun::query()->sole();
                $target = AutomationRunTarget::query()->sole();

                $this->assertNull($appointment->reminder_sent_at);
                $this->assertSame('confirm_queued', $appointment->confirmation_status);
                $this->assertSame($appointmentId, $message->appointment_id);
                $this->assertSame($automationId, $message->automation_id);
                $this->assertSame('queued', $message->status);
                $this->assertSame('appointment_reminder', data_get($message->payload_json, 'automation.type'));
                $this->assertSame('appointment_confirmation', data_get($message->payload_json, 'product.manual_action'));
                $this->assertSame('queued', $target->status);
                $this->assertSame('appointment_confirmation', $target->target_type);
                $this->assertSame('manual_appointment_confirmation', $target->trigger_reason);
                $this->assertSame('completed', $run->status);
                $this->assertSame(1, OutboxEvent::query()->where('message_id', $message->id)->count());
                $this->assertSame('product_panel', EventLog::query()->where('event_name', 'whatsapp.message.queued')->sole()->trigger_source);
            });

            $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_product.appointment_confirmation.manual_queued')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_relationship_page_only_shows_appointment_section_when_user_only_has_appointments_access(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-agenda-only', 'barbearia-relacionamento-agenda-only.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'finance',
                permissions: ['appointments.read'],
                email: 'agenda-only@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));
            $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            $this->activateAutomation($tenant, 'inactive_client_reactivation', [
                'status' => 'active',
                'conditions_json' => [
                    'inactivity_days' => 30,
                    'minimum_completed_visits' => 1,
                    'require_marketing_opt_in' => true,
                    'exclude_with_future_appointments' => true,
                ],
            ]);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Agenda Only', '+5511999998400', marketingOptIn: true);
            $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:05:00');
            $this->seedCompletedVisit($tenant, $clientId, $professionalId, $serviceId, '2026-01-10 14:00:00', '2026-01-10 15:00:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Agendamentos do Período')
                ->assertSee('Cliente Agenda Only')
                ->assertSee('data-metric-key="reminders_queued"', false)
                ->assertDontSee('data-metric-key="reactivations_triggered"', false)
                ->assertDontSee('Clientes para Reativação')
                ->assertDontSee('Reativação automática');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_relationship_page_only_shows_reactivation_section_when_user_only_has_clients_access(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-client-only', 'barbearia-relacionamento-client-only.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'finance',
                permissions: ['clients.read'],
                email: 'client-only@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));
            $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            $this->activateAutomation($tenant, 'inactive_client_reactivation', [
                'status' => 'active',
                'conditions_json' => [
                    'inactivity_days' => 30,
                    'minimum_completed_visits' => 1,
                    'require_marketing_opt_in' => true,
                    'exclude_with_future_appointments' => true,
                ],
            ]);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Reativação Only', '+5511999998401', marketingOptIn: true);
            $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:05:00');
            $this->seedCompletedVisit($tenant, $clientId, $professionalId, $serviceId, '2026-01-10 14:00:00', '2026-01-10 15:00:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Clientes para Reativação')
                ->assertSee('Cliente Reativação Only')
                ->assertSee('data-metric-key="reactivations_triggered"', false)
                ->assertDontSee('data-metric-key="reminders_queued"', false)
                ->assertDontSee('Agendamentos do Período')
                ->assertDontSee('Lembrete automático');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_relationship_metrics_cards_respect_selected_period_and_show_inferred_conversions_only_with_real_base(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-metricas-periodo', 'barbearia-relacionamento-metricas-periodo.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-metricas@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $appointmentAutomationId = $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            $reactivationAutomationId = $this->activateAutomation($tenant, 'inactive_client_reactivation', ['status' => 'active']);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Métricas', '+5511999998450', marketingOptIn: true);
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:00:00');

            [$oldClientId, $oldProfessionalId, $oldServiceId] = $this->createOperationalContext($tenant, 'Cliente Métricas Antigo', '+5511999998451', marketingOptIn: true);
            $oldAppointmentId = $this->createAppointment($tenant, $oldClientId, $oldProfessionalId, $oldServiceId, '2026-03-21 10:00:00');
            $convertedClientAppointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-25 11:00:00');

            $this->withTenantConnection($tenant, function () use (
                $appointmentAutomationId,
                $reactivationAutomationId,
                $appointmentId,
                $oldAppointmentId,
                $convertedClientAppointmentId,
                $clientId,
                $oldClientId,
            ): void {
                $this->seedRelationshipMessageMetric([
                    'automation_id' => $appointmentAutomationId,
                    'appointment_id' => $appointmentId,
                    'client_id' => $clientId,
                    'status' => 'queued',
                    'payload_json' => [
                        'automation' => [
                            'type' => 'appointment_reminder',
                            'trigger_reason' => 'manual_appointment_reminder',
                        ],
                    ],
                    'created_at' => '2026-03-19 09:10:00',
                ]);

                $this->seedRelationshipMessageMetric([
                    'automation_id' => $appointmentAutomationId,
                    'appointment_id' => $appointmentId,
                    'client_id' => $clientId,
                    'status' => 'dispatched',
                    'sent_at' => '2026-03-18 14:00:00',
                    'payload_json' => [
                        'automation' => [
                            'type' => 'appointment_reminder',
                            'trigger_reason' => 'appointment_due_soon',
                        ],
                    ],
                    'created_at' => '2026-03-18 13:55:00',
                ]);

                $this->seedRelationshipMessageMetric([
                    'automation_id' => $appointmentAutomationId,
                    'appointment_id' => $appointmentId,
                    'client_id' => $clientId,
                    'status' => 'dispatched',
                    'sent_at' => '2026-03-17 15:10:00',
                    'payload_json' => [
                        'automation' => [
                            'type' => 'appointment_reminder',
                            'trigger_reason' => 'manual_appointment_confirmation',
                        ],
                        'product' => [
                            'manual_action' => 'appointment_confirmation',
                        ],
                    ],
                    'created_at' => '2026-03-17 15:05:00',
                ]);

                $this->seedRelationshipMessageMetric([
                    'automation_id' => $appointmentAutomationId,
                    'appointment_id' => $appointmentId,
                    'client_id' => $clientId,
                    'status' => 'failed',
                    'failed_at' => '2026-03-19 08:00:00',
                    'failure_reason' => 'Falha para métrica.',
                    'payload_json' => [
                        'automation' => [
                            'type' => 'appointment_reminder',
                            'trigger_reason' => 'appointment_due_soon',
                        ],
                    ],
                    'created_at' => '2026-03-19 07:58:00',
                ]);

                $this->seedRelationshipMessageMetric([
                    'automation_id' => $appointmentAutomationId,
                    'appointment_id' => $oldAppointmentId,
                    'client_id' => $oldClientId,
                    'status' => 'dispatched',
                    'sent_at' => '2026-03-01 10:00:00',
                    'payload_json' => [
                        'automation' => [
                            'type' => 'appointment_reminder',
                            'trigger_reason' => 'appointment_due_soon',
                        ],
                    ],
                    'created_at' => '2026-03-01 09:58:00',
                ]);

                \App\Domain\Appointment\Models\Appointment::query()->findOrFail($appointmentId)->forceFill([
                    'confirmation_status' => 'confirmed',
                ])->save();

                $this->seedRelationshipMessageMetric([
                    'automation_id' => $reactivationAutomationId,
                    'client_id' => $clientId,
                    'status' => 'queued',
                    'payload_json' => [
                        'automation' => [
                            'type' => 'inactive_client_reactivation',
                            'trigger_reason' => 'manual_client_reactivation',
                        ],
                        'product' => [
                            'manual_action' => 'client_reactivation',
                        ],
                    ],
                    'created_at' => '2026-03-16 10:00:00',
                ]);

                $this->seedRelationshipMessageMetric([
                    'automation_id' => $reactivationAutomationId,
                    'client_id' => $oldClientId,
                    'status' => 'queued',
                    'payload_json' => [
                        'automation' => [
                            'type' => 'inactive_client_reactivation',
                            'trigger_reason' => 'inactive_for_reactivation',
                        ],
                    ],
                    'created_at' => '2026-03-02 10:00:00',
                ]);

                \App\Domain\Appointment\Models\Appointment::query()->findOrFail($convertedClientAppointmentId)->forceFill([
                    'created_at' => Carbon::parse('2026-03-18 12:00:00'),
                    'updated_at' => Carbon::parse('2026-03-18 12:00:00'),
                ])->save();
            });

            AuditLog::query()->create([
                'tenant_id' => $tenant->id,
                'actor_user_id' => $user->id,
                'auditable_type' => 'client',
                'auditable_id' => $clientId,
                'action' => 'whatsapp_product.client_reactivation.snoozed',
                'before_json' => [],
                'after_json' => [],
                'metadata_json' => [],
            ])->forceFill([
                'created_at' => Carbon::parse('2026-03-18 16:00:00'),
                'updated_at' => Carbon::parse('2026-03-18 16:00:00'),
            ])->save();

            AuditLog::query()->create([
                'tenant_id' => $tenant->id,
                'actor_user_id' => $user->id,
                'auditable_type' => 'client',
                'auditable_id' => $oldClientId,
                'action' => 'whatsapp_product.client_reactivation.snoozed',
                'before_json' => [],
                'after_json' => [],
                'metadata_json' => [],
            ])->forceFill([
                'created_at' => Carbon::parse('2026-03-01 16:00:00'),
                'updated_at' => Carbon::parse('2026-03-01 16:00:00'),
            ])->save();

            $this->flushHeaders();
            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

            $todayResponse = $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant, ['period' => 'today']));

            $todayResponse->assertOk()
                ->assertSee('Período dos indicadores')
                ->assertSee('Indicadores do WhatsApp');

            $todayPanel = $this->buildRelationshipPanelData($tenant, 'today');
            $this->assertSame(2, $this->metricValue($todayPanel, 'reminders_queued'));
            $this->assertSame(0, $this->metricValue($todayPanel, 'reminders_sent'));
            $this->assertSame(0, $this->metricValue($todayPanel, 'manual_confirmations_sent'));
            $this->assertSame(1, $this->metricValue($todayPanel, 'delivery_failures'));
            $this->assertSame(0, $this->metricValue($todayPanel, 'reactivations_triggered'));
            $this->assertSame(0, $this->metricValue($todayPanel, 'reactivation_snoozes'));
            $this->assertNull($this->metricValue($todayPanel, 'reminder_confirmation_conversion'));
            $this->assertNull($this->metricValue($todayPanel, 'reactivation_appointment_conversion'));

            $weeklyResponse = $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant, ['period' => '7d']));

            $weeklyResponse->assertOk()
                ->assertSee('Leitura resumida de últimos 7 dias')
                ->assertSee('Leitura inferida');

            $weeklyPanel = $this->buildRelationshipPanelData($tenant, '7d');
            $this->assertSame(3, $this->metricValue($weeklyPanel, 'reminders_queued'));
            $this->assertSame(1, $this->metricValue($weeklyPanel, 'reminders_sent'));
            $this->assertSame(1, $this->metricValue($weeklyPanel, 'manual_confirmations_sent'));
            $this->assertSame(1, $this->metricValue($weeklyPanel, 'delivery_failures'));
            $this->assertSame(1, $this->metricValue($weeklyPanel, 'reactivations_triggered'));
            $this->assertSame(1, $this->metricValue($weeklyPanel, 'reactivation_snoozes'));
            $this->assertSame(1, $this->metricValue($weeklyPanel, 'reminder_confirmation_conversion'));
            $this->assertSame(2, $this->metricValue($weeklyPanel, 'reactivation_appointment_conversion'));

            $monthlyResponse = $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant, ['period' => '30d']));

            $monthlyResponse->assertOk()
                ->assertSee('Leitura resumida de últimos 30 dias');

            $monthlyPanel = $this->buildRelationshipPanelData($tenant, '30d');
            $this->assertSame(2, $this->metricValue($monthlyPanel, 'reminders_sent'));
            $this->assertSame(2, $this->metricValue($monthlyPanel, 'reactivations_triggered'));
            $this->assertSame(2, $this->metricValue($monthlyPanel, 'reactivation_snoozes'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_relationship_metrics_do_not_leak_between_tenants(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-metricas-scope-a', 'barbearia-relacionamento-metricas-scope-a.test');
            $otherTenant = $this->provisionTenant('barbearia-relacionamento-metricas-scope-b', 'barbearia-relacionamento-metricas-scope-b.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-metricas-scope@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));
            $appointmentAutomationId = $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Scope A', '+5511999998452');
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:00:00');
            $this->withTenantConnection($tenant, function () use ($appointmentAutomationId, $appointmentId, $clientId): void {
                $this->seedRelationshipMessageMetric([
                    'automation_id' => $appointmentAutomationId,
                    'appointment_id' => $appointmentId,
                    'client_id' => $clientId,
                    'status' => 'queued',
                    'payload_json' => [
                        'automation' => [
                            'type' => 'appointment_reminder',
                            'trigger_reason' => 'appointment_due_soon',
                        ],
                    ],
                    'created_at' => '2026-03-19 09:00:00',
                ]);
            });

            $this->withHeaders($this->tenantAuthHeaders($otherTenant, role: 'manager'));
            $otherAutomationId = $this->activateAutomation($otherTenant, 'appointment_reminder', ['status' => 'active']);
            [$otherClientId, $otherProfessionalId, $otherServiceId] = $this->createOperationalContext($otherTenant, 'Cliente Scope B', '+5511999998453');
            $otherAppointmentId = $this->createAppointment($otherTenant, $otherClientId, $otherProfessionalId, $otherServiceId, '2026-03-20 10:00:00');
            $this->withTenantConnection($otherTenant, function () use ($otherAutomationId, $otherAppointmentId, $otherClientId): void {
                $this->seedRelationshipMessageMetric([
                    'automation_id' => $otherAutomationId,
                    'appointment_id' => $otherAppointmentId,
                    'client_id' => $otherClientId,
                    'status' => 'queued',
                    'payload_json' => [
                        'automation' => [
                            'type' => 'appointment_reminder',
                            'trigger_reason' => 'appointment_due_soon',
                        ],
                    ],
                    'created_at' => '2026-03-19 09:05:00',
                ]);
            });
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant, ['period' => 'today']))
                ->assertOk()
                ->assertDontSee('Cliente Scope B');

            $panel = $this->buildRelationshipPanelData($tenant, 'today');
            $this->assertSame(1, $this->metricValue($panel, 'reminders_queued'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_reminder_button_only_appears_for_manually_eligible_rows(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-reminder-ux', 'barbearia-relacionamento-reminder-ux.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-reminder-ux@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            [$eligibleClientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Elegível', '+5511999998402');
            $eligibleAppointmentId = $this->createAppointment($tenant, $eligibleClientId, $professionalId, $serviceId, '2026-03-20 10:05:00');

            [$ineligibleClientId] = $this->createOperationalContext($tenant, 'Cliente Sem WhatsApp', '+5511999998403');
            $ineligibleAppointmentId = $this->createAppointment($tenant, $ineligibleClientId, $professionalId, $serviceId, '2026-03-20 11:30:00');

            $this->withTenantConnection($tenant, function () use ($ineligibleClientId): void {
                \App\Domain\Client\Models\Client::query()->findOrFail($ineligibleClientId)->forceFill([
                    'phone_e164' => null,
                ])->save();
            });
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            $response = $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant));

            $response->assertOk()
                ->assertSee('Cliente Elegível')
                ->assertSee('Cliente Sem WhatsApp')
                ->assertSee($this->panelRelationshipAppointmentReminderUrl($tenant, $eligibleAppointmentId), false)
                ->assertDontSee($this->panelRelationshipAppointmentReminderUrl($tenant, $ineligibleAppointmentId), false)
                ->assertSee('O cliente ainda não possui telefone válido.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_confirmation_button_only_appears_for_confirmation_eligible_rows(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-confirmacao-ux', 'barbearia-relacionamento-confirmacao-ux.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-confirmacao-ux@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            [$eligibleClientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Confirmação Elegível', '+5511999998407');
            $eligibleAppointmentId = $this->createAppointment($tenant, $eligibleClientId, $professionalId, $serviceId, '2026-03-20 10:05:00');

            [$confirmedClientId] = $this->createOperationalContext($tenant, 'Cliente Já Confirmado', '+5511999998408');
            $confirmedAppointmentId = $this->createAppointment($tenant, $confirmedClientId, $professionalId, $serviceId, '2026-03-20 11:30:00');

            $this->withTenantConnection($tenant, function () use ($confirmedAppointmentId): void {
                \App\Domain\Appointment\Models\Appointment::query()->findOrFail($confirmedAppointmentId)->forceFill([
                    'confirmation_status' => 'confirmed',
                ])->save();
            });
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            $response = $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant));

            $response->assertOk()
                ->assertSee('Cliente Confirmação Elegível')
                ->assertSee('Cliente Já Confirmado')
                ->assertSee($this->panelRelationshipAppointmentConfirmationUrl($tenant, $eligibleAppointmentId), false)
                ->assertDontSee($this->panelRelationshipAppointmentConfirmationUrl($tenant, $confirmedAppointmentId), false)
                ->assertSee('Esse agendamento já está confirmado.');
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

    public function test_manual_appointment_confirmation_respects_permission(): void
    {
        $tenant = $this->provisionTenant('barbearia-relacionamento-confirmacao-perm', 'barbearia-relacionamento-confirmacao-perm.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'barber',
            email: 'barbeiro-relacionamento-confirmacao@test.local',
            password: 'password123',
        );

        $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager'));
        $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
        [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Sem Permissão Confirmação', '+5511999998211');
        $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 16:00:00');
        $this->flushHeaders();

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
        ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

        $this->from($this->panelRelationshipUrl($tenant))
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->withUnencryptedCookie((string) config('session.cookie'), $session)
            ->post($this->panelRelationshipAppointmentConfirmationUrl($tenant, $appointmentId), [
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

    public function test_client_reactivation_can_be_snoozed_temporarily_with_audit(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-reativacao-snooze', 'barbearia-relacionamento-reativacao-snooze.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-reativacao-snooze@test.local',
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
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Snooze', '+5511999998310', marketingOptIn: true);
            $this->seedCompletedVisit($tenant, $clientId, $professionalId, $serviceId, '2026-01-10 14:00:00', '2026-01-10 15:00:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

            $this->from($this->panelRelationshipUrl($tenant))
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->withUnencryptedCookie((string) config('session.cookie'), $session)
                ->post($this->panelRelationshipClientReactivationSnoozeUrl($tenant, $clientId), [
                    '_token' => $csrf,
                ])
                ->assertRedirect($this->panelRelationshipUrl($tenant));

            $this->withTenantConnection($tenant, function () use ($clientId): void {
                $client = \App\Domain\Client\Models\Client::query()->findOrFail($clientId);

                $this->assertNotNull($client->whatsapp_reactivation_snoozed_until);
                $this->assertTrue($client->whatsapp_reactivation_snoozed_until->greaterThan(now()));
            });

            $this->assertSame(1, AuditLog::query()->where('action', 'whatsapp_product.client_reactivation.snoozed')->count());

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Cliente Snooze')
                ->assertSee('Ignorado temporariamente')
                ->assertSee('Ignorado até')
                ->assertDontSee($this->panelRelationshipClientReactivationUrl($tenant, $clientId), false)
                ->assertDontSee($this->panelRelationshipClientReactivationSnoozeUrl($tenant, $clientId), false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_client_reactivation_becomes_eligible_again_after_snooze_expiration(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-reativacao-snooze-expira', 'barbearia-relacionamento-reativacao-snooze-expira.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-reativacao-snooze-expira@test.local',
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
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Snooze Expira', '+5511999998311', marketingOptIn: true);
            $this->seedCompletedVisit($tenant, $clientId, $professionalId, $serviceId, '2026-01-10 14:00:00', '2026-01-10 15:00:00');

            $this->withTenantConnection($tenant, function () use ($clientId): void {
                \App\Domain\Client\Models\Client::query()->findOrFail($clientId)->forceFill([
                    'whatsapp_reactivation_snoozed_until' => Carbon::now()->addDays(7),
                ])->save();
            });
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Ignorado temporariamente')
                ->assertDontSee($this->panelRelationshipClientReactivationUrl($tenant, $clientId), false);

            Carbon::setTestNow('2026-03-27 10:00:01');
            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Elegível para reativação')
                ->assertSee($this->panelRelationshipClientReactivationUrl($tenant, $clientId), false)
                ->assertSee($this->panelRelationshipClientReactivationSnoozeUrl($tenant, $clientId), false)
                ->assertDontSee('Ignorado temporariamente');
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

    public function test_client_reactivation_snooze_respects_permission(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-reativacao-snooze-perm', 'barbearia-relacionamento-reativacao-snooze-perm.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'barber',
                email: 'barbeiro-relacionamento-reativacao-snooze@test.local',
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
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Snooze Sem Permissão', '+5511999998312', marketingOptIn: true);
            $this->seedCompletedVisit($tenant, $clientId, $professionalId, $serviceId, '2026-01-10 14:00:00', '2026-01-10 15:00:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

            $this->from($this->panelRelationshipUrl($tenant))
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->withUnencryptedCookie((string) config('session.cookie'), $session)
                ->post($this->panelRelationshipClientReactivationSnoozeUrl($tenant, $clientId), [
                    '_token' => $csrf,
                ])
                ->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_client_reactivation_snooze_links_are_tenant_scoped(): void
    {
        $tenant = $this->provisionTenant('barbearia-relacionamento-reativacao-snooze-scope-a', 'barbearia-relacionamento-reativacao-snooze-scope-a.test');
        $otherTenant = $this->provisionTenant('barbearia-relacionamento-reativacao-snooze-scope-b', 'barbearia-relacionamento-reativacao-snooze-scope-b.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor-relacionamento-reativacao-snooze-scope@test.local',
            password: 'password123',
        );

        $this->withHeaders($this->tenantAuthHeaders($otherTenant, role: 'manager'));
        $this->activateAutomation($otherTenant, 'inactive_client_reactivation', [
            'status' => 'active',
            'conditions_json' => [
                'inactivity_days' => 30,
                'minimum_completed_visits' => 1,
                'require_marketing_opt_in' => true,
                'exclude_with_future_appointments' => true,
            ],
        ]);
        [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($otherTenant, 'Cliente Outro Tenant Snooze', '+5511999998313', marketingOptIn: true);
        $this->seedCompletedVisit($otherTenant, $clientId, $professionalId, $serviceId, '2026-01-10 14:00:00', '2026-01-10 15:00:00');
        $this->flushHeaders();

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

        $this
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->get($this->panelRelationshipUrl($tenant))
            ->assertOk()
            ->assertDontSee('Cliente Outro Tenant Snooze')
            ->assertDontSee($this->panelRelationshipClientReactivationSnoozeUrl($tenant, $clientId), false);
    }

    public function test_manual_client_reactivation_returns_friendly_error_when_client_is_not_eligible(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-reativacao-erro', 'barbearia-relacionamento-reativacao-erro.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-reativacao-erro@test.local',
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
            [$clientId] = $this->createOperationalContext($tenant, 'Cliente Sem Histórico', '+5511999998404', marketingOptIn: true);
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

            $this->from($this->panelRelationshipUrl($tenant))
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->withUnencryptedCookie((string) config('session.cookie'), $session)
                ->post($this->panelRelationshipClientReactivationUrl($tenant, $clientId), [
                    '_token' => $csrf,
                ])
                ->assertRedirect($this->panelRelationshipUrl($tenant))
                ->assertSessionHasErrors([
                    'client' => 'Esse cliente não está elegível para reativação manual agora.',
                ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_appointment_confirmation_returns_friendly_error_when_appointment_is_not_eligible(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-confirmacao-erro', 'barbearia-relacionamento-confirmacao-erro.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-confirmacao-erro@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Já Confirmado Erro', '+5511999998410');
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 12:00:00');
            $this->withTenantConnection($tenant, function () use ($appointmentId): void {
                \App\Domain\Appointment\Models\Appointment::query()->findOrFail($appointmentId)->forceFill([
                    'confirmation_status' => 'confirmed',
                ])->save();
            });
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

            $this->from($this->panelRelationshipUrl($tenant))
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->withUnencryptedCookie((string) config('session.cookie'), $session)
                ->post($this->panelRelationshipAppointmentConfirmationUrl($tenant, $appointmentId), [
                    '_token' => $csrf,
                ])
                ->assertRedirect($this->panelRelationshipUrl($tenant))
                ->assertSessionHasErrors([
                    'appointment' => 'Esse agendamento já está confirmado.',
                ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_relationship_panel_reflects_enqueued_then_dispatched_reminder_honestly(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-status-real', 'barbearia-relacionamento-status-real.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-status-real@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            $this->createDirectConfig($tenant, [
                'slot' => 'primary',
                'provider' => 'fake',
                'enabled' => true,
            ]);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Status Real', '+5511999998405');
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:05:00');
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

            $this->withTenantConnection($tenant, function () use ($appointmentId): void {
                $appointment = \App\Domain\Appointment\Models\Appointment::query()->findOrFail($appointmentId);
                $message = Message::query()->sole();

                $this->assertNull($appointment->reminder_sent_at);
                $this->assertSame('not_sent', $appointment->confirmation_status);
                $this->assertSame('queued', $message->status);
            });

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Lembrete enfileirado')
                ->assertSee('Solicitação em preparo')
                ->assertDontSee('Lembrete enviado');

            $this->artisan('tenancy:process-outbox', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function () use ($appointmentId): void {
                $appointment = \App\Domain\Appointment\Models\Appointment::query()->findOrFail($appointmentId);
                $message = Message::query()->sole();

                $this->assertNotNull($appointment->reminder_sent_at);
                $this->assertSame('awaiting_customer', $appointment->confirmation_status);
                $this->assertSame('dispatched', $message->status);
            });

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Lembrete enviado')
                ->assertSee('Aguardando resposta do cliente');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_relationship_panel_reflects_enqueued_then_dispatched_manual_confirmation_honestly(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-confirmacao-status', 'barbearia-relacionamento-confirmacao-status.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-confirmacao-status@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            $this->createDirectConfig($tenant, [
                'slot' => 'primary',
                'provider' => 'fake',
                'enabled' => true,
            ]);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Confirmação Status', '+5511999998411');
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:05:00');
            $this->flushHeaders();

            $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');
            ['csrf' => $csrf, 'session' => $session] = $this->panelFormContext($this->panelRelationshipUrl($tenant), $panelCookie);

            $this->from($this->panelRelationshipUrl($tenant))
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->withUnencryptedCookie((string) config('session.cookie'), $session)
                ->post($this->panelRelationshipAppointmentConfirmationUrl($tenant, $appointmentId), [
                    '_token' => $csrf,
                ])
                ->assertRedirect($this->panelRelationshipUrl($tenant));

            $this->withTenantConnection($tenant, function () use ($appointmentId): void {
                $appointment = \App\Domain\Appointment\Models\Appointment::query()->findOrFail($appointmentId);
                $message = Message::query()->sole();

                $this->assertSame('confirm_queued', $appointment->confirmation_status);
                $this->assertSame('queued', $message->status);
            });

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Confirmação em preparo')
                ->assertDontSee('Confirmação enviada');

            $this->artisan('tenancy:process-outbox', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function () use ($appointmentId): void {
                $appointment = \App\Domain\Appointment\Models\Appointment::query()->findOrFail($appointmentId);
                $message = Message::query()->sole();

                $this->assertSame('awaiting_customer', $appointment->confirmation_status);
                $this->assertSame('dispatched', $message->status);
            });

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Confirmação enviada')
                ->assertSee('O provider já aceitou o envio da confirmação.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_appointment_confirmation_links_are_tenant_scoped(): void
    {
        $tenant = $this->provisionTenant('barbearia-relacionamento-confirmacao-scope-a', 'barbearia-relacionamento-confirmacao-scope-a.test');
        $otherTenant = $this->provisionTenant('barbearia-relacionamento-confirmacao-scope-b', 'barbearia-relacionamento-confirmacao-scope-b.test');
        $user = $this->createTenantUser(
            tenant: $tenant,
            role: 'manager',
            email: 'gestor-relacionamento-confirmacao-scope@test.local',
            password: 'password123',
        );

        $this->withHeaders($this->tenantAuthHeaders($otherTenant, role: 'manager'));
        $this->activateAutomation($otherTenant, 'appointment_reminder', ['status' => 'active']);
        [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($otherTenant, 'Cliente Outro Tenant Confirmação', '+5511999998412');
        $otherAppointmentId = $this->createAppointment($otherTenant, $clientId, $professionalId, $serviceId, '2026-03-20 16:00:00');
        $this->flushHeaders();

        $panelCookie = $this->loginPanelAndGetCookie($tenant, $user->email, 'password123');

        $this
            ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
            ->get($this->panelRelationshipUrl($tenant))
            ->assertOk()
            ->assertDontSee('Cliente Outro Tenant Confirmação')
            ->assertDontSee($this->panelRelationshipAppointmentConfirmationUrl($tenant, $otherAppointmentId), false);
    }

    public function test_relationship_panel_shows_failed_reminder_after_dispatch_failure_without_claiming_it_was_sent(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant('barbearia-relacionamento-falha-envio', 'barbearia-relacionamento-falha-envio.test');
            $user = $this->createTenantUser(
                tenant: $tenant,
                role: 'manager',
                email: 'gestor-relacionamento-falha-envio@test.local',
                password: 'password123',
            );

            $this->withHeaders($this->tenantAuthHeaders($tenant, role: 'manager', user: $user));
            $this->activateAutomation($tenant, 'appointment_reminder', ['status' => 'active']);
            $this->createDirectConfig($tenant, [
                'slot' => 'primary',
                'provider' => 'fake',
                'retry_profile_json' => [
                    'max_attempts' => 1,
                    'retry_backoff_seconds' => 1,
                ],
                'enabled' => true,
                'settings_json' => [
                    'testing' => [
                        'fail_on_attempts' => [1],
                        'error_code' => 'provider_unavailable',
                        'retryable' => false,
                        'message' => 'Provider indisponível para teste.',
                    ],
                ],
            ]);
            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant, 'Cliente Falha Lembrete', '+5511999998406');
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-20 10:05:00');
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

            $this->artisan('tenancy:process-outbox', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function () use ($appointmentId): void {
                $appointment = \App\Domain\Appointment\Models\Appointment::query()->findOrFail($appointmentId);
                $message = Message::query()->sole();

                $this->assertNull($appointment->reminder_sent_at);
                $this->assertSame('not_sent', $appointment->confirmation_status);
                $this->assertSame('failed', $message->status);
            });

            $this
                ->withUnencryptedCookie((string) config('auth.access_tokens.panel_cookie', 'tenant_panel_access_token'), $panelCookie)
                ->get($this->panelRelationshipUrl($tenant))
                ->assertOk()
                ->assertSee('Falha no lembrete')
                ->assertSee('Último envio falhou')
                ->assertDontSee('Lembrete entregue')
                ->assertDontSee('Aguardando resposta do cliente');
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createDirectConfig(Tenant $tenant, array $attributes): string
    {
        return $this->withTenantConnection($tenant, function () use ($attributes): string {
            return WhatsappProviderConfig::query()->create(array_merge([
                'slot' => 'primary',
                'provider' => 'fake',
                'timeout_seconds' => 10,
                'enabled_capabilities_json' => ['text', 'healthcheck'],
                'enabled' => true,
            ], $attributes))->id;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedRelationshipMessageMetric(array $attributes): string
    {
        $message = Message::query()->create([
            'client_id' => $attributes['client_id'] ?? null,
            'appointment_id' => $attributes['appointment_id'] ?? null,
            'automation_id' => $attributes['automation_id'] ?? null,
            'direction' => 'outbound',
            'channel' => 'whatsapp',
            'provider' => $attributes['provider'] ?? 'fake',
            'thread_key' => $attributes['thread_key']
                ?? (string) ($attributes['client_id'] ?? $attributes['appointment_id'] ?? 'metric-thread'),
            'type' => $attributes['type'] ?? 'text',
            'status' => $attributes['status'] ?? 'queued',
            'body_text' => $attributes['body_text'] ?? 'Mensagem de teste',
            'payload_json' => $attributes['payload_json'] ?? [],
            'failure_reason' => $attributes['failure_reason'] ?? null,
        ]);

        $message->forceFill([
            'created_at' => isset($attributes['created_at']) ? Carbon::parse((string) $attributes['created_at']) : $message->created_at,
            'updated_at' => isset($attributes['updated_at'])
                ? Carbon::parse((string) $attributes['updated_at'])
                : (isset($attributes['created_at']) ? Carbon::parse((string) $attributes['created_at']) : $message->updated_at),
            'sent_at' => isset($attributes['sent_at']) ? Carbon::parse((string) $attributes['sent_at']) : null,
            'delivered_at' => isset($attributes['delivered_at']) ? Carbon::parse((string) $attributes['delivered_at']) : null,
            'read_at' => isset($attributes['read_at']) ? Carbon::parse((string) $attributes['read_at']) : null,
            'failed_at' => isset($attributes['failed_at']) ? Carbon::parse((string) $attributes['failed_at']) : null,
        ])->save();

        return $message->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRelationshipPanelData(Tenant $tenant, string $period): array
    {
        return $this->withTenantConnection($tenant, function () use ($tenant, $period): array {
            $tenantContext = app(TenantContext::class);
            $tenantContext->set($tenant);

            try {
                return app(BuildWhatsappRelationshipPanelDataAction::class)->execute(
                    filters: [
                        'date' => '2026-03-19',
                        'period' => $period,
                    ],
                    visibility: [
                        'appointments' => [
                            'read' => true,
                            'write' => true,
                        ],
                        'clients' => [
                            'read' => true,
                            'write' => true,
                        ],
                    ],
                );
            } finally {
                $tenantContext->clear();
            }
        });
    }

    private function metricValue(array $panel, string $key): ?int
    {
        $card = collect($panel['metrics']['cards'] ?? [])->firstWhere('key', $key);

        return is_array($card) ? (int) ($card['value'] ?? 0) : null;
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $domain = $tenant->domains()->value('domain');

        return sprintf('http://%s/api/v1%s', $domain, $path);
    }
}
