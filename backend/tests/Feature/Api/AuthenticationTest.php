<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_use_the_issued_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test Driver',
            'email' => 'driver@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonMissingPath('user.password');

        $this->withToken($response->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'driver@example.com');
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create(['email' => 'driver@example.com']);

        $this->postJson('/api/login', [
            'email' => 'driver@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_a_user_can_log_in_and_revoke_their_token(): void
    {
        User::factory()->create([
            'email' => 'driver@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'driver@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user']);

        $token = $response->json('token');
        $tokenId = explode('|', $token, 2)[0];

        $this->withToken($token)->postJson('/api/logout')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }
}
