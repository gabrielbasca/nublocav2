<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationMessageController extends Controller
{
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = $request->user()->id;
        $isInitiator = $conversation->started_by_user_id === $userId;
        $isVehicleOwner = $conversation->vehicle()->where('user_id', $userId)->exists();

        abort_unless($isInitiator || $isVehicleOwner, 404);
        abort_unless($conversation->status === 'open', 409, 'This conversation is closed.');

        $expectedType = $isInitiator ? 'contact' : 'reply';
        $data = $request->validate([
            'message_template_id' => [
                'required',
                'integer',
                'exists:message_templates,id,type,'.$expectedType.',is_active,1',
            ],
        ]);

        $template = MessageTemplate::findOrFail($data['message_template_id']);
        $message = $conversation->messages()->create([
            'sender_user_id' => $userId,
            'message_template_id' => $template->id,
            'body_snapshot' => $template->body,
        ]);

        return response()->json($message, 201);
    }
}
