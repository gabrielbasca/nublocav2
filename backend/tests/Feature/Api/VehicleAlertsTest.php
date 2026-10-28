<?php

namespace Tests\Feature\Api;

use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\VehicleAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

class VehicleAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class));
    }

    private function validToken(): string
    {
        return str_repeat('a', 64);
    }

    public function test_public_show_returns_only_safe_information_and_reasons(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $vehicle = $owner->vehicles()->create(['license_plate' => 'CJ 01 ABC']);

        $response = $this->getJson("/api/public/vehicles/{$vehicle->public_token}");

        $response->assertOk()
            ->assertJsonPath('vehicle.public_token', $vehicle->public_token)
            ->assertJsonPath('alert_reasons', VehicleAlert::reasons());

        // Privacy: no plate, owner identity, user id, or contact details.
        $this->assertStringNotContainsString('CJ01ABC', $response->getContent());
        $this->assertStringNotContainsString('owner@example.com', $response->getContent());
        $this->assertArrayNotHasKey('license_plate', $response->json('vehicle'));
        $this->assertArrayNotHasKey('user_id', $response->json('vehicle'));
        $this->assertArrayNotHasKey('id', $response->json('vehicle'));
    }

    public function test_public_show_rejects_invalid_and_unknown_tokens(): void
    {
        // Wrong shape is rejected by the route constraint.
        $this->getJson('/api/public/vehicles/not-a-token')->assertNotFound();

        // Well-formed but unknown token.
        $this->getJson('/api/public/vehicles/'.$this->validToken())->assertNotFound();
    }

    public function test_anonymous_alert_is_persisted_and_dispatches_notification(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'BV 02 XYZ']);
        DeviceToken::create(['user_id' => $owner->id, 'token' => 'owner-device-token']);

        $response = $this->postJson("/api/public/vehicles/{$vehicle->public_token}/alerts", [
            'reason' => 'blocking_access',
            'scanner_token' => 'scanner-token-1234567890',
        ]);

        $response->assertCreated()->assertJsonPath('message', 'Your alert has been sent.');

        $this->assertDatabaseCount('vehicle_alerts', 1);
        $alert = VehicleAlert::first();
        $this->assertSame($vehicle->id, $alert->vehicle_id);
        $this->assertSame('blocking_access', $alert->reason);
        $this->assertSame(VehicleAlert::STATUS_NEW, $alert->status);
        $this->assertNotNull($alert->reporter_ip_hash);
        $this->assertNotNull($alert->reporter_token_hash);
        // Raw scanner token / IP must never be stored.
        $this->assertStringNotContainsString('scanner-token-1234567890', $alert->reporter_token_hash);

        Queue::assertPushed(SendPushNotification::class, 1);
        Queue::assertPushed(SendPushNotification::class, function (SendPushNotification $job) use ($owner, $alert): bool {
            return $job->recipientUserId === $owner->id
                && $job->payload['type'] === 'account_event'
                && $job->payload['navigation_data'] === json_encode([
                    'route' => 'vehicle_alerts',
                    'vehicle_id' => (string) $alert->vehicle_id,
                    'alert_id' => (string) $alert->id,
                ]);
        });
    }

    public function test_alert_reason_is_validated(): void
    {
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'IS 03 DEF']);

        $this->postJson("/api/public/vehicles/{$vehicle->public_token}/alerts", ['reason' => 'not_a_reason'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/public/vehicles/{$vehicle->public_token}/alerts", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseCount('vehicle_alerts', 0);
    }

    public function test_duplicate_alert_within_cooldown_is_not_duplicated(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'CJ 04 GHI']);
        DeviceToken::create(['user_id' => $owner->id, 'token' => 'owner-device-token']);

        $payload = ['reason' => 'lights_on', 'scanner_token' => 'scanner-token-abcdefghij'];

        $this->postJson("/api/public/vehicles/{$vehicle->public_token}/alerts", $payload)->assertCreated();
        $this->postJson("/api/public/vehicles/{$vehicle->public_token}/alerts", $payload)->assertCreated();

        // Second identical alert is accepted (generic response) but not stored.
        $this->assertDatabaseCount('vehicle_alerts', 1);
        Queue::assertPushed(SendPushNotification::class, 1);
    }

    public function test_honeypot_submission_is_accepted_but_not_stored(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'B 05 JKL']);

        $this->postJson("/api/public/vehicles/{$vehicle->public_token}/alerts", [
            'reason' => 'other',
            'company' => 'spammy-bot',
        ])->assertCreated();

        $this->assertDatabaseCount('vehicle_alerts', 0);
        Queue::assertNothingPushed();
    }

    public function test_alert_rate_limit_returns_429(): void
    {
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'TM 06 MNO']);

        // Exhaust the per-IP route limit (5 per minute).
        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/public/vehicles/{$vehicle->public_token}/alerts", [
                'reason' => 'other',
                'scanner_token' => "scanner-token-{$i}-abcdefghij",
            ]);
        }

        $this->postJson("/api/public/vehicles/{$vehicle->public_token}/alerts", [
            'reason' => 'other',
            'scanner_token' => 'scanner-token-overflow-abcdefghij',
        ])->assertStatus(429);
    }

    public function test_owner_can_list_and_mark_alerts_read(): void
    {
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'AR 07 PQR']);
        $alert = $vehicle->alerts()->create(['reason' => 'window_open']);

        $list = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/vehicles/{$vehicle->id}/alerts")
            ->assertOk();

        $this->assertCount(1, $list->json());
        // Owner list must not leak reporter metadata.
        $this->assertArrayNotHasKey('reporter_ip_hash', $list->json('0'));
        $this->assertArrayNotHasKey('reporter_token_hash', $list->json('0'));

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/vehicles/{$vehicle->id}/alerts/{$alert->id}/read")
            ->assertOk()
            ->assertJsonPath('status', VehicleAlert::STATUS_READ);

        $this->assertNotNull($alert->refresh()->read_at);
    }

    public function test_owner_cannot_access_another_users_alerts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'GL 08 STU']);
        $alert = $vehicle->alerts()->create(['reason' => 'other']);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/vehicles/{$vehicle->id}/alerts")
            ->assertNotFound();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/vehicles/{$vehicle->id}/alerts/{$alert->id}/read")
            ->assertNotFound();
    }

    public function test_rotating_token_invalidates_old_public_endpoint(): void
    {
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'NT 09 VWX']);
        $oldToken = $vehicle->public_token;

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/vehicles/{$vehicle->id}/rotate-public-token")
            ->assertOk();

        $newToken = $vehicle->refresh()->public_token;
        $this->assertNotSame($oldToken, $newToken);

        // Old token no longer resolves; new one does.
        $this->getJson("/api/public/vehicles/{$oldToken}")->assertNotFound();
        $this->getJson("/api/public/vehicles/{$newToken}")
            ->assertOk()
            ->assertJsonPath('vehicle.public_token', $newToken);
    }
}
