<?php

namespace App\Http\Controllers\Api;

use App\Domain\Observability\Models\OutboxEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\OutboxEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OutboxEventController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = OutboxEvent::query()
            ->with('eventLog')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('event_name')) {
            $query->where('event_name', (string) $request->string('event_name'));
        }

        return OutboxEventResource::collection($query->paginate(20));
    }
}
