<?php

namespace App\Services;

use App\Jobs\SendPushNotification;
use App\Models\ConversationMessage;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\InvalidArgumentException as FirebaseInvalidArgumentException;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\SenderIdMismatch;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification;

class FirebaseNotificationService
{
    public function __construct(
        private readonly Messaging $messaging,
    ) {}

    public function queueConversationMessage(
        ConversationMessage $message,
        User $recipient,
        bool $isContactRequest = false,
    ): void {
        $type = $isContactRequest ? 'contact_request' : 'message_received';
        $eventId = "conversation-message:{$message->id}";

        $this->queueToUser(
            recipient: $recipient,
            senderUserId: $message->sender_user_id,
            eventId: $eventId,
            payload: [
                'type' => $type,
                'conversation_id' => (string) $message->conversation_id,
                'title' => $isContactRequest ? 'New vehicle contact request' : 'New message',
                'body' => $message->body_snapshot,
                'route' => 'conversation',
                'navigation_data' => ['conversation_id' => (string) $message->conversation_id],
            ],
        );
    }

    /**
     * Queue an important account notification using a stable event ID for deduplication.
     *
     * @param  array<string, string>  $navigationData
     */
    public function queueAccountEvent(
        User $recipient,
        string $eventId,
        string $title,
        string $body,
        array $navigationData = [],
    ): void {
        if (trim($eventId) === '' || trim($title) === '' || trim($body) === '') {
            throw new \InvalidArgumentException('Account notifications require an event ID, title, and body.');
        }

        $this->queueToUser(
            recipient: $recipient,
            senderUserId: null,
            eventId: "account-event:{$eventId}",
            payload: [
                'type' => 'account_event',
                'title' => $title,
                'body' => $body,
                'route' => $navigationData['route'] ?? 'account',
                'navigation_data' => $navigationData,
            ],
        );
    }

    /**
     * @param array{
     *     type: string,
     *     title: string,
     *     body: string,
     *     route: string,
     *     conversation_id?: string,
     *     navigation_data: array<string, string>
     * } $payload
     */
    private function queueToUser(User $recipient, ?int $senderUserId, string $eventId, array $payload): void
    {
        $notificationId = hash('sha256', $eventId.':recipient:'.$recipient->id);

        $tokenIds = DeviceToken::query()
            ->where('user_id', $recipient->id)
            ->when($senderUserId !== null, fn ($query) => $query->where('user_id', '!=', $senderUserId))
            ->pluck('id');

        foreach ($tokenIds as $tokenId) {
            SendPushNotification::dispatch(
                deviceTokenId: (int) $tokenId,
                recipientUserId: (int) $recipient->id,
                senderUserId: $senderUserId,
                notificationId: $notificationId,
                payload: $this->serializePayload($payload, $notificationId),
            )->afterCommit();
        }
    }

    /**
     * @param array{
     *     type: string,
     *     title: string,
     *     body: string,
     *     route: string,
     *     conversation_id?: string,
     *     navigation_data: array<string, string>
     * } $payload
     * @return array<string, string>
     */
    private function serializePayload(array $payload, string $notificationId): array
    {
        $data = [
            'type' => $payload['type'],
            'title' => $payload['title'],
            'body' => $payload['body'],
            'route' => $payload['route'],
            'notification_id' => $notificationId,
            'navigation_data' => json_encode($payload['navigation_data'], JSON_THROW_ON_ERROR),
        ];

        if (isset($payload['conversation_id'])) {
            $data['conversation_id'] = $payload['conversation_id'];
        }

        return $data;
    }

    public function sendToDevice(
        int $deviceTokenId,
        int $recipientUserId,
        ?int $senderUserId,
        string $notificationId,
        array $payload,
    ): void {
        $deviceToken = DeviceToken::query()
            ->whereKey($deviceTokenId)
            ->where('user_id', $recipientUserId)
            ->when($senderUserId !== null, fn ($query) => $query->where('user_id', '!=', $senderUserId))
            ->first();

        if (! $deviceToken) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($payload['title'], $payload['body']))
            ->withData($payload)
            ->withAndroidConfig(['priority' => 'high'])
            ->withToken($deviceToken->token);

        try {
            $report = $this->messaging->sendMulticast($message, [$deviceToken->token]);
        } catch (FirebaseException|FirebaseInvalidArgumentException $exception) {
            Log::warning('firebase.push_delivery_request_failed', [
                'notification_id' => $notificationId,
                'recipient_user_id' => $recipientUserId,
                'device_token_id' => $deviceToken->id,
                'device_token_hash' => hash('sha256', $deviceToken->token),
                'exception_class' => $exception::class,
            ]);

            throw new \RuntimeException('Firebase push delivery request failed.');
        }

        $this->handleReport($report, $deviceToken, $recipientUserId, $notificationId);
    }

    private function handleReport(
        MulticastSendReport $report,
        DeviceToken $deviceToken,
        int $recipientUserId,
        string $notificationId,
    ): void {
        foreach ($report->failures()->getItems() as $failure) {
            $error = $failure->error();

            if ($failure->messageTargetWasInvalid() || $error instanceof NotFound || $error instanceof SenderIdMismatch) {
                $reason = match (true) {
                    $error instanceof NotFound => 'unregistered_token',
                    $error instanceof SenderIdMismatch => 'sender_id_mismatch',
                    default => 'invalid_token',
                };

                $deviceToken->delete();

                Log::notice('firebase.device_token_removed', [
                    'reason' => $reason,
                    'notification_id' => $notificationId,
                    'recipient_user_id' => $recipientUserId,
                    'device_token_id' => $deviceToken->id,
                    'device_token_hash' => hash('sha256', $deviceToken->token),
                ]);

                continue;
            }

            if ($error instanceof InvalidMessage) {
                Log::error('firebase.push_payload_rejected', [
                    'notification_id' => $notificationId,
                    'recipient_user_id' => $recipientUserId,
                    'device_token_id' => $deviceToken->id,
                    'exception_class' => $error::class,
                ]);

                continue;
            }

            Log::warning('firebase.push_delivery_failed', [
                'notification_id' => $notificationId,
                'recipient_user_id' => $recipientUserId,
                'device_token_id' => $deviceToken->id,
                'device_token_hash' => hash('sha256', $deviceToken->token),
                'exception_class' => $error ? $error::class : null,
            ]);

            throw new \RuntimeException('Firebase push delivery failed and will be retried.');
        }
    }
}
