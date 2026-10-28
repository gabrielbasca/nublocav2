<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_a_device_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'fcm-device-token-abc123']);

        $response->assertOk()
            ->assertJsonPath('token', 'fcm-device-token-abc123')
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'token' => 'fcm-device-token-abc123',
        ]);
    }

    public function test_registering_an_existing_token_reassigns_it_to_the_current_user(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'shared-token'])
            ->assertOk();

        // Same token registered by another user updates ownership (updateOrCreate).
        $this->actingAs($second, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'shared-token'])
            ->assertOk()
            ->assertJsonPath('user_id', $second->id);

        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertSame($second->id, DeviceToken::where('token', 'shared-token')->value('user_id'));
    }

    public function test_device_token_registration_requires_a_valid_token_and_auth(): void
    {
        $user = User::factory()->create();

        // Unauthenticated.
        $this->postJson('/api/device-tokens', ['token' => 'x'])->assertUnauthorized();

        // Missing token.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/device-tokens', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_authenticated_user_can_remove_their_own_device_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        DeviceToken::create(['user_id' => $user->id, 'token' => 'my-token']);
        DeviceToken::create(['user_id' => $other->id, 'token' => 'other-token']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/device-tokens', ['token' => 'my-token'])
            ->assertNoContent();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'my-token']);
        // Another user's token is untouched.
        $this->assertDatabaseHas('device_tokens', ['token' => 'other-token']);
    }
}
