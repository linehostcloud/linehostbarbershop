<?php

namespace Tests\Unit\Communication;

use App\Domain\Communication\Enums\WhatsappMessageStatus;
use App\Domain\Communication\Support\WhatsappMessageStateMachine;
use Tests\TestCase;

class WhatsappMessageStateMachineTest extends TestCase
{
    public function test_it_allows_only_valid_outbound_progression(): void
    {
        $stateMachine = app(WhatsappMessageStateMachine::class);

        $decision = $stateMachine->decide(
            direction: 'outbound',
            currentStatus: WhatsappMessageStatus::Delivered,
            incomingStatus: WhatsappMessageStatus::Read,
        );

        $this->assertTrue($decision->shouldApply);
        $this->assertSame('advanced_status', $decision->reason);
    }

    public function test_it_rejects_regressive_outbound_statuses(): void
    {
        $stateMachine = app(WhatsappMessageStateMachine::class);

        $decision = $stateMachine->decide(
            direction: 'outbound',
            currentStatus: WhatsappMessageStatus::Read,
            incomingStatus: WhatsappMessageStatus::Delivered,
        );

        $this->assertFalse($decision->shouldApply);
        $this->assertSame('regressive_status', $decision->reason);
    }

    public function test_it_ignores_duplicate_webhook_statuses(): void
    {
        $stateMachine = app(WhatsappMessageStateMachine::class);

        $decision = $stateMachine->decide(
            direction: 'outbound',
            currentStatus: WhatsappMessageStatus::Delivered,
            incomingStatus: WhatsappMessageStatus::Delivered,
        );

        $this->assertFalse($decision->shouldApply);
        $this->assertSame('duplicate_webhook', $decision->reason);
    }

    public function test_it_preserves_state_on_retryable_failure(): void
    {
        $stateMachine = app(WhatsappMessageStateMachine::class);

        $decision = $stateMachine->decide(
            direction: 'outbound',
            currentStatus: WhatsappMessageStatus::Queued,
            incomingStatus: WhatsappMessageStatus::Failed,
            retryableFailure: true,
        );

        $this->assertFalse($decision->shouldApply);
        $this->assertSame('transient_failure_preserves_state', $decision->reason);
    }

    public function test_it_separates_inbound_and_outbound_state_spaces(): void
    {
        $stateMachine = app(WhatsappMessageStateMachine::class);

        $decision = $stateMachine->decide(
            direction: 'inbound',
            currentStatus: WhatsappMessageStatus::InboundProcessed,
            incomingStatus: WhatsappMessageStatus::Read,
        );

        $this->assertFalse($decision->shouldApply);
        $this->assertSame('direction_mismatch', $decision->reason);
    }

    public function test_it_ignores_unknown_provider_statuses(): void
    {
        $stateMachine = app(WhatsappMessageStateMachine::class);

        $decision = $stateMachine->decide(
            direction: 'outbound',
            currentStatus: WhatsappMessageStatus::Sent,
            incomingStatus: null,
        );

        $this->assertFalse($decision->shouldApply);
        $this->assertSame('unknown_provider_status', $decision->reason);
    }
}
