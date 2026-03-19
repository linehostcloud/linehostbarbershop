<?php

namespace App\Infrastructure\Integration\Whatsapp\Providers;

use App\Domain\Communication\Data\ProviderDispatchResult;
use App\Domain\Communication\Data\ProviderErrorData;
use App\Domain\Communication\Data\ProviderHealthCheckResult;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Infrastructure\Integration\Whatsapp\ProviderEndpointGuard;
use App\Infrastructure\Integration\Whatsapp\WhatsappPayloadSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class AbstractHttpWhatsappProvider
{
    public function __construct(
        protected readonly WhatsappPayloadSanitizer $sanitizer,
        protected readonly ProviderEndpointGuard $endpointGuard,
        protected readonly \App\Infrastructure\Integration\Whatsapp\WhatsappProviderCapabilityMatrix $capabilityMatrix,
    ) {
    }

    protected function baseRequest(WhatsappProviderConfig $configuration): PendingRequest
    {
        $this->endpointGuard->assertSafe($configuration);

        return Http::baseUrl((string) $configuration->base_url)
            ->acceptJson()
            ->asJson()
            ->timeout($configuration->timeoutSeconds())
            ->connectTimeout($configuration->timeoutSeconds());
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     */
    protected function dispatchHttpCall(
        WhatsappProviderConfig $configuration,
        string $operation,
        array $requestPayload,
        \Closure $requestCallback,
        \Closure $successMapper,
    ): ProviderDispatchResult {
        $startedAt = microtime(true);
        $sanitizedRequest = $this->sanitizer->sanitize($requestPayload);

        try {
            /** @var Response $response */
            $response = $requestCallback($this->baseRequest($configuration));
        } catch (ConnectionException $exception) {
            $normalizedCode = str_contains(mb_strtolower($exception->getMessage()), 'timed out')
                ? WhatsappProviderErrorCode::TimeoutError
                : WhatsappProviderErrorCode::TransientNetworkError;

            throw new WhatsappProviderException(new ProviderErrorData(
                code: $normalizedCode,
                message: sprintf('Falha de conectividade ao executar "%s" no provider.', $operation),
                retryable: true,
                details: ['operation' => $operation],
            ), $exception);
        } catch (RequestException $exception) {
            $response = $exception->response;

            throw $this->mapHttpFailure(
                operation: $operation,
                response: $response,
                latencyMs: $this->latencyMs($startedAt),
            );
        }

        if ($response->failed()) {
            throw $this->mapHttpFailure(
                operation: $operation,
                response: $response,
                latencyMs: $this->latencyMs($startedAt),
            );
        }

        /** @var ProviderDispatchResult $result */
        $result = $successMapper($response, $this->latencyMs($startedAt), $sanitizedRequest);

        return $result;
    }

    protected function mapHttpFailure(string $operation, Response $response, int $latencyMs): WhatsappProviderException
    {
        $payload = $response->json();
        $errorPayload = is_array($payload) ? ($payload['error'] ?? $payload) : [];
        $message = is_array($errorPayload)
            ? (string) ($errorPayload['message'] ?? $errorPayload['error'] ?? sprintf('Falha no provider durante "%s".', $operation))
            : sprintf('Falha no provider durante "%s".', $operation);

        $status = $response->status();

        $errorCode = match (true) {
            $status === 401 => WhatsappProviderErrorCode::AuthenticationError,
            $status === 403 => WhatsappProviderErrorCode::AuthorizationError,
            $status === 408 => WhatsappProviderErrorCode::TimeoutError,
            $status === 422, $status === 400 => WhatsappProviderErrorCode::ValidationError,
            $status === 429 => WhatsappProviderErrorCode::RateLimit,
            $status >= 500 => WhatsappProviderErrorCode::ProviderUnavailable,
            default => WhatsappProviderErrorCode::PermanentProviderError,
        };

        throw new WhatsappProviderException(new ProviderErrorData(
            code: $errorCode,
            message: $message,
            retryable: in_array($errorCode, [
                WhatsappProviderErrorCode::RateLimit,
                WhatsappProviderErrorCode::TimeoutError,
                WhatsappProviderErrorCode::ProviderUnavailable,
            ], true),
            httpStatus: $status,
            providerCode: is_array($errorPayload) ? (string) ($errorPayload['code'] ?? $errorPayload['error_subcode'] ?? '') ?: null : null,
            requestId: $response->header('x-request-id') ?: $response->header('x-fb-trace-id'),
            details: [
                'payload' => is_array($payload) ? $this->sanitizer->sanitize($payload) : [],
                'latency_ms' => $latencyMs,
            ],
        ));
    }

    protected function unsupportedResult(string $provider, string $feature): ProviderDispatchResult
    {
        throw new WhatsappProviderException(new ProviderErrorData(
            code: WhatsappProviderErrorCode::UnsupportedFeature,
            message: sprintf('O provider "%s" nao suporta a capability "%s".', $provider, $feature),
            retryable: false,
        ));
    }

    protected function supportsCapability(string $provider, string $feature): bool
    {
        return $this->capabilityMatrix->isImplemented($provider, $feature);
    }

    protected function healthcheckFailure(\Throwable $throwable): ProviderHealthCheckResult
    {
        $error = $throwable instanceof WhatsappProviderException
            ? $throwable->error
            : new ProviderErrorData(
                code: WhatsappProviderErrorCode::UnknownError,
                message: $throwable->getMessage(),
                retryable: false,
            );

        return new ProviderHealthCheckResult(
            healthy: false,
            details: [],
            error: $error,
        );
    }

    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    private function latencyMs(float $startedAt): int
    {
        return max(1, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
