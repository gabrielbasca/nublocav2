<?php

namespace Tests\Feature\Api;

use App\Jobs\SendPushNotification;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\FirebaseNotificationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FirebaseNotificationsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class));
    }

    public function test_a_contact_request_queues_one_after_commit_notification_for_the_vehicle_owner(): void
    {
        $owner = User::factory()->create();
        $sender = User::factory()->create();
        $owner->vehicles()->create(['license_plate' => 'CJ 01 ABC']);
        $ownerToken = DeviceToken::create(['user_id' => $owner->id, 'token' => 'owner-device-token']);
        DeviceToken::create(['user_id' => $sender->id, 'token' => 'sender-device-token']);
        $template = MessageTemplate::create([
            'type' => 'contact',
            'body' => 'Va rog sa mutati vehiculul.',
            'is_active' => true,
        ]);

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/contact', [
                'license_plate' => 'CJ 01 ABC',
                'message_template_id' => $template->id,
            ])
            ->assertCreated();

        Queue::assertPushed(SendPushNotification::class, 1);
        Queue::assertPushed(SendPushNotification::class, function (SendPushNotification $job) use ($owner, $sender, $ownerToken, $template): bool {
            return $job->deviceTokenId === $ownerToken->id
                && $job->recipientUserId === $owner->id
                && $job->senderUserId === $sender->id
                && $job->afterCommit
                && $job->payload['type'] === 'contact_request'
                && $job->payload['body'] === $template->body
                && isset($job->payload['conversation_id'], $job->payload['title'], $job->payload['route'], $job->payload['notification_id']);
        });
    }

    public function test_a_received_message_notifies_the_other_participant_and_not_the_sender_device(): void
    {
        $owner = User::factory()->create();
        $starter = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'BV 01 ABC']);
        $conversation = Conversation::create([
            'vehicle_id' => $vehicle->id,
            'started_by_user_id' => $starter->id,
            'status' => 'open',
        ]);
        $reply = MessageTemplate::create([
            'type' => 'reply',
            'body' => 'Multumesc, ma ocup imediat.',
            'is_active' => true,
        ]);
        $starterToken = DeviceToken::create(['user_id' => $starter->id, 'token' => 'starter-device-token']);
        DeviceToken::create(['user_id' => $owner->id, 'token' => 'owner-device-token']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'message_template_id' => $reply->id,
            ])
            ->assertCreated();

        Queue::assertPushed(SendPushNotification::class, 1);
        Queue::assertPushed(SendPushNotification::class, function (SendPushNotification $job) use ($starter, $owner, $starterToken, $reply, $conversation): bool {
            return $job->deviceTokenId === $starterToken->id
                && $job->recipientUserId === $starter->id
                && $job->senderUserId === $owner->id
                && $job->payload['type'] === 'message_received'
                && $job->payload['conversation_id'] === (string) $conversation->id
                && $job->payload['body'] === $reply->body;
        });
    }

    public function test_account_events_use_a_stable_id_and_navigation_payload(): void
    {
        $user = User::factory()->create();
        $token = DeviceToken::create(['user_id' => $user->id, 'token' => 'account-device-token']);
        $eventId = 'email-verified:'.$user->id;

        $notifications = app(FirebaseNotificationService::class);
        $notifications->queueAccountEvent(
            recipient: $user,
            eventId: $eventId,
            title: 'Email verified',
            body: 'Your email address is verified.',
            navigationData: ['route' => 'account/security', 'tab' => 'email'],
        );
        $notifications->queueAccountEvent(
            recipient: $user,
            eventId: $eventId,
            title: 'Email verified',
            body: 'Your email address is verified.',
            navigationData: ['route' => 'account/security', 'tab' => 'email'],
        );

        Queue::assertPushed(SendPushNotification::class, 1);
        Queue::assertPushed(SendPushNotification::class, function (SendPushNotification $job) use ($user, $token, $eventId): bool {
            $navigation = json_decode($job->payload['navigation_data'], true);

            return $job->deviceTokenId === $token->id
                && $job->afterCommit
                && $job->recipientUserId === $user->id
                && $job->payload['type'] === 'account_event'
                && $job->payload['route'] === 'account/security'
                && $navigation === ['route' => 'account/security', 'tab' => 'email']
                && $job->notificationId === hash(
                    'sha256',
                    'account-event:'.$eventId.':recipient:'.$user->id,
                );
        });
    }

    public function test_the_job_sends_an_android_notification_with_the_expected_fcm_data(): void
    {
        $user = User::factory()->create();
        $deviceToken = DeviceToken::create(['user_id' => $user->id, 'token' => 'mock-only-fcm-token']);
        $notificationId = str_repeat('a', 64);
        $payload = [
            'type' => 'message_received',
            'conversation_id' => '42',
            'title' => 'New message',
            'body' => 'Please move your vehicle.',
            'route' => 'conversation',
            'notification_id' => $notificationId,
            'navigation_data' => '{"conversation_id":"42"}',
        ];
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message, array $tokens) use ($deviceToken, $payload): bool {
                $serialized = $message->jsonSerialize();

                return $tokens === [$deviceToken->token]
                    && $serialized['data'] === $payload
                    && $serialized['notification'] === ['title' => 'New message', 'body' => 'Please move your vehicle.']
                    && $serialized['android']['priority'] === 'high';
            })
            ->andReturn(MulticastSendReport::withItems([
                SendReport::success(
                    MessageTarget::with('token', $deviceToken->token),
                    ['name' => 'projects/nubloca-app/messages/mock-message-id'],
                ),
            ]));

        $this->app->instance(Messaging::class, $messaging);

        $job = new SendPushNotification(
            deviceTokenId: $deviceToken->id,
            recipientUserId: $user->id,
            senderUserId: null,
            notificationId: $notificationId,
            payload: $payload,
        );

        $job->handle(app(FirebaseNotificationService::class));
    }

    public function test_unregistered_tokens_are_removed_without_logging_the_token(): void
    {
        $user = User::factory()->create();
        $rawToken = 'mock-only-expired-token';
        $deviceToken = DeviceToken::create(['user_id' => $user->id, 'token' => $rawToken]);
        $notificationId = str_repeat('b', 64);
        $report = MulticastSendReport::withItems([
            SendReport::failure(
                MessageTarget::with('token', $rawToken),
                NotFound::becauseTokenNotFound($rawToken),
            ),
        ]);
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturn($report);
        $this->app->instance(Messaging::class, $messaging);
        Log::spy();

        (new SendPushNotification(
            deviceTokenId: $deviceToken->id,
            recipientUserId: $user->id,
            senderUserId: null,
            notificationId: $notificationId,
            payload: [
                'type' => 'account_event',
                'title' => 'Account update',
                'body' => 'Review your account.',
                'route' => 'account',
                'navigation_data' => '{}',
                'notification_id' => $notificationId,
            ],
        ))->handle(app(FirebaseNotificationService::class));

        $this->assertDatabaseMissing('device_tokens', ['id' => $deviceToken->id]);
        Log::shouldHaveReceived('notice')
            ->with('firebase.device_token_removed', Mockery::on(
                fn (array $context): bool => ! str_contains(json_encode($context), $rawToken)
                    && $context['device_token_hash'] === hash('sha256', $rawToken),
            ))
            ->once();
    }

    public function test_invalid_tokens_are_removed(): void
    {
        $user = User::factory()->create();
        $rawToken = 'mock-only-invalid-token';
        $deviceToken = DeviceToken::create(['user_id' => $user->id, 'token' => $rawToken]);
        $notificationId = str_repeat('d', 64);
        $report = MulticastSendReport::withItems([
            SendReport::failure(
                MessageTarget::with('token', $rawToken),
                new InvalidArgument('invalid registration token'),
            ),
        ]);
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturn($report);
        $this->app->instance(Messaging::class, $messaging);

        (new SendPushNotification(
            deviceTokenId: $deviceToken->id,
            recipientUserId: $user->id,
            senderUserId: null,
            notificationId: $notificationId,
            payload: [
                'type' => 'account_event',
                'title' => 'Account update',
                'body' => 'Review your account.',
                'route' => 'account',
                'navigation_data' => '{}',
                'notification_id' => $notificationId,
            ],
        ))->handle(app(FirebaseNotificationService::class));

        $this->assertDatabaseMissing('device_tokens', ['id' => $deviceToken->id]);
    }

    public function test_temporary_firebase_errors_are_sanitized_and_retried_by_the_queue(): void
    {
        $user = User::factory()->create();
        $deviceToken = DeviceToken::create(['user_id' => $user->id, 'token' => 'mock-only-retry-token']);
        $notificationId = str_repeat('c', 64);
        $report = MulticastSendReport::withItems([
            SendReport::failure(
                MessageTarget::with('token', $deviceToken->token),
                new ServerUnavailable('temporary FCM failure'),
            ),
        ]);
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturn($report);
        $this->app->instance(Messaging::class, $messaging);

        $job = new SendPushNotification(
            deviceTokenId: $deviceToken->id,
            recipientUserId: $user->id,
            senderUserId: null,
            notificationId: $notificationId,
            payload: [
                'type' => 'account_event',
                'title' => 'Account update',
                'body' => 'Review your account.',
                'route' => 'account',
                'navigation_data' => '{}',
                'notification_id' => $notificationId,
            ],
        );

        try {
            $job->handle(app(FirebaseNotificationService::class));
            $this->fail('A temporary FCM error should be retried by the worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Firebase push delivery failed and will be retried.', $exception->getMessage());
        }

        $this->assertSame(5, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([10, 30, 60, 120], $job->backoff);
    }

    public function test_firebase_request_exceptions_are_logged_without_token_or_provider_message(): void
    {
        $user = User::factory()->create();
        $rawToken = 'mock-only-fcm-request-token';
        $deviceToken = DeviceToken::create(['user_id' => $user->id, 'token' => $rawToken]);
        $notificationId = str_repeat('e', 64);
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')
            ->once()
            ->andThrow(new ServerUnavailable("provider response included {$rawToken}"));
        $this->app->instance(Messaging::class, $messaging);
        Log::spy();

        $job = new SendPushNotification(
            deviceTokenId: $deviceToken->id,
            recipientUserId: $user->id,
            senderUserId: null,
            notificationId: $notificationId,
            payload: [
                'type' => 'account_event',
                'title' => 'Account update',
                'body' => 'Review your account.',
                'route' => 'account',
                'navigation_data' => '{}',
                'notification_id' => $notificationId,
            ],
        );

        try {
            $job->handle(app(FirebaseNotificationService::class));
            $this->fail('A Firebase request exception should be retried by the worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Firebase push delivery request failed.', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->with('firebase.push_delivery_request_failed', Mockery::on(
                fn (array $context): bool => ! str_contains(json_encode($context), $rawToken)
                    && $context['device_token_hash'] === hash('sha256', $rawToken)
                    && $context['exception_class'] === ServerUnavailable::class,
            ))
            ->once();
    }
}
