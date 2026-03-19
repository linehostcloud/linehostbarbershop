<?php

namespace App\Domain\Communication\Support;

use App\Domain\Communication\Data\WhatsappStatusTransitionDecision;
use App\Domain\Communication\Enums\WhatsappMessageStatus;

class WhatsappMessageStateMachine
{
    /**
     * @param  'inbound'|'outbound'  $direction
     */
    public function decide(
        string $direction,
        ?WhatsappMessageStatus $currentStatus,
        ?WhatsappMessageStatus $incomingStatus,
        bool $retryableFailure = false,
    ): WhatsappStatusTransitionDecision {
        if ($incomingStatus === null) {
            return new WhatsappStatusTransitionDecision(
                shouldApply: false,
                reason: 'unknown_provider_status',
                direction: $direction,
                currentStatus: $currentStatus,
                incomingStatus: $incomingStatus,
            );
        }

        if (! $this->statusBelongsToDirection($direction, $incomingStatus)) {
            return new WhatsappStatusTransitionDecision(
                shouldApply: false,
                reason: 'direction_mismatch',
                direction: $direction,
                currentStatus: $currentStatus,
                incomingStatus: $incomingStatus,
            );
        }

        if ($incomingStatus === WhatsappMessageStatus::Failed && $retryableFailure) {
            return new WhatsappStatusTransitionDecision(
                shouldApply: false,
                reason: 'transient_failure_preserves_state',
                direction: $direction,
                currentStatus: $currentStatus,
                incomingStatus: $incomingStatus,
            );
        }

        if ($currentStatus === null) {
            return new WhatsappStatusTransitionDecision(
                shouldApply: true,
                reason: 'initial_transition',
                direction: $direction,
                currentStatus: $currentStatus,
                incomingStatus: $incomingStatus,
            );
        }

        if ($currentStatus === $incomingStatus) {
            return new WhatsappStatusTransitionDecision(
                shouldApply: false,
                reason: 'duplicate_webhook',
                direction: $direction,
                currentStatus: $currentStatus,
                incomingStatus: $incomingStatus,
            );
        }

        if (! in_array($incomingStatus, $this->allowedTransitions($direction, $currentStatus), true)) {
            return new WhatsappStatusTransitionDecision(
                shouldApply: false,
                reason: $incomingStatus->rank() < $currentStatus->rank() ? 'regressive_status' : 'transition_not_allowed',
                direction: $direction,
                currentStatus: $currentStatus,
                incomingStatus: $incomingStatus,
            );
        }

        return new WhatsappStatusTransitionDecision(
            shouldApply: true,
            reason: $incomingStatus === WhatsappMessageStatus::Failed ? 'terminal_failure' : 'advanced_status',
            direction: $direction,
            currentStatus: $currentStatus,
            incomingStatus: $incomingStatus,
        );
    }

    /**
     * @param  'inbound'|'outbound'  $direction
     * @return list<WhatsappMessageStatus>
     */
    private function allowedTransitions(string $direction, WhatsappMessageStatus $currentStatus): array
    {
        $outbound = [
            WhatsappMessageStatus::Queued->value => [
                WhatsappMessageStatus::Dispatched,
                WhatsappMessageStatus::Sent,
                WhatsappMessageStatus::Delivered,
                WhatsappMessageStatus::Read,
                WhatsappMessageStatus::Failed,
            ],
            WhatsappMessageStatus::Dispatched->value => [
                WhatsappMessageStatus::Sent,
                WhatsappMessageStatus::Delivered,
                WhatsappMessageStatus::Read,
                WhatsappMessageStatus::Failed,
            ],
            WhatsappMessageStatus::Sent->value => [
                WhatsappMessageStatus::Delivered,
                WhatsappMessageStatus::Read,
                WhatsappMessageStatus::Failed,
            ],
            WhatsappMessageStatus::Delivered->value => [
                WhatsappMessageStatus::Read,
            ],
            WhatsappMessageStatus::Read->value => [],
            WhatsappMessageStatus::Failed->value => [
                WhatsappMessageStatus::Queued,
                WhatsappMessageStatus::Dispatched,
                WhatsappMessageStatus::Sent,
                WhatsappMessageStatus::Delivered,
                WhatsappMessageStatus::Read,
            ],
        ];

        $inbound = [
            WhatsappMessageStatus::InboundReceived->value => [
                WhatsappMessageStatus::InboundProcessed,
            ],
            WhatsappMessageStatus::InboundProcessed->value => [],
            WhatsappMessageStatus::Failed->value => [
                WhatsappMessageStatus::InboundReceived,
                WhatsappMessageStatus::InboundProcessed,
            ],
        ];

        $transitions = $direction === 'inbound' ? $inbound : $outbound;

        return $transitions[$currentStatus->value] ?? [];
    }

    /**
     * @param  'inbound'|'outbound'  $direction
     */
    private function statusBelongsToDirection(string $direction, WhatsappMessageStatus $status): bool
    {
        return match ($direction) {
            'inbound' => in_array($status, [
                WhatsappMessageStatus::InboundReceived,
                WhatsappMessageStatus::InboundProcessed,
                WhatsappMessageStatus::Failed,
            ], true),
            default => in_array($status, [
                WhatsappMessageStatus::Queued,
                WhatsappMessageStatus::Dispatched,
                WhatsappMessageStatus::Sent,
                WhatsappMessageStatus::Delivered,
                WhatsappMessageStatus::Read,
                WhatsappMessageStatus::Failed,
            ], true),
        };
    }
}
