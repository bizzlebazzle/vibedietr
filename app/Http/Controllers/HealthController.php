<?php

namespace App\Http\Controllers;

use App\Observability\Health\HealthService;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function live(HealthService $health): JsonResponse
    {
        return response()->json($health->liveness());
    }

    public function ready(HealthService $health): JsonResponse
    {
        $report = $health->readiness();

        return response()->json(
            ['status' => $report['status']],
            $report['status'] === 'unhealthy' ? 503 : 200,
        );
    }
}
