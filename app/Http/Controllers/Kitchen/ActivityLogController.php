<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ListActivityLogsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(ListActivityLogsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $perPage = $validated['per_page'] ?? 25;

        $query = Activity::with('causer')
            ->when(isset($validated['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $validated['date_to']))
            ->when(isset($validated['user_id']), fn ($q) => $q->where('causer_id', $validated['user_id'])->where('causer_type', User::class))
            ->when(isset($validated['log_name']), fn ($q) => $q->where('log_name', $validated['log_name']))
            ->when(isset($validated['search']), fn ($q) => $q->whereContainsLiteral('description', $validated['search']))
            ->latest();

        $logs = $query->paginate($perPage);

        return response()->json([
            'data' => collect($logs->items())->map(fn ($log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->toDateTimeString(),
                'causer_name' => $log->causer?->full_name,
                'description' => $log->description,
                'log_name' => $log->log_name,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'properties' => $log->properties,
            ]),
            'meta' => $this->paginationMeta($logs),
        ]);
    }
}
