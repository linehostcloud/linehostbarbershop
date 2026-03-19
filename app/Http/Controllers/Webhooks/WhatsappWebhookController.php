<?php

namespace App\Http\Controllers\Webhooks;

use App\Application\Actions\Communication\ReceiveWhatsappWebhookAction;
use App\Application\Actions\Observability\RecordBoundaryRejectionAuditAction;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Http\Controllers\Controller;
use App\Infrastructure\Integration\Whatsapp\BoundaryRejectionCodeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WhatsappWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        ReceiveWhatsappWebhookAction $receiveWhatsappWebhook,
        RecordBoundaryRejectionAuditAction $recordBoundaryRejectionAudit,
        BoundaryRejectionCodeResolver $boundaryCodeResolver,
    ): JsonResponse
    {
        try {
            $result = $receiveWhatsappWebhook->execute($request, $provider);
        } catch (WhatsappProviderException $exception) {
            $boundaryCode = $boundaryCodeResolver->resolve($exception, $request);

            $recordBoundaryRejectionAudit->execute(
                request: $request,
                code: $boundaryCode,
                message: $exception->error->message,
                httpStatus: $this->statusCodeFor($exception->error->code),
                provider: $provider,
                slot: is_string($exception->error->details['slot'] ?? null)
                    ? $exception->error->details['slot']
                    : null,
                context: [
                    'normalized_error_code' => $exception->error->code->value,
                    'provider_error_code' => $exception->error->providerCode,
                ],
                exception: $exception,
            );

            return response()->json([
                'status' => 'rejected',
                'provider' => $provider,
                'normalized_error_code' => $exception->error->code->value,
                'boundary_rejection_code' => $boundaryCode->value,
                'message' => $exception->error->message,
            ], $this->statusCodeFor($exception->error->code));
        } catch (Throwable $throwable) {
            $recordBoundaryRejectionAudit->execute(
                request: $request,
                code: WhatsappBoundaryRejectionCode::UnknownBoundaryError,
                message: 'Erro inesperado antes do inicio do pipeline de webhook.',
                httpStatus: 503,
                provider: $provider,
                exception: $throwable,
            );

            return response()->json([
                'status' => 'rejected',
                'provider' => $provider,
                'boundary_rejection_code' => WhatsappBoundaryRejectionCode::UnknownBoundaryError->value,
                'message' => 'Erro inesperado antes do inicio do pipeline de webhook.',
            ], 503);
        }

        return response()->json([
            'status' => 'accepted',
            'provider' => $result['provider'],
            'duplicate' => $result['duplicate'],
            'event_log_id' => $result['event_log_id'],
            'outbox_event_id' => $result['outbox_event_id'],
            'received_at' => $result['received_at'],
            'payload_keys' => array_keys($request->all()),
        ], 202);
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
