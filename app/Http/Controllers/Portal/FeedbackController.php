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
            'meta' => $this->paginationMeta($feedbacks),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $parent = $request->user();

        $linkedStudents = $parent->students()->get(['students.id', 'branch_id']);
        $linkedStudentIds = $linkedStudents->pluck('id')->all();

        if ($linkedStudents->isEmpty()) {
            return response()->json(['message' => 'You have no linked students.'], 422);
        }

        $validated = $request->validate([
            'student_id' => ['nullable', 'integer', Rule::in($linkedStudentIds)],
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $studentId = $validated['student_id'] ?? null;

        $branchId = $linkedStudents->firstWhere('id', $studentId)?->branch_id
            ?? $linkedStudents->first()->branch_id;

        $feedback = Feedback::create([
            'parent_id' => $parent->id,
            'student_id' => $studentId,
            'branch_id' => $branchId,
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
