<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreFeedbackRequest;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $feedbacks = $request->user()
            ->feedbacks()
            ->with('student:id,first_name,last_name')
            ->latest('created_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($feedbacks->items())->map(fn ($feedback) => [
                'id' => $feedback->id,
                'category' => $feedback->category->value,
                'rating' => $feedback->rating,
                'message' => $feedback->message,
                'admin_reply' => $feedback->admin_reply,
                'replied_at' => $feedback->replied_at,
                'is_read' => $feedback->is_read,
                'student' => $feedback->student ? [
                    'id' => $feedback->student->id,
                    'full_name' => $feedback->student->full_name,
                ] : null,
                'created_at' => $feedback->created_at,
            ]),
            'meta' => $this->paginationMeta($feedbacks),
        ]);
    }

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $parent = $request->user();
        $linkedStudents = $request->linkedStudents();

        $validated = $request->validated();

        $studentId = $validated['student_id'] ?? null;

        $branchId = $linkedStudents->firstWhere('id', $studentId)?->branch_id
            ?? $linkedStudents->first()->branch_id;

        $feedback = Feedback::create([
            'parent_id' => $parent->id,
            'student_id' => $studentId,
            'branch_id' => $branchId,
            'category' => $validated['category'],
            'rating' => $validated['rating'],
            'message' => $validated['message'],
            'is_read' => false,
        ]);

        return response()->json([
            'id' => $feedback->id,
            'category' => $feedback->category->value,
            'rating' => $feedback->rating,
            'message' => $feedback->message,
            'created_at' => $feedback->created_at,
        ], 201);
    }
}
