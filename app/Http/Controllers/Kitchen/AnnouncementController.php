<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\StoreAnnouncementRequest;
use App\Models\Announcement;
use App\Models\ParentUser;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    public function index(): JsonResponse
    {
        $announcements = Announcement::with('sender')
            ->latest()
            ->paginate(15);

        $readCounts = DB::table('notifications')
            ->selectRaw("json_extract(data, '$.announcement_id') AS announcement_id, COUNT(*) AS total")
            ->whereIn(DB::raw("json_extract(data, '$.announcement_id')"), $announcements->getCollection()->pluck('id')->all())
            ->whereNotNull('read_at')
            ->groupBy('announcement_id')
            ->pluck('total', 'announcement_id');

        $items = $announcements->getCollection()->map(function (Announcement $announcement) use ($readCounts) {
            $readCount = (int) ($readCounts[$announcement->id] ?? 0);

            return [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'message_preview' => mb_substr($announcement->message, 0, 100),
                'sender_name' => $announcement->sender->full_name,
                'recipient_type' => $announcement->recipient_type,
                'recipient_count' => $announcement->recipient_count,
                'read_count' => $readCount,
                'created_at' => $announcement->created_at,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => $this->paginationMeta($announcements),
        ]);
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branch = app('active_branch');

        $announcement = DB::transaction(function () use ($validated, $request, $branch) {
            $announcement = Announcement::create([
                'title' => $validated['title'] ?? null,
                'message' => $validated['message'],
                'sender_id' => $request->user()->id,
                'branch_id' => $branch->id,
                'recipient_type' => $validated['recipient_type'],
                'recipient_count' => 0,
            ]);

            $recipientIds = $validated['recipient_ids'];
            $notified = 0;

            if ($validated['recipient_type'] === 'parents') {
                $recipients = ParentUser::whereHas('students', function ($q) use ($branch) {
                    $q->where('branch_id', $branch->id)->whereNull('deleted_at');
                })->whereIn('id', $recipientIds)->get();
            } else {
                $recipients = User::whereHas('branches', function ($q) use ($branch) {
                    $q->where('branches.id', $branch->id);
                })->where('id', '!=', $request->user()->id)
                    ->whereIn('id', $recipientIds)
                    ->get();
            }

            foreach ($recipients as $recipient) {
                $recipient->notify(new AnnouncementNotification($announcement));
                $notified++;
            }

            $announcement->update(['recipient_count' => $notified]);

            return $announcement;
        });

        return response()->json(['id' => $announcement->id], 201);
    }

    public function show(Announcement $announcement): JsonResponse
    {
        $branch = app('active_branch');

        if ($announcement->branch_id !== $branch->id) {
            abort(404);
        }

        $announcement->load('sender');

        $notifications = DB::table('notifications')
            ->whereRaw("JSON_EXTRACT(data, '$.announcement_id') = ?", [$announcement->id])
            ->get();

        $model = $announcement->recipient_type === 'parents' ? ParentUser::class : User::class;
        $notifiables = $model::whereIn('id', $notifications->pluck('notifiable_id'))->get()->keyBy('id');

        $recipients = $notifications->map(function ($n) use ($notifiables) {
            $notifiable = $notifiables->get($n->notifiable_id);

            return [
                'id' => $n->id,
                'name' => $notifiable?->full_name ?? 'Unknown',
                'read_at' => $n->read_at,
            ];
        });

        return response()->json([
            'data' => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'message' => $announcement->message,
                'sender_name' => $announcement->sender->full_name,
                'recipient_type' => $announcement->recipient_type,
                'recipient_count' => $announcement->recipient_count,
                'created_at' => $announcement->created_at,
                'recipients' => $recipients,
            ],
        ]);
    }
}
