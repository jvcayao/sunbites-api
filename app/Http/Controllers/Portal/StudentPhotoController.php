<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentPhotoController extends Controller
{
    #[Authorize('view', 'student')]
    public function show(Request $request, Student $student): StreamedResponse
    {
        abort_if(! $student->photo_path, 404);

        return Storage::disk('private')->response($student->photo_path);
    }

    #[Authorize('view', 'student')]
    public function store(Request $request, Student $student): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $oldPath = $student->photo_path;

        $path = $request->file('photo')->store('photos/students', 'private');

        $student->update(['photo_path' => $path]);

        if ($oldPath) {
            Storage::disk('private')->delete($oldPath);
        }

        return response()->json([
            'photo_url' => url("/api/v1/portal/students/{$student->id}/photo"),
        ]);
    }
}
