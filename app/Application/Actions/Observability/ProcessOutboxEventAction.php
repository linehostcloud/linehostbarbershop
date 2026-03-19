<?php

namespace App\Application\Actions\Observability;

use App\Application\Actions\Communication\DispatchWhatsappMessageAction;
use App\Application\Actions\Communication\ProcessWhatsappWebhookAction;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Observability\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessOutboxEventAction
{
    public function __construct(
        private readonly DispatchWhatsappMessageAction $dispatchWhatsappMessage,
        private readonly ProcessWhatsappWebhookAction $processWhatsappWebhook,
    ) {}

    public function execute(OutboxEvent $outboxEvent): OutboxEvent
    {
        $claimedEvent = $this->claim($outboxEvent);

        if ($claimedEvent->status !== 'processing') {
            return $claimedEvent;
        }

        try {
            $result = $this->handle($claimedEvent);

            return $this->markProcessed($claimedEvent, $result);
        } catch (Throwable $throwable) {
            return $this->markFailure($claimedEvent, $throwable);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(OutboxEvent $outboxEvent): array
    {
        return match ($outboxEvent->event_name) {
            'appointment.created',
            'order.closed' => [
                'processor' => 'automation_audit',
                'decision' => 'event_recorded_for_future_rules',
            ],
            'whatsapp.message.dispatch.requested' => $this->dispatchWhatsappMessage->execute($outboxEvent),
            'whatsapp.webhook.process.requested' => $this->processWhatsappWebhook->execute($outboxEvent),
            default => [
                'processor' => 'outbox',
                'decision' => 'ignored',
                'reason' => 'Nenhum handler registrado para o evento.',
            ],
        };
    }

    private function claim(OutboxEvent $outboxEvent): OutboxEvent
    {
        $connection = config('tenancy.tenant_connection', 'tenant');

        return DB::connection($connection)->transaction(function () use ($outboxEvent) {
            $updated = OutboxEvent::query()
                ->whereKey($outboxEvent->id)
                ->whereIn('status', ['pending', 'retry_scheduled'])
                ->where(function ($query): void {
                    $query
                        ->whereNull('available_at')
                        ->orWhere('available_at', '<=', now());
                })
                ->update([
                    'status' => 'processing',
                    'attempt_count' => DB::raw('attempt_count + 1'),
                    'reserved_at' => now(),
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);

            /** @var OutboxEvent $freshEvent */
            $freshEvent = OutboxEvent::query()->with('eventLog', 'message')->findOrFail($outboxEvent->id);

            if ($updated === 0) {
                return $freshEvent->fresh(['eventLog', 'message']);
            }

            $freshEvent->eventLog?->forceFill([
                'status' => 'processing',
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();

            return $freshEvent->fresh(['eventLog', 'message']);
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markProcessed(OutboxEvent $outboxEvent, array $result): OutboxEvent
    {
        $connection = config('tenancy.tenant_connection', 'tenant');

        return DB::connection($connection)->transaction(function () use ($outboxEvent, $result) {
            /** @var OutboxEvent $freshEvent */
            $freshEvent = OutboxEvent::query()
                ->with(['eventLog', 'message', 'integrationAttempts'])
                ->findOrFail($outboxEvent->id);

            $freshEvent->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();

            $freshEvent->eventLog?->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'result_json' => $result,
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();

            return $freshEvent->fresh(['eventLog', 'message', 'integrationAttempts']);
        });
    }

    private function markFailure(OutboxEvent $outboxEvent, Throwable $throwable): OutboxEvent
    {
        $connection = config('tenancy.tenant_connection', 'tenant');

        return DB::connection($connection)->transaction(function () use ($outboxEvent, $throwable) {
            /** @var OutboxEvent $freshEvent */
            $freshEvent = OutboxEvent::query()
                ->with(['eventLog', 'message', 'integrationAttempts'])
                ->findOrFail($outboxEvent->id);

            $willRetry = $this->shouldRetry($freshEvent, $throwable);
            $nextAttemptAt = $willRetry ? now()->addSeconds($freshEvent->retry_backoff_seconds) : null;

            $freshEvent->forceFill([
                'status' => $willRetry ? 'retry_scheduled' : 'failed',
                'available_at' => $nextAttemptAt ?: $freshEvent->available_at,
                'failed_at' => $willRetry ? null : now(),
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            $freshEvent->eventLog?->forceFill([
                'status' => $willRetry ? 'retry_scheduled' : 'failed',
                'result_json' => [
                    'error' => $throwable->getMessage(),
                    'next_retry_at' => $nextAttemptAt?->toIso8601String(),
                ],
                'failed_at' => $willRetry ? null : now(),
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            return $freshEvent->fresh(['eventLog', 'message', 'integrationAttempts']);
        });
    }

    private function shouldRetry(OutboxEvent $outboxEvent, Throwable $throwable): bool
    {
        if ($outboxEvent->attempt_count >= $outboxEvent->max_attempts) {
            return false;
        }

        if ($throwable instanceof WhatsappProviderException) {
            return $throwable->isRetryable();
        }

        return true;
    }
}
