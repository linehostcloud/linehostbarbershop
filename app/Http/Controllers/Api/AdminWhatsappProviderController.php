<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Communication\PersistWhatsappProviderConfigAction;
use App\Application\Actions\Communication\RecordWhatsappProviderAdminAuditAction;
use App\Application\Actions\Communication\RunWhatsappProviderHealthcheckAction;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAdminWhatsappProviderConfigRequest;
use App\Http\Requests\Api\UpdateAdminWhatsappProviderConfigRequest;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderConfigValidator;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderConfigViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminWhatsappProviderController extends Controller
{
    /**
     * @var list<string>
     */
    private const VALID_SLOTS = ['primary', 'secondary'];

    public function index(WhatsappProviderConfigViewFactory $viewFactory): JsonResponse
    {
        $configurations = WhatsappProviderConfig::query()
            ->orderByRaw("case when slot = 'primary' then 0 else 1 end")
            ->get()
            ->map(fn (WhatsappProviderConfig $configuration): array => $viewFactory->summary($configuration))
            ->values()
            ->all();

        return response()->json([
            'data' => $configurations,
        ]);
    }

    public function show(string $slot, WhatsappProviderConfigViewFactory $viewFactory): JsonResponse
    {
        return response()->json([
            'data' => $viewFactory->detail($this->findConfigurationBySlot($slot)),
        ]);
    }

    public function store(
        StoreAdminWhatsappProviderConfigRequest $request,
        PersistWhatsappProviderConfigAction $persistConfiguration,
        RecordWhatsappProviderAdminAuditAction $recordAudit,
        WhatsappProviderConfigViewFactory $viewFactory,
    ): JsonResponse {
        $payload = $request->validated();

        if (WhatsappProviderConfig::query()->where('slot', $payload['slot'])->exists()) {
            return response()->json([
                'message' => sprintf('O tenant ja possui configuracao cadastrada para o slot "%s".', $payload['slot']),
            ], 409);
        }

        try {
            $result = $persistConfiguration->create($payload);
        } catch (WhatsappProviderException $exception) {
            return $this->providerErrorResponse($exception);
        }

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_provider_config.created',
            configuration: $result->configuration,
            before: null,
            after: $result->after,
            metadata: [
                'request_payload' => $viewFactory->sanitizeAdminPayload($payload),
            ],
        );

        return response()->json([
            'data' => $viewFactory->detail($result->configuration),
        ], 201);
    }

    public function update(
        string $slot,
        UpdateAdminWhatsappProviderConfigRequest $request,
        PersistWhatsappProviderConfigAction $persistConfiguration,
        RecordWhatsappProviderAdminAuditAction $recordAudit,
        WhatsappProviderConfigViewFactory $viewFactory,
    ): JsonResponse {
        $payload = $request->validated();

        if ($payload === []) {
            return response()->json([
                'message' => 'Nenhum campo administrativo valido foi informado para atualizacao.',
            ], 422);
        }

        $configuration = $this->findConfigurationBySlot($slot);

        try {
            $result = $persistConfiguration->update($configuration, $payload);
        } catch (WhatsappProviderException $exception) {
            return $this->providerErrorResponse($exception);
        }

        $metadata = [
            'request_payload' => $viewFactory->sanitizeAdminPayload($payload),
            'rotated_secret_fields' => $result->rotatedSecretFields !== [] ? $result->rotatedSecretFields : null,
        ];

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_provider_config.updated',
            configuration: $result->configuration,
            before: $result->before,
            after: $result->after,
            metadata: $metadata,
        );

        if ($result->rotatedSecretFields !== []) {
            $recordAudit->execute(
                request: $request,
                action: 'whatsapp_provider_config.rotated_secret',
                configuration: $result->configuration,
                before: $result->before,
                after: $result->after,
                metadata: [
                    'request_payload' => $viewFactory->sanitizeAdminPayload($payload),
                    'rotated_secret_fields' => $result->rotatedSecretFields,
                ],
            );
        }

        return response()->json([
            'data' => $viewFactory->detail($result->configuration),
        ]);
    }

    public function activate(
        Request $request,
        string $slot,
        WhatsappProviderConfigValidator $configValidator,
        RecordWhatsappProviderAdminAuditAction $recordAudit,
        WhatsappProviderConfigViewFactory $viewFactory,
    ): JsonResponse {
        $configuration = $this->findConfigurationBySlot($slot);
        $before = $viewFactory->snapshot($configuration);

        try {
            $configValidator->assertCanPersist($configuration);
        } catch (WhatsappProviderException $exception) {
            return $this->providerErrorResponse($exception);
        }

        $configuration->forceFill([
            'enabled' => true,
            'last_validated_at' => now(),
        ])->save();
        $configuration->refresh();

        $after = $viewFactory->snapshot($configuration);

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_provider_config.activated',
            configuration: $configuration,
            before: $before,
            after: $after,
        );

        return response()->json([
            'data' => $viewFactory->detail($configuration),
        ]);
    }

    public function deactivate(
        Request $request,
        string $slot,
        RecordWhatsappProviderAdminAuditAction $recordAudit,
        WhatsappProviderConfigViewFactory $viewFactory,
    ): JsonResponse {
        $configuration = $this->findConfigurationBySlot($slot);
        $before = $viewFactory->snapshot($configuration);

        $configuration->forceFill([
            'enabled' => false,
        ])->save();
        $configuration->refresh();

        $after = $viewFactory->snapshot($configuration);

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_provider_config.deactivated',
            configuration: $configuration,
            before: $before,
            after: $after,
        );

        return response()->json([
            'data' => $viewFactory->detail($configuration),
        ]);
    }

    public function healthcheck(
        Request $request,
        string $slot,
        RunWhatsappProviderHealthcheckAction $runHealthcheck,
        RecordWhatsappProviderAdminAuditAction $recordAudit,
        WhatsappProviderConfigViewFactory $viewFactory,
    ): JsonResponse {
        $configuration = $this->findConfigurationBySlot($slot);
        $snapshot = $viewFactory->snapshot($configuration);

        try {
            $result = $runHealthcheck->execute($configuration);
            $payload = $viewFactory->healthcheck($configuration, $result);
        } catch (WhatsappProviderException $exception) {
            $recordAudit->execute(
                request: $request,
                action: 'whatsapp_provider_config.healthcheck_requested',
                configuration: $configuration,
                before: $snapshot,
                after: $snapshot,
                metadata: [
                    'result' => [
                        'healthy' => false,
                        'error' => [
                            'code' => $exception->error->code->value,
                            'message' => $exception->error->message,
                            'retryable' => $exception->error->retryable,
                            'http_status' => $exception->error->httpStatus,
                            'provider_code' => $exception->error->providerCode,
                        ],
                    ],
                ],
            );

            return $this->providerErrorResponse($exception);
        } catch (Throwable $throwable) {
            $recordAudit->execute(
                request: $request,
                action: 'whatsapp_provider_config.healthcheck_requested',
                configuration: $configuration,
                before: $snapshot,
                after: $snapshot,
                metadata: [
                    'result' => [
                        'healthy' => false,
                        'error' => [
                            'message' => 'Erro inesperado ao executar healthcheck administrativo.',
                        ],
                    ],
                ],
            );

            return response()->json([
                'message' => 'Erro inesperado ao executar healthcheck administrativo.',
            ], 500);
        }

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_provider_config.healthcheck_requested',
            configuration: $configuration,
            before: $snapshot,
            after: $snapshot,
            metadata: [
                'result' => $payload,
            ],
        );

        return response()->json([
            'data' => $payload,
        ]);
    }

    private function findConfigurationBySlot(string $slot): WhatsappProviderConfig
    {
        abort_unless(in_array($slot, self::VALID_SLOTS, true), 404, 'Slot de provider WhatsApp invalido.');

        return WhatsappProviderConfig::query()
            ->where('slot', $slot)
            ->firstOrFail();
    }

    private function providerErrorResponse(WhatsappProviderException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->error->message,
            'normalized_error_code' => $exception->error->code->value,
        ], $this->statusCodeFor($exception->error->code));
    }

    private function statusCodeFor(WhatsappProviderErrorCode $code): int
    {
        return match ($code) {
            WhatsappProviderErrorCode::AuthenticationError => 401,
            WhatsappProviderErrorCode::AuthorizationError,
            WhatsappProviderErrorCode::WebhookSignatureInvalid => 403,
            WhatsappProviderErrorCode::ValidationError,
            WhatsappProviderErrorCode::UnsupportedFeature => 422,
            default => 503,
        };
    }
}
