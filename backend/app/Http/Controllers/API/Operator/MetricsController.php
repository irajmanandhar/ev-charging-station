<?php

namespace App\Http\Controllers\API\Operator;

use App\Http\Controllers\API\BaseController;
use App\Models\Station;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsController extends BaseController
{
    public function __construct(private readonly MetricsService $metrics)
    {
    }

    /** Query: days=7|30|90 (default 30), station_id (optional) */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ]);
        $user = $request->user();
        $tz = config('ev.default_timezone');
        $days = (int) ($data['days'] ?? 30);

        $stations = Station::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('operator_id', $user->id))
            ->when($data['station_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->withCount('chargers')
            ->get();

        if (isset($data['station_id']) && $stations->isEmpty()) {
            return $this->sendError('You are not allowed to do that.', 403);
        }

        $to = now($tz)->addDay()->startOfDay()->utc();
        $from = now($tz)->subDays($days - 1)->startOfDay()->utc();

        return $this->sendResponse($this->metrics->forStations($stations, $from, $to, $tz));
    }
}
