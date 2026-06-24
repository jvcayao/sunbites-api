<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentPhotoController extends Controller
{
    use AuthorizesRequests;

    public function show(Request $request, Student $student): StreamedResponse
    {
        $this->authorize('view', $student);

        abort_if(! $student->photo_path, 404);

        return Storage::disk('private')->response($student->photo_path);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

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
