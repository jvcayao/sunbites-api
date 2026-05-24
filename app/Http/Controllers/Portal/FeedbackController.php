<?php

namespace App\Http\Controllers\Portal;

use App\Enums\FeedbackCategory;
use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'meta' => [
                'current_page' => $feedbacks->currentPage(),
                'last_page' => $feedbacks->lastPage(),
                'per_page' => $feedbacks->perPage(),
                'total' => $feedbacks->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $parent = $request->user();

        $linkedStudents = $parent->students()->get(['students.id', 'branch_id']);
        $linkedStudentIds = $linkedStudents->pluck('id')->all();

        $validated = $request->validate([
            'student_id' => ['required', 'integer', Rule::in($linkedStudentIds)],
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $student = $linkedStudents->firstWhere('id', $validated['student_id']);

        $feedback = Feedback::create([
            'parent_id' => $parent->id,
            'student_id' => $validated['student_id'],
            'branch_id' => $student->branch_id,
            'category' => $validated['category'],
            'rating' => $validated['rating'],
            'message' => strip_tags($validated['message']),
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
