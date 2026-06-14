<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\ParentUser;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    public function index(): JsonResponse
    {
        $announcements = Announcement::with('sender')
            ->latest()
            ->paginate(15);

        $items = $announcements->getCollection()->map(function (Announcement $announcement) {
            $readCount = DB::table('notifications')
                ->whereRaw("JSON_EXTRACT(data, '$.announcement_id') = ?", [$announcement->id])
                ->whereNotNull('read_at')
                ->count();

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'recipient_type' => ['required', 'in:parents,staff'],
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['integer'],
        ]);

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

        $recipients = $notifications->map(function ($n) use ($announcement) {
            $data = json_decode($n->data, true);

            if ($announcement->recipient_type === 'parents') {
                $notifiable = ParentUser::find($n->notifiable_id);
            } else {
                $notifiable = User::find($n->notifiable_id);
            }

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
