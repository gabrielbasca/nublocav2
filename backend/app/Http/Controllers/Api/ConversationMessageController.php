<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MessageTemplate;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationMessageController extends Controller
{
    public function store(
        Request $request,
        Conversation $conversation,
        FirebaseNotificationService $notifications,
    ): JsonResponse {
        $userId = $request->user()->id;
        $isInitiator = $conversation->started_by_user_id === $userId;
        $isVehicleOwner = $conversation->vehicle()->where('user_id', $userId)->exists();

        abort_unless($isInitiator || $isVehicleOwner, 404);
        abort_unless($conversation->status === 'open', 409, 'This conversation is closed.');
        abort_if(
            $conversation->started_by_user_id === null,
            409,
            'Public contact requests cannot receive replies in this API.',
        );

        $expectedType = $isInitiator ? 'contact' : 'reply';
        $data = $request->validate([
            'message_template_id' => [
                'required',
                'integer',
                'exists:message_templates,id,type,'.$expectedType.',is_active,1',
            ],
        ]);

        $message = DB::transaction(function () use ($conversation, $data, $userId, $notifications, $isInitiator): ConversationMessage {
            $template = MessageTemplate::findOrFail($data['message_template_id']);
            $message = $conversation->messages()->create([
                'sender_user_id' => $userId,
                'message_template_id' => $template->id,
                'body_snapshot' => $template->body,
            ]);

            $recipient = $isInitiator
                ? $conversation->vehicle->user
                : $conversation->startedBy;

            $notifications->queueConversationMessage($message, $recipient);

            return $message;
        });

        return response()->json($message, 201);
    }
}
