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

    public function test_vehicle_update_preserves_public_url_and_rotating_the_token_changes_it(): void
    {
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'CJ 01 ABC']);
        $originalToken = $vehicle->public_token;

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/vehicles/{$vehicle->id}", ['license_plate' => 'bv 02 xyz'])
            ->assertOk()
            ->assertJsonPath('license_plate', 'BV02XYZ')
            ->assertJsonPath('public_token', $originalToken)
            ->assertJsonPath('public_url', "https://nubloca.clarfiscal.ro/v/{$originalToken}");

        $rotated = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/vehicles/{$vehicle->id}/rotate-public-token")
            ->assertOk()
            ->json('public_token');

        $this->assertNotSame($originalToken, $rotated);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rotated);
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'public_token' => $rotated,
        ]);
    }

    public function test_vehicle_update_and_token_rotation_are_owner_only(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'CJ 01 ABC']);

        $this->actingAs($otherUser, 'sanctum')
            ->putJson("/api/vehicles/{$vehicle->id}", ['license_plate' => 'BV 02 XYZ'])
            ->assertNotFound();

        $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/vehicles/{$vehicle->id}/rotate-public-token")
            ->assertNotFound();
    }

    public function test_vehicle_update_validates_normalized_plate_uniqueness(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'CJ 01 ABC']);
        $otherOwner->vehicles()->create(['license_plate' => 'BV 02 XYZ']);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/vehicles/{$vehicle->id}", ['license_plate' => '---'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('license_plate');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/vehicles/{$vehicle->id}", ['license_plate' => 'bv02 xyz'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('license_plate');
    }

    public function test_public_vehicle_page_hides_owner_identity_and_accepts_only_contact_templates(): void
    {
        $owner = User::factory()->create([
            'name' => 'Private Owner Name',
            'email' => 'private-owner@example.com',
        ]);
        $vehicle = $owner->vehicles()->create(['license_plate' => 'CJ 01 ABC']);
        $contact = MessageTemplate::create([
            'type' => 'contact',
            'body' => 'Please move your vehicle.',
            'is_active' => true,
        ]);
        $reply = MessageTemplate::create([
            'type' => 'reply',
            'body' => 'Thanks.',
            'is_active' => true,
        ]);

        $this->get("/v/{$vehicle->public_token}")
            ->assertOk()
            ->assertSee('Please move your vehicle.')
            ->assertDontSee('Private Owner Name')
            ->assertDontSee('private-owner@example.com')
            ->assertDontSee($vehicle->license_plate);
        $submissionToken = session()->get('public_vehicle_contact:'.$vehicle->public_token);

        $this->from("/v/{$vehicle->public_token}")
            ->post("/v/{$vehicle->public_token}", [
                'message_template_id' => $reply->id,
                'submission_token' => $submissionToken,
            ])
            ->assertSessionHasErrors('message_template_id');

        $this->post('/v/'.str_repeat('0', 64), [
            'message_template_id' => $contact->id,
            'submission_token' => str_repeat('a', 64),
        ])->assertNotFound();

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_public_contact_creates_guest_conversation_and_message(): void
    {
        $owner = User::factory()->create();
        $vehicle = $owner->vehicles()->create(['license_plate' => 'CJ 01 ABC']);
        $template = MessageTemplate::create([
            'type' => 'contact',
            'body' => 'Please move your vehicle.',
            'is_active' => true,
        ]);

        $this->get("/v/{$vehicle->public_token}")->assertOk();
        $submissionToken = session()->get('public_vehicle_contact:'.$vehicle->public_token);

        $response = $this->post("/v/{$vehicle->public_token}", [
            'message_template_id' => $template->id,
            'submission_token' => $submissionToken,
        ]);

        $response->assertRedirect(route('vehicles.public.show', $vehicle->public_token));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('conversations', [
            'vehicle_id' => $vehicle->id,
            'started_by_user_id' => null,
        ]);
        $this->assertDatabaseHas('conversation_messages', [
            'sender_user_id' => null,
            'message_template_id' => $template->id,
            'body_snapshot' => $template->body,
        ]);

        $this->post("/v/{$vehicle->public_token}", [
            'message_template_id' => $template->id,
            'submission_token' => $submissionToken,
        ])->assertRedirect(route('vehicles.public.show', $vehicle->public_token));
        $this->assertDatabaseCount('conversations', 1);
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
