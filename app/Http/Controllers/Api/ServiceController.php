<?php

namespace App\Http\Controllers\Api;

use App\Domain\Service\Models\Service;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreServiceRequest;
use App\Http\Resources\ServiceResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            Service::query()->latest()->paginate(15),
        );
    }

    public function store(StoreServiceRequest $request): ServiceResource
    {
        $service = Service::query()->create([
            ...$request->validated(),
            'commissionable' => $request->has('commissionable') ? $request->boolean('commissionable') : true,
            'requires_subscription' => $request->boolean('requires_subscription'),
            'active' => $request->has('active') ? $request->boolean('active') : true,
        ]);

        return new ServiceResource($service);
    }

    public function show(string $service): ServiceResource
    {
        return new ServiceResource(Service::query()->findOrFail($service));
    }
}
