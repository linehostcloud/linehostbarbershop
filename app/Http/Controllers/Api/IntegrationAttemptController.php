<?php

namespace App\Http\Controllers\Api;

use App\Domain\Integration\Models\IntegrationAttempt;
use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationAttemptResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IntegrationAttemptController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = IntegrationAttempt::query()
            ->with('message')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('provider')) {
            $query->where('provider', (string) $request->string('provider'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', (string) $request->string('direction'));
        }

        return IntegrationAttemptResource::collection($query->paginate(20));
    }
}
