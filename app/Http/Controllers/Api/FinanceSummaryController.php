<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Finance\BuildFinanceSummaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FinanceSummaryRequest;
use Illuminate\Http\JsonResponse;

class FinanceSummaryController extends Controller
{
    public function __invoke(
        FinanceSummaryRequest $request,
        BuildFinanceSummaryAction $buildFinanceSummary,
    ): JsonResponse {
        return response()->json([
            'data' => $buildFinanceSummary->execute($request->validated()),
        ]);
    }
}
