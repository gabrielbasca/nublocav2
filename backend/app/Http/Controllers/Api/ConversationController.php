<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MessageTemplate;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->where('started_by_user_id', $user->id)
            ->orWhereHas('vehicle', fn ($query) => $query->where('user_id', $user->id))
            ->with(['vehicle:id,license_plate', 'messages'])
            ->latest('updated_at')
            ->get();

        return response()->json($conversations);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);

        return response()->json($conversation->load([
            'vehicle:id,license_plate',
            'messages' => fn ($query) => $query->with('template:id,type')->orderBy('created_at'),
        ]));
    }

    public function contact(Request $request): JsonResponse
    {
        $request->merge([
            'license_plate' => Vehicle::normalizeLicensePlate((string) $request->input('license_plate', '')),
        ]);

        $data = $request->validate([
            'license_plate' => ['required', 'string', 'min:2', 'max:16'],
            'message_template_id' => [
                'required',
                'integer',
                'exists:message_templates,id,type,contact,is_active,1',
            ],
        ]);

        $vehicle = Vehicle::where('license_plate', $data['license_plate'])->firstOrFail();
        abort_if($vehicle->user_id === $request->user()->id, 422, 'You cannot contact your own vehicle.');

        $conversation = DB::transaction(function () use ($request, $vehicle, $data): Conversation {
            $conversation = Conversation::firstOrCreate(
                [
                    'vehicle_id' => $vehicle->id,
                    'started_by_user_id' => $request->user()->id,
                    'status' => 'open',
                ],
            );

            $template = MessageTemplate::findOrFail($data['message_template_id']);
            $conversation->messages()->create([
                'sender_user_id' => $request->user()->id,
                'message_template_id' => $template->id,
                'body_snapshot' => $template->body,
            ]);

            return $conversation;
        });

        return response()->json($conversation->load('messages'), 201);
    }

    private function ensureParticipant(Request $request, Conversation $conversation): void
    {
        $userId = $request->user()->id;
        $isInitiator = $conversation->started_by_user_id === $userId;
        $isVehicleOwner = $conversation->vehicle()->where('user_id', $userId)->exists();

        abort_unless($isInitiator || $isVehicleOwner, 404);
    }
}
