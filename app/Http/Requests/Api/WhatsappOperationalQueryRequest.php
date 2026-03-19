<?php

namespace App\Http\Requests\Api;

use App\Application\DTOs\OperationalWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WhatsappOperationalQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'window' => ['nullable', 'string', Rule::in($this->allowedWindows())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'provider' => ['nullable', 'string', 'max:50'],
            'slot' => ['nullable', 'string', Rule::in(['primary', 'secondary'])],
            'status' => ['nullable', 'string', 'max:60'],
            'code' => ['nullable', 'string', 'max:80'],
            'error_code' => ['nullable', 'string', 'max:80'],
            'direction' => ['nullable', 'string', Rule::in(['inbound', 'outbound', 'webhook'])],
            'type' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:50'],
            'attention_type' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if (($from === null) !== ($to === null)) {
                $validator->errors()->add('window', 'Os filtros customizados exigem o envio conjunto de "from" e "to".');
            }
        });
    }

    public function window(): OperationalWindow
    {
        $timezone = config('app.timezone', 'UTC');
        $from = $this->input('from');
        $to = $this->input('to');

        if ($from !== null && $to !== null) {
            return new OperationalWindow(
                label: 'custom',
                startedAt: CarbonImmutable::parse((string) $from, $timezone),
                endedAt: CarbonImmutable::parse((string) $to, $timezone),
            );
        }

        $label = (string) ($this->input('window') ?: config('observability.whatsapp_operations.default_window', '24h'));
        $endedAt = CarbonImmutable::now($timezone);

        $startedAt = match ($label) {
            '24h' => $endedAt->subDay(),
            '7d' => $endedAt->subDays(7),
            '30d' => $endedAt->subDays(30),
            default => $endedAt->subDay(),
        };

        return new OperationalWindow(
            label: in_array($label, $this->allowedWindows(), true) ? $label : '24h',
            startedAt: $startedAt,
            endedAt: $endedAt,
        );
    }

    public function perPage(): int
    {
        return max(
            1,
            min(
                (int) config('observability.whatsapp_operations.max_per_page', 100),
                (int) ($this->input('per_page') ?: config('observability.whatsapp_operations.default_per_page', 20)),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function allowedWindows(): array
    {
        return array_values((array) config('observability.whatsapp_operations.allowed_windows', ['24h', '7d', '30d']));
    }
}
