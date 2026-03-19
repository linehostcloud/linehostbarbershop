<?php

namespace App\Domain\Communication\Enums;

enum WhatsappCapability: string
{
    case Text = 'text';
    case Template = 'template';
    case Media = 'media';
    case InboundWebhook = 'inbound_webhook';
    case DeliveryStatus = 'delivery_status';
    case ReadReceipt = 'read_receipt';
    case Healthcheck = 'healthcheck';
    case InstanceManagement = 'instance_management';
    case QrBootstrap = 'qr_bootstrap';
    case OfficialTemplates = 'official_templates';
}
