<?php

namespace App\Domain\Communication\Enums;

enum WhatsappMessageStatus: string
{
    case Queued = 'queued';
    case Dispatched = 'dispatched';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case DuplicatePrevented = 'duplicate_prevented';
    case Failed = 'failed';
    case InboundReceived = 'inbound_received';
    case InboundProcessed = 'inbound_processed';

    public function rank(): int
    {
        return match ($this) {
            self::Queued => 10,
            self::Dispatched => 20,
            self::Sent => 30,
            self::Delivered => 40,
            self::Read => 50,
            self::DuplicatePrevented => 35,
            self::InboundReceived => 60,
            self::InboundProcessed => 70,
            self::Failed => 25,
        };
    }
}
