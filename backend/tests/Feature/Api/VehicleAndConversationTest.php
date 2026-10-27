<?php

namespace Tests\Feature\Api;

use App\Models\MessageTemplate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

class VehicleAndConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class));
    }

    public function test_starter_templates_can_be_seeded_repeatedly(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('message_templates', 5);
    }

    public function test_vehicle_plates_are_normalized_and_unique(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vehicles', ['license_plate' => 'b 123-ab'])
            ->assertCreated()
            ->assertJsonPath('license_plate', 'B123AB');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/vehicles', ['license_plate' => 'B-123 AB'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('license_plate');
    }

    public function test_a_contact_message_must_use_a_predefined_template(): void
    {
        $owner = User::factory()->create();
        $sender = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'CJ 01 ABC']);
        $template = MessageTemplate::create([
            'type' => 'contact',
            'body' => 'Va rog sa mutati vehiculul.',
            'is_active' => true,
        ]);

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/contact', [
                'license_plate' => 'cj-01 abc',
                'message_template_id' => $template->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('messages.0.body_snapshot', $template->body);

        $this->assertDatabaseHas('conversations', [
            'vehicle_id' => $vehicle->id,
            'started_by_user_id' => $sender->id,
        ]);
    }

    public function test_only_the_vehicle_owner_can_send_a_predefined_reply(): void
    {
        $owner = User::factory()->create();
        $sender = User::factory()->create();
        $outsider = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'BV 01 ABC']);
        $contactTemplate = MessageTemplate::create([
            'type' => 'contact',
            'body' => 'Va rog sa mutati vehiculul.',
            'is_active' => true,
        ]);
        $replyTemplate = MessageTemplate::create([
            'type' => 'reply',
            'body' => 'Multumesc, ma ocup imediat.',
            'is_active' => true,
        ]);

        $conversation = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/contact', [
                'license_plate' => $vehicle->license_plate,
                'message_template_id' => $contactTemplate->id,
            ])
            ->assertCreated()
            ->json('id');

        $this->actingAs($sender, 'sanctum')
            ->postJson("/api/conversations/{$conversation}/messages", [
                'message_template_id' => $replyTemplate->id,
            ])
            ->assertUnprocessable();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/conversations/{$conversation}")
            ->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/conversations/{$conversation}/messages", [
                'message_template_id' => $replyTemplate->id,
            ])
            ->assertCreated()
            ->assertJsonPath('body_snapshot', $replyTemplate->body);
    }

    public function test_contact_requests_are_rate_limited(): void
    {
        $owner = User::factory()->create();
        $sender = User::factory()->create();
        $owner->vehicles()->create(['license_plate' => 'IS 01 ABC']);
        $template = MessageTemplate::create([
            'type' => 'contact',
            'body' => 'Va rog sa mutati vehiculul.',
            'is_active' => true,
        ]);

        $this->actingAs($sender, 'sanctum');

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/contact', [
                'license_plate' => 'IS 01 ABC',
                'message_template_id' => $template->id,
            ])->assertCreated();
        }

        $this->postJson('/api/contact', [
            'license_plate' => 'IS 01 ABC',
            'message_template_id' => $template->id,
        ])->assertTooManyRequests();
    }
}
