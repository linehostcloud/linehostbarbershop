<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Communication\QueueWhatsappMessageAction;
use App\Application\Actions\Observability\RecordBoundaryRejectionAuditAction;
use App\Infrastructure\Integration\Whatsapp\BoundaryRejectionCodeResolver;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Communication\Enums\WhatsappProviderErrorCode;
use App\Domain\Communication\Exceptions\WhatsappProviderException;
use App\Domain\Communication\Models\Message;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreWhatsappMessageRequest;
use App\Http\Resources\MessageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class MessageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Message::query()
            ->with('client')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', (string) $request->string('direction'));
        }

        if ($request->filled('channel')) {
            $query->where('channel', (string) $request->string('channel'));
        }

        return MessageResource::collection($query->paginate(20));
    }

    public function storeWhatsapp(
        StoreWhatsappMessageRequest $request,
        QueueWhatsappMessageAction $queueWhatsappMessage,
        RecordBoundaryRejectionAuditAction $recordBoundaryRejectionAudit,
        BoundaryRejectionCodeResolver $boundaryCodeResolver,
    ): MessageResource|JsonResponse
    {
        try {
            return new MessageResource(
                $queueWhatsappMessage->execute($request->validated()),
            );
        } catch (WhatsappProviderException $exception) {
            $boundaryCode = $boundaryCodeResolver->resolve($exception, $request);

            $recordBoundaryRejectionAudit->execute(
                request: $request,
                code: $boundaryCode,
                message: $exception->error->message,
                httpStatus: $this->statusCodeFor($exception->error->code),
                provider: is_string($exception->error->details['provider'] ?? null)
                    ? $exception->error->details['provider']
                    : null,
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
                'normalized_error_code' => $exception->error->code->value,
                'boundary_rejection_code' => $boundaryCode->value,
                'message' => $exception->error->message,
            ], $this->statusCodeFor($exception->error->code));
        } catch (Throwable $throwable) {
            $recordBoundaryRejectionAudit->execute(
                request: $request,
                code: WhatsappBoundaryRejectionCode::UnknownBoundaryError,
                message: 'Erro inesperado antes do inicio do pipeline de mensageria.',
                httpStatus: 503,
                exception: $throwable,
            );

            return response()->json([
                'status' => 'rejected',
                'boundary_rejection_code' => WhatsappBoundaryRejectionCode::UnknownBoundaryError->value,
                'message' => 'Erro inesperado antes do inicio do pipeline de mensageria.',
            ], 503);
        }
    }

    public function show(string $message): MessageResource
    {
        return new MessageResource(
            Message::query()
                ->with(['client', 'integrationAttempts', 'outboxEvents.eventLog'])
                ->findOrFail($message),
        );
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
