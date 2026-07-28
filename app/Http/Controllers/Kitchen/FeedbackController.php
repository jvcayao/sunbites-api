<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Mail\FeedbackReplyMail;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class FeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_read' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Feedback::with(['parent:id,first_name,last_name,email', 'student:id,first_name,last_name,student_number'])
            ->latest('created_at');

        if (app()->bound('active_branch')) {
            $query->where('branch_id', app('active_branch')->id);
        }

        if (! empty($validated['search'])) {
            $search = '%'.mb_strtolower($validated['search']).'%';

            $query->where(fn ($q) => $q->whereRaw('lower(message) like ?', [$search])
                ->orWhereHas('student', fn ($student) => $student
                    ->whereRaw('lower(first_name) like ?', [$search])
                    ->orWhereRaw('lower(last_name) like ?', [$search])
                    ->orWhereRaw('lower(student_number) like ?', [$search]))
                ->orWhereHas('parent', fn ($parent) => $parent
                    ->whereRaw('lower(first_name) like ?', [$search])
                    ->orWhereRaw('lower(last_name) like ?', [$search])));
        }

        if (isset($validated['is_read'])) {
            $query->where('is_read', $validated['is_read']);
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        $feedbacks = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => collect($feedbacks->items())->map(fn ($feedback) => [
                'id' => $feedback->id,
                'category' => $feedback->category->value,
                'rating' => $feedback->rating,
                'message' => $feedback->message,
                'is_read' => $feedback->is_read,
                'admin_reply' => $feedback->admin_reply,
                'replied_at' => $feedback->replied_at,
                'parent' => $feedback->parent ? [
                    'id' => $feedback->parent->id,
                    'full_name' => $feedback->parent->full_name,
                    'email' => $feedback->parent->email,
                ] : null,
                'student' => $feedback->student ? [
                    'id' => $feedback->student->id,
                    'student_number' => $feedback->student->student_number,
                    'full_name' => $feedback->student->full_name,
                ] : null,
                'created_at' => $feedback->created_at,
            ]),
            'meta' => $this->paginationMeta($feedbacks),
        ]);
    }

    public function reply(Request $request, Feedback $feedback): JsonResponse
    {
        // Sanitize before validating so the length rules apply to what is
        // actually stored — otherwise markup-only input such as "<br><br>"
        // satisfies `min` and then persists as an empty reply.
        $request->merge(['reply' => $this->sanitizeText($request->input('reply'))]);

        $validated = $request->validate([
            'reply' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $feedback->update([
            'admin_reply' => $validated['reply'],
            'replied_at' => now(),
            'is_read' => true,
        ]);

        // The parent may have been soft-deleted since submitting the feedback;
        // the reply is still recorded, there is simply nobody left to notify.
        if ($feedback->parent !== null) {
            Mail::to($feedback->parent->email)->queue(new FeedbackReplyMail($feedback));
        }

        return response()->json([
            'id' => $feedback->id,
            'admin_reply' => $feedback->admin_reply,
            'replied_at' => $feedback->replied_at,
        ]);
    }

    public function markRead(Feedback $feedback): JsonResponse
    {
        $feedback->update(['is_read' => true]);

        return response()->json(['message' => 'Feedback marked as read.']);
    }
}
