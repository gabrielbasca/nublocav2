<?php

namespace App\Jobs;

use App\Services\FirebaseNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPushNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 86400;

    public array $backoff = [10, 30, 60, 120];

    /**
     * @param  array<string, string>  $payload
     */
    public function __construct(
        public readonly int $deviceTokenId,
        public readonly int $recipientUserId,
        public readonly ?int $senderUserId,
        public readonly string $notificationId,
        public readonly array $payload,
    ) {}

    public function uniqueId(): string
    {
        return $this->notificationId.':device:'.$this->deviceTokenId;
    }

    public function handle(FirebaseNotificationService $notifications): void
    {
        $notifications->sendToDevice(
            deviceTokenId: $this->deviceTokenId,
            recipientUserId: $this->recipientUserId,
            senderUserId: $this->senderUserId,
            notificationId: $this->notificationId,
            payload: $this->payload,
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('firebase.push_delivery_job_failed', [
            'notification_id' => $this->notificationId,
            'recipient_user_id' => $this->recipientUserId,
            'device_token_id' => $this->deviceTokenId,
            'exception_class' => $exception::class,
        ]);
    }
}
