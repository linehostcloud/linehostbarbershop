<?php

namespace App\Domain\Communication\Data;

readonly class OutboundWhatsappMessageData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $messageId,
        public string $type,
        public string $recipientPhoneE164,
        public string $threadKey,
        public ?string $bodyText = null,
        public ?string $templateName = null,
        public ?string $templateLanguage = null,
        public ?string $mediaUrl = null,
        public ?string $mediaMimeType = null,
        public ?string $mediaFilename = null,
        public ?string $caption = null,
        public ?string $replyToMessageId = null,
        public array $payload = [],
    ) {
    }
}
