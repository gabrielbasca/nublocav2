<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleAlertController extends Controller
{
    /**
     * List the authenticated owner's alerts for a vehicle, newest first.
     * Only safe fields are exposed (never reporter metadata).
     */
    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);

        $alerts = $vehicle->alerts()
            ->latest()
            ->get(['id', 'vehicle_id', 'reason', 'status', 'read_at', 'created_at', 'updated_at']);

        return response()->json($alerts);
    }

    /**
     * Mark one of the authenticated owner's alerts as read.
     */
    public function markRead(Request $request, Vehicle $vehicle, VehicleAlert $alert): JsonResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);
        abort_unless($alert->vehicle_id === $vehicle->id, 404);

        $alert->markRead();

        return response()->json(
            $alert->refresh()->only(['id', 'vehicle_id', 'reason', 'status', 'read_at', 'created_at', 'updated_at']),
            200,
        );
    }
}
