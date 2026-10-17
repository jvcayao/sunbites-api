<?php

namespace App\Http\Controllers\Kitchen;

use App\Actions\Pos\CheckoutAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\CheckoutRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request, CheckoutAction $checkout): JsonResponse
    {
        $order = $checkout->execute($request->validated(), $request->user());

        return response()->json(['message' => 'Order created successfully.', 'order' => new OrderResource($order)], 201);
    }
}
