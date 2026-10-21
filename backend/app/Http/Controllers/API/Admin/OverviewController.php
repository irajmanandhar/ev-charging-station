<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\API\BaseController;
use App\Models\AdminLog;
use App\Models\Charger;
use App\Models\Station;
use App\Models\User;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends BaseController
{
    public function __construct(private readonly MetricsService $metrics)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tz = 'Asia/Kathmandu';
        $data = $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90']]);
        $days = (int) ($data['days'] ?? 30);

        $stations = Station::query()->withCount('chargers')->get();

        return $this->sendResponse([
            'counts' => [
                'users' => User::query()->selectRaw('role, COUNT(*) as n')->groupBy('role')->pluck('n', 'role'),
                'stations' => $stations->countBy(fn ($s) => $s->status->value),
                'chargers' => Charger::query()->count(),
            ],
            'metrics' => $this->metrics->forStations(
                $stations,
                now($tz)->subDays($days - 1)->startOfDay()->utc(),
                now($tz)->addDay()->startOfDay()->utc(),
                $tz,
            ),
            'recent_activity' => AdminLog::query()
                ->with('admin:id,name')
                ->latest()
                ->limit(15)
                ->get()
                ->map(fn (AdminLog $l) => [
                    'id' => $l->id,
                    'action' => $l->action,
                    'subject' => $l->subject_type ? "{$l->subject_type} #{$l->subject_id}" : null,
                    'details' => $l->details,
                    'admin' => $l->admin?->name,
                    'created_at' => $l->created_at->toIso8601ZuluString(),
                ]),
        ]);
    }
}
