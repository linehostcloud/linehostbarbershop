<?php

namespace App\Http\Controllers\Api;

use App\Domain\Client\Models\Client;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreClientRequest;
use App\Http\Resources\ClientResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClientController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ClientResource::collection(
            Client::query()->latest()->paginate(15),
        );
    }

    public function store(StoreClientRequest $request): ClientResource
    {
        $client = Client::query()->create([
            ...$request->validated(),
            'marketing_opt_in' => $request->boolean('marketing_opt_in'),
            'whatsapp_opt_in' => $request->boolean('whatsapp_opt_in'),
            'retention_status' => $request->validated('retention_status') ?? 'new',
            'visit_count' => 0,
        ]);

        return new ClientResource($client);
    }

    public function show(string $client): ClientResource
    {
        return new ClientResource(Client::query()->findOrFail($client));
    }
}
