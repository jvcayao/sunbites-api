<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ListFeedbackRequest;
use App\Http\Requests\Kitchen\ReplyFeedbackRequest;
use App\Mail\FeedbackReplyMail;
use App\Models\Feedback;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class FeedbackController extends Controller
{
    public function index(ListFeedbackRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = Feedback::with(['parent:id,first_name,last_name,email', 'student:id,first_name,last_name,student_number'])
            ->latest('created_at');

        $query->where('branch_id', app('active_branch')->id);

        if (! empty($validated['search'])) {
            $search = mb_strtolower($validated['search']);

            $query->where(fn ($q) => $q->whereContainsLiteral('lower(message)', $search)
                ->orWhereHas('student', fn ($student) => $student
                    ->whereContainsLiteral('lower(first_name)', $search)
                    ->whereContainsLiteral('lower(last_name)', $search, 'or')
                    ->whereContainsLiteral('lower(student_number)', $search, 'or'))
                ->orWhereHas('parent', fn ($parent) => $parent
                    ->whereContainsLiteral('lower(first_name)', $search)
                    ->whereContainsLiteral('lower(last_name)', $search, 'or')));
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

    public function reply(ReplyFeedbackRequest $request, Feedback $feedback): JsonResponse
    {
        $validated = $request->validated();

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
        if ($feedback->branch_id !== app('active_branch')->id) {
            throw (new ModelNotFoundException)->setModel(Feedback::class);
        }

        $feedback->update(['is_read' => true]);

        return response()->json(['message' => 'Feedback marked as read.']);
    }
}
