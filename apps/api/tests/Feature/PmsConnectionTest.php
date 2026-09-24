<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\PmsConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PmsConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_administrators_can_read_provider_catalog_or_connections(): void
    {
        $hotel = Hotel::factory()->create();
        $manager = User::factory()->create();
        $hotel->users()->attach($manager, ['role' => 'hotel_manager']);

        $this->getJson('/api/v1/pms/providers')->assertUnauthorized();
        $this->actingAs($manager)->getJson('/api/v1/pms/providers')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/hotels/'.$hotel->id.'/pms-connections')->assertForbidden();
        $this->actingAs($manager)->postJson('/api/v1/hotels/'.$hotel->id.'/pms-connections', $this->payload())->assertForbidden();
    }

    public function test_administrator_can_create_a_disabled_pms_connection_without_exposing_credentials(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/hotels/'.$hotel->id.'/pms-connections', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.provider', 'surge')
            ->assertJsonPath('data.inventory_mode', 'pms')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.credentials_configured', true)
            ->assertJsonPath('data.capabilities.hold_confirm', false)
            ->assertJsonMissingPath('data.credentials')
            ->assertSee('credentials_configured');
        $this->assertStringNotContainsString('super-secret-token', $response->getContent());
        $connection = PmsConnection::firstOrFail();
        $this->assertSame(['api_key' => 'super-secret-token'], $connection->credentials);
        $this->assertStringNotContainsString('super-secret-token', (string) DB::table('pms_connections')->value('credentials'));
        $this->assertDatabaseHas('hotel_access_events', ['hotel_id' => $hotel->id, 'actor_id' => $admin->id, 'action' => 'pms.connection.created']);
    }

    public function test_connection_listing_is_hotel_scoped_even_for_administrators(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $first = Hotel::factory()->create();
        $second = Hotel::factory()->create();
        PmsConnection::create([
            ...$this->payload(),
            'hotel_id' => $second->id,
            'created_by' => $admin->id,
            'capabilities' => ['hold_confirm' => false],
        ]);

        $this->actingAs($admin)->getJson('/api/v1/hotels/'.$first->id.'/pms-connections')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/v1/hotels/'.$second->id.'/pms-connections')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_endpoint_and_inventory_mode_validation_rejects_unsafe_configuration(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        foreach ([
            'http://pms.example.test',
            'https://localhost',
            'https://metadata.google.internal',
            'https://pms.internal',
            'https://pms.example.test/api/v1',
            'https://user:pass@pms.example.test',
        ] as $endpoint) {
            $this->actingAs($admin)->postJson('/api/v1/hotels/'.$hotel->id.'/pms-connections', [
                ...$this->payload(), 'endpoint_origin' => $endpoint,
            ])->assertUnprocessable()->assertJsonValidationErrors('endpoint_origin');
        }
        $this->actingAs($admin)->postJson('/api/v1/hotels/'.$hotel->id.'/pms-connections', [
            ...$this->payload(), 'inventory_mode' => 'manual',
        ])->assertUnprocessable()->assertJsonValidationErrors('inventory_mode');
    }

    public function test_provider_catalog_keeps_unverified_checkout_disabled(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);

        $this->actingAs($admin)->getJson('/api/v1/pms/providers')
            ->assertOk()
            ->assertJsonPath('data.0.checkout_enabled', false)
            ->assertJsonPath('data.0.capabilities.inventory_assurance', 'none')
            ->assertJsonPath('data.1.checkout_enabled', false);
    }

    public function test_administrator_can_rotate_credentials_without_returning_them(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        $connection = PmsConnection::create([
            ...$this->payload(),
            'hotel_id' => $hotel->id,
            'created_by' => $admin->id,
            'capabilities' => ['hold_confirm' => false],
        ]);

        $this->actingAs($admin)->patchJson('/api/v1/pms-connections/'.$connection->id, [
            'credentials' => ['api_key' => 'rotated-secret'],
        ])->assertOk()->assertJsonMissingPath('data.credentials');

        $this->assertSame(['api_key' => 'rotated-secret'], $connection->fresh()->credentials);
    }

    public function test_provider_error_details_are_not_returned_to_the_browser(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        $connection = PmsConnection::create([
            ...$this->payload(),
            'hotel_id' => $hotel->id,
            'created_by' => $admin->id,
            'capabilities' => ['hold_confirm' => false],
            'last_error' => 'Authorization: Bearer leaked-token',
        ]);
        $connection->last_error = 'Authorization: Bearer leaked-token';
        $connection->save();

        $response = $this->actingAs($admin)->getJson('/api/v1/hotels/'.$hotel->id.'/pms-connections');
        $response
            ->assertOk()
            ->assertJsonPath('data.0.has_error', true)
            ->assertJsonMissingPath('data.0.last_error')
            ->assertSee('has_error');
        $this->assertStringNotContainsString('leaked-token', $response->getContent());
        $this->assertSame('Authorization: Bearer leaked-token', $connection->fresh()->last_error);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'provider' => 'surge',
            'environment' => 'sandbox',
            'endpoint_origin' => 'https://pms.example.test',
            'external_property_id' => 'property-123',
            'credentials' => ['api_key' => 'super-secret-token'],
            'inventory_mode' => 'pms',
        ];
    }
}
