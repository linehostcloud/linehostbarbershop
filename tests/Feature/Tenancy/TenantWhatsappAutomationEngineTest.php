<?php

namespace Tests\Feature\Tenancy;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\AutomationRunTarget;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantExecutionLockManager;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantWhatsappAutomationEngineTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_appointment_reminder_finds_eligible_appointment_and_queues_message_through_official_pipeline(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant(
                slug: 'barbearia-automation-reminder',
                domain: 'barbearia-automation-reminder.test',
            );
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
            $this->withHeaders($headers);

            $automationId = $this->activateAutomation($tenant, 'appointment_reminder', [
                'conditions_json' => [
                    'lead_time_minutes' => 180,
                    'selection_tolerance_minutes' => 15,
                    'excluded_statuses' => ['canceled', 'no_show', 'completed'],
                ],
            ]);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant);
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-19 13:00:00');

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->artisan('tenancy:process-outbox', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function () use ($appointmentId, $automationId): void {
                $appointment = Appointment::query()->findOrFail($appointmentId);
                $message = Message::query()->sole();
                $run = AutomationRun::query()->sole();
                $target = AutomationRunTarget::query()->sole();
                $attempt = IntegrationAttempt::query()->sole();

                $this->assertNotNull($appointment->reminder_sent_at);
                $this->assertSame('awaiting_customer', $appointment->confirmation_status);
                $this->assertSame($automationId, $message->automation_id);
                $this->assertSame($appointmentId, $message->appointment_id);
                $this->assertSame('dispatched', $message->status);
                $this->assertNotNull($message->deduplication_key);
                $this->assertSame('appointment_reminder', data_get($message->payload_json, 'automation.type'));
                $this->assertSame($run->id, data_get($message->payload_json, 'automation.run_id'));
                $this->assertSame($target->id, data_get($message->payload_json, 'automation.target_id'));
                $this->assertSame('appointment', data_get($message->payload_json, 'automation.target_reference.type'));
                $this->assertSame($appointmentId, data_get($message->payload_json, 'automation.target_reference.id'));
                $this->assertSame(1, OutboxEvent::query()->where('message_id', $message->id)->count());
                $this->assertSame('completed', $run->status);
                $this->assertSame(1, $run->messages_queued);
                $this->assertSame(0, $run->skipped_total);
                $this->assertSame('queued', $target->status);
                $this->assertSame('appointment', $target->target_type);
                $this->assertSame($appointmentId, $target->target_id);
                $this->assertSame($message->id, $target->message_id);
                $this->assertSame('appointment_due_soon', $target->trigger_reason);
                $this->assertSame('succeeded', $attempt->status);
                $this->assertSame($message->id, $attempt->message_id);
                $this->assertSame(1, EventLog::query()->where('event_name', 'whatsapp.automation.run.completed')->count());
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_appointment_reminder_does_not_fire_for_canceled_appointment(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant(
                slug: 'barbearia-automation-reminder-canceled',
                domain: 'barbearia-automation-reminder-canceled.test',
            );
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
            $this->withHeaders($headers);

            $this->activateAutomation($tenant, 'appointment_reminder', [
                'conditions_json' => [
                    'lead_time_minutes' => 180,
                    'selection_tolerance_minutes' => 15,
                    'excluded_statuses' => ['canceled', 'no_show', 'completed'],
                ],
            ]);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant);
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-19 13:00:00');

            $this->withTenantConnection($tenant, function () use ($appointmentId): void {
                Appointment::query()->findOrFail($appointmentId)->forceFill([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                ])->save();
            });

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function () use ($appointmentId): void {
                $run = AutomationRun::query()->sole();
                $target = AutomationRunTarget::query()->sole();

                $this->assertSame(0, Message::query()->count());
                $this->assertSame(0, OutboxEvent::query()->whereNotNull('message_id')->count());
                $this->assertSame('completed', $run->status);
                $this->assertSame(0, $run->messages_queued);
                $this->assertSame(1, $run->skipped_total);
                $this->assertSame('skipped', $target->status);
                $this->assertSame('appointment_not_eligible', $target->skip_reason);
                $this->assertSame($appointmentId, $target->target_id);
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_appointment_reminder_does_not_duplicate_logical_send(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant(
                slug: 'barbearia-automation-reminder-dedup',
                domain: 'barbearia-automation-reminder-dedup.test',
            );
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
            $this->withHeaders($headers);

            $this->activateAutomation($tenant, 'appointment_reminder', [
                'conditions_json' => [
                    'lead_time_minutes' => 180,
                    'selection_tolerance_minutes' => 15,
                    'excluded_statuses' => ['canceled', 'no_show', 'completed'],
                ],
            ]);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant);
            $appointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-19 13:00:00');

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->artisan('tenancy:process-outbox', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function () use ($appointmentId): void {
                $this->assertSame(1, Message::query()->count());
                $this->assertSame(2, AutomationRun::query()->count());
                $this->assertSame(2, AutomationRunTarget::query()->count());
                $this->assertSame(1, AutomationRunTarget::query()->where('status', 'queued')->count());

                $skippedTarget = AutomationRunTarget::query()
                    ->where('status', 'skipped')
                    ->latest('created_at')
                    ->firstOrFail();

                $this->assertSame('reminder_already_sent', $skippedTarget->skip_reason);
                $this->assertSame($appointmentId, $skippedTarget->target_id);
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_scheduler_executes_automations_without_duplicate_run_when_lock_is_active(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant(
                slug: 'barbearia-automation-lock',
                domain: 'barbearia-automation-lock.test',
            );
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
            $this->withHeaders($headers);

            $this->activateAutomation($tenant, 'appointment_reminder', [
                'conditions_json' => [
                    'lead_time_minutes' => 180,
                    'selection_tolerance_minutes' => 15,
                    'excluded_statuses' => ['canceled', 'no_show', 'completed'],
                ],
            ]);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant);
            $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-19 13:00:00');

            $lockKey = $this->withTenantConnection($tenant, fn (): string => app(TenantExecutionLockManager::class)
                ->lockKeyForCurrentTenantConnection('whatsapp_automations'));

            $lock = Cache::lock($lockKey, 300);
            $this->assertTrue($lock->get());

            try {
                $this->artisan('tenancy:process-whatsapp-automations', [
                    '--tenant' => [$tenant->slug],
                    '--limit' => 10,
                ])->assertExitCode(0);
            } finally {
                $lock->release();
            }

            $this->withTenantConnection($tenant, function (): void {
                $this->assertSame(0, AutomationRun::query()->count());
                $this->assertSame(1, EventLog::query()->where('event_name', 'whatsapp.automation.scheduler_run_started')->count());

                $completed = EventLog::query()
                    ->where('event_name', 'whatsapp.automation.scheduler_run_completed')
                    ->sole();

                $this->assertTrue((bool) data_get($completed->payload_json, 'skipped_due_to_lock'));
                $this->assertSame('skipped_due_to_lock', data_get($completed->result_json, 'status'));
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_whatsapp_automation_command_can_target_specific_tenant(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $firstTenant = $this->provisionTenant(
                slug: 'barbearia-automation-first',
                domain: 'barbearia-automation-first.test',
            );
            $secondTenant = $this->provisionTenant(
                slug: 'barbearia-automation-second',
                domain: 'barbearia-automation-second.test',
            );

            foreach ([$firstTenant, $secondTenant] as $tenant) {
                $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
                $this->withHeaders($headers);
                $this->activateAutomation($tenant, 'appointment_reminder', [
                    'conditions_json' => [
                        'lead_time_minutes' => 180,
                        'selection_tolerance_minutes' => 15,
                        'excluded_statuses' => ['canceled', 'no_show', 'completed'],
                    ],
                ]);

                [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant);
                $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-19 13:00:00');
            }

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$firstTenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($firstTenant, function (): void {
                $this->assertSame(1, AutomationRun::query()->count());
            });

            $this->withTenantConnection($secondTenant, function (): void {
                $this->assertSame(0, AutomationRun::query()->count());
                $this->assertSame(0, Message::query()->count());
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_inactive_client_reactivation_finds_eligible_client(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant(
                slug: 'barbearia-automation-reactivation',
                domain: 'barbearia-automation-reactivation.test',
            );
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
            $this->withHeaders($headers);

            $automationId = $this->activateAutomation($tenant, 'inactive_client_reactivation', [
                'conditions_json' => [
                    'inactivity_days' => 30,
                    'minimum_completed_visits' => 1,
                    'require_marketing_opt_in' => true,
                    'exclude_with_future_appointments' => true,
                ],
            ]);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext(
                tenant: $tenant,
                clientName: 'Cliente Reativacao',
                clientPhone: '+5511999997301',
                marketingOptIn: true,
            );

            $this->seedCompletedVisit(
                tenant: $tenant,
                clientId: $clientId,
                professionalId: $professionalId,
                serviceId: $serviceId,
                appointmentStartsAt: '2026-01-10 09:00:00',
                orderClosedAt: '2026-01-10 10:00:00',
            );

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function () use ($clientId, $automationId): void {
                $client = Client::query()->findOrFail($clientId);
                $message = Message::query()->sole();
                $target = AutomationRunTarget::query()->sole();

                $this->assertSame($automationId, $message->automation_id);
                $this->assertSame($clientId, $message->client_id);
                $this->assertNull($message->appointment_id);
                $this->assertSame('inactive_client_reactivation', data_get($message->payload_json, 'automation.type'));
                $this->assertSame('active', $client->retention_status);
                $this->assertNotNull($client->last_visit_at);
                $this->assertGreaterThanOrEqual(1, (int) $client->visit_count);
                $this->assertSame('client', $target->target_type);
                $this->assertSame($clientId, $target->target_id);
                $this->assertSame('queued', $target->status);
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_inactive_client_reactivation_respects_cooldown(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant(
                slug: 'barbearia-automation-reactivation-cooldown',
                domain: 'barbearia-automation-reactivation-cooldown.test',
            );
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
            $this->withHeaders($headers);

            $this->activateAutomation($tenant, 'inactive_client_reactivation', [
                'conditions_json' => [
                    'inactivity_days' => 30,
                    'minimum_completed_visits' => 1,
                    'require_marketing_opt_in' => true,
                    'exclude_with_future_appointments' => true,
                ],
                'cooldown_hours' => 720,
            ]);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext(
                tenant: $tenant,
                clientName: 'Cliente Reativacao Cooldown',
                clientPhone: '+5511999997302',
                marketingOptIn: true,
            );

            $this->seedCompletedVisit(
                tenant: $tenant,
                clientId: $clientId,
                professionalId: $professionalId,
                serviceId: $serviceId,
                appointmentStartsAt: '2026-01-10 09:00:00',
                orderClosedAt: '2026-01-10 10:00:00',
            );

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($tenant, function () use ($clientId): void {
                $this->assertSame(1, Message::query()->count());

                $skippedTarget = AutomationRunTarget::query()
                    ->where('status', 'skipped')
                    ->latest('created_at')
                    ->firstOrFail();

                $this->assertSame('cooldown_active', $skippedTarget->skip_reason);
                $this->assertSame($clientId, $skippedTarget->target_id);
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_whatsapp_automation_processing_is_tenant_scoped(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $firstTenant = $this->provisionTenant(
                slug: 'barbearia-automation-scope-a',
                domain: 'barbearia-automation-scope-a.test',
            );
            $secondTenant = $this->provisionTenant(
                slug: 'barbearia-automation-scope-b',
                domain: 'barbearia-automation-scope-b.test',
            );

            foreach ([$firstTenant, $secondTenant] as $tenant) {
                $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
                $this->withHeaders($headers);
                $this->activateAutomation($tenant, 'appointment_reminder', [
                    'conditions_json' => [
                        'lead_time_minutes' => 180,
                        'selection_tolerance_minutes' => 15,
                        'excluded_statuses' => ['canceled', 'no_show', 'completed'],
                    ],
                ]);

                [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant);
                $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-19 13:00:00');
            }

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$firstTenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $this->withTenantConnection($firstTenant, function (): void {
                $this->assertSame(1, Message::query()->count());
            });
            $this->withTenantConnection($secondTenant, function (): void {
                $this->assertSame(0, Message::query()->count());
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_operational_feed_and_summary_expose_automation_events(): void
    {
        Carbon::setTestNow('2026-03-19 10:00:00');

        try {
            $tenant = $this->provisionTenant(
                slug: 'barbearia-automation-operations',
                domain: 'barbearia-automation-operations.test',
            );
            $headers = $this->tenantAuthHeaders($tenant, role: 'manager');
            $this->withHeaders($headers);

            $this->activateAutomation($tenant, 'appointment_reminder', [
                'conditions_json' => [
                    'lead_time_minutes' => 180,
                    'selection_tolerance_minutes' => 15,
                    'excluded_statuses' => ['canceled', 'no_show', 'completed'],
                ],
            ]);

            [$clientId, $professionalId, $serviceId] = $this->createOperationalContext($tenant);
            $eligibleAppointmentId = $this->createAppointment($tenant, $clientId, $professionalId, $serviceId, '2026-03-19 13:00:00');
            $secondaryProfessionalId = $this->createProfessional($tenant, 'Profissional Automacao 2');
            $canceledAppointmentId = $this->createAppointment($tenant, $clientId, $secondaryProfessionalId, $serviceId, '2026-03-19 13:10:00');

            $this->withTenantConnection($tenant, function () use ($canceledAppointmentId): void {
                Appointment::query()->findOrFail($canceledAppointmentId)->forceFill([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                ])->save();
            });

            $this->artisan('tenancy:process-whatsapp-automations', [
                '--tenant' => [$tenant->slug],
                '--limit' => 10,
            ])->assertExitCode(0);

            $summaryResponse = $this->withHeaders($headers)->getJson($this->tenantUrl($tenant, '/operations/whatsapp/summary', [
                'from' => '2026-03-19T00:00:00-03:00',
                'to' => '2026-03-20T00:00:00-03:00',
            ]));

            $summaryResponse->assertOk()
                ->assertJsonPath('data.operational_cards.automation_runs_total', 1)
                ->assertJsonPath('data.operational_cards.automation_messages_queued_total', 1)
                ->assertJsonPath('data.operational_cards.automation_skipped_total', 1)
                ->assertJsonPath('data.automations.type_totals.0.type', 'appointment_reminder');

            $feedResponse = $this->withHeaders($headers)->getJson($this->tenantUrl($tenant, '/operations/whatsapp/feed', [
                'from' => '2026-03-19T00:00:00-03:00',
                'to' => '2026-03-20T00:00:00-03:00',
                'type' => 'automation_run_completed',
            ]));

            $feedResponse->assertOk()
                ->assertJsonPath('data.0.type', 'automation_run_completed')
                ->assertJsonPath('data.0.details.automation_type', 'appointment_reminder')
                ->assertJsonPath('data.0.details.messages_queued', 1)
                ->assertJsonPath('data.0.details.skipped_total', 1);

            $this->withTenantConnection($tenant, function () use ($eligibleAppointmentId): void {
                $this->assertSame(1, EventLog::query()->where('event_name', 'whatsapp.automation.run.completed')->count());
                $this->assertSame(1, Message::query()->where('appointment_id', $eligibleAppointmentId)->count());
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_can_list_and_update_whatsapp_automation_defaults(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-automation-admin',
            domain: 'barbearia-automation-admin.test',
        );
        $headers = $this->tenantAuthHeaders($tenant, role: 'automation_admin');

        $indexResponse = $this->withHeaders($headers)
            ->getJson($this->tenantUrl($tenant, '/admin/whatsapp-automations'));

        $indexResponse->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withHeaders($headers)
            ->patchJson($this->tenantUrl($tenant, '/admin/whatsapp-automations/appointment_reminder'), [
                'status' => 'active',
                'conditions' => [
                    'lead_time_minutes' => 120,
                    'selection_tolerance_minutes' => 10,
                    'excluded_statuses' => ['canceled', 'completed'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.conditions.lead_time_minutes', 120);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function createOperationalContext(
        Tenant $tenant,
        string $clientName = 'Cliente Automacao',
        string $clientPhone = '+5511999997300',
        bool $marketingOptIn = false,
    ): array {
        $clientId = $this->postJson($this->tenantUrl($tenant, '/clients'), [
            'full_name' => $clientName,
            'phone_e164' => $clientPhone,
            'whatsapp_opt_in' => true,
            'marketing_opt_in' => $marketingOptIn,
        ])->assertCreated()->json('data.id');

        $professionalId = $this->createProfessional($tenant, 'Profissional Automacao');
        $serviceId = $this->createService($tenant, 'Servico Automacao', 45, 6500);

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
                    'description' => 'Servico Automacao',
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

    private function tenantUrl(Tenant $tenant, string $path, array $query = []): string
    {
        $domain = $tenant->domains()->value('domain');
        $url = sprintf('http://%s/api/v1%s', $domain, $path);

        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query);
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withTenantConnection(Tenant $tenant, \Closure $callback): mixed
    {
        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            return $callback();
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }
    }
}
