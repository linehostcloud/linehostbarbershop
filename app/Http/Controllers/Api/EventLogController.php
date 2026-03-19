<?php

namespace App\Http\Controllers\Api;

use App\Domain\Observability\Models\EventLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventLogController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = EventLog::query()->latest('occurred_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('event_name')) {
            $query->where('event_name', (string) $request->string('event_name'));
        }

        if ($request->filled('message_id')) {
            $query->where('message_id', (string) $request->string('message_id'));
        }

        return EventLogResource::collection($query->paginate(20));
    }
}
