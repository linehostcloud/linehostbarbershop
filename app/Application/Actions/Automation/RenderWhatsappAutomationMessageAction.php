<?php

namespace App\Application\Actions\Automation;

use App\Domain\Automation\Models\Automation;

class RenderWhatsappAutomationMessageAction
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{type:string,body_text:?string,payload_json:array<string, mixed>,provider:?string}
     */
    public function execute(Automation $automation, array $context): array
    {
        $definition = is_array($automation->action_payload_json) ? $automation->action_payload_json : [];
        $renderedPayload = $this->renderValue($definition['payload_json'] ?? [], $context);
        $renderedBody = $this->renderValue($definition['body_text'] ?? null, $context);
        $renderedProvider = $this->renderValue($definition['provider'] ?? null, $context);

        return [
            'type' => is_string($definition['type'] ?? null) && $definition['type'] !== ''
                ? (string) $definition['type']
                : 'text',
            'body_text' => is_string($renderedBody) && trim($renderedBody) !== ''
                ? trim($renderedBody)
                : null,
            'payload_json' => is_array($renderedPayload) ? $renderedPayload : [],
            'provider' => is_string($renderedProvider) && trim($renderedProvider) !== ''
                ? trim($renderedProvider)
                : null,
        ];
    }

    private function renderValue(mixed $value, array $context): mixed
    {
        if (is_array($value)) {
            $rendered = [];

            foreach ($value as $key => $item) {
                $rendered[$key] = $this->renderValue($item, $context);
            }

            return $rendered;
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function (array $matches) use ($context): string {
            $resolved = data_get($context, $matches[1], '');

            if (is_scalar($resolved)) {
                return trim((string) $resolved);
            }

            return '';
        }, $value) ?? $value;
    }
}
