<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->vehicles()->latest()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'license_plate' => Vehicle::normalizeLicensePlate((string) $request->input('license_plate', '')),
        ]);

        $data = $request->validate([
            'license_plate' => ['required', 'string', 'min:2', 'max:16', 'unique:vehicles,license_plate'],
        ]);

        $vehicle = $request->user()->vehicles()->create($data);

        return response()->json($vehicle, 201);
    }

    public function destroy(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);
        abort_if($vehicle->conversations()->exists(), 409, 'Vehicles with conversation history cannot be deleted.');

        $vehicle->delete();

        return response()->json(status: 204);
    }
}
