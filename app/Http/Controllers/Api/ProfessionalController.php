<?php

namespace App\Http\Controllers\Api;

use App\Domain\Professional\Models\Professional;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProfessionalRequest;
use App\Http\Resources\ProfessionalResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProfessionalController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProfessionalResource::collection(
            Professional::query()->latest()->paginate(15),
        );
    }

    public function store(StoreProfessionalRequest $request): ProfessionalResource
    {
        $professional = Professional::query()->create([
            ...$request->validated(),
            'role' => $request->validated('role') ?? 'barber',
            'commission_model' => $request->validated('commission_model') ?? 'fixed_percent',
            'active' => $request->has('active') ? $request->boolean('active') : true,
        ]);

        return new ProfessionalResource($professional);
    }

    public function show(string $professional): ProfessionalResource
    {
        return new ProfessionalResource(Professional::query()->findOrFail($professional));
    }
}
