<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::where('is_active', true)
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $branches]);
    }
}
