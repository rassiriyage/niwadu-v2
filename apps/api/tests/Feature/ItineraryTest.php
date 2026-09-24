<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItineraryTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/v1/me/itineraries';

    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'My trip', 'stops' => [['slug' => 'ella', 'nights' => 2]]], $changes);
    }

    public function test_owned_crud_retains_order_and_rejects_stale_update_and_delete(): void
    {
        $this->actingAs(User::factory()->create());
        $created = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(self::ROOT, $this->payload())->assertCreated()->json('data');
        $this->assertSame(1, $created['version']);
        $path = self::ROOT.'/'.$created['id'];
        $this->getJson(self::ROOT)->assertOk()->assertJsonStructure(['data', 'links', 'meta'])->assertJsonPath('data.0.id', $created['id']);
        $response = $this->getJson($path)->assertJsonPath('data.stops.0.slug', 'ella')->assertJsonMissingPath('data.user_id');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->putJson($path, $this->payload(['version' => 1, 'stops' => [['slug' => 'kandy', 'nights' => 3], ['slug' => 'ella', 'nights' => 1]]]))->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.stops.0.slug', 'kandy');
        $this->putJson($path, $this->payload(['version' => 1]))->assertConflict();
        $this->deleteJson($path, ['version' => 1])->assertConflict();
        $this->getJson($path)->assertJsonPath('data.version', 2);
        $this->deleteJson($path, ['version' => 2])->assertNoContent();
        $this->getJson($path)->assertNotFound();
    }

    public function test_create_key_replays_original_after_update_and_delete_without_recreating(): void
    {
        $this->actingAs(User::factory()->create());
        $key = (string) Str::uuid();
        $response = $this->withHeader('Idempotency-Key', $key)->postJson(self::ROOT, $this->payload())->assertCreated()->json();
        $path = self::ROOT.'/'.$response['data']['id'];
        $this->postJson(self::ROOT, $this->payload())->assertCreated()->assertExactJson($response);
        $this->assertDatabaseCount('itineraries', 1);
        $this->postJson(self::ROOT, $this->payload(['name' => 'Different']))->assertConflict();
        $this->putJson($path, $this->payload(['version' => 1, 'name' => 'Edited']))->assertOk();
        $this->postJson(self::ROOT, $this->payload())->assertCreated()->assertExactJson($response);
        $this->getJson($path)->assertJsonPath('data.name', 'Edited')->assertJsonPath('data.version', 2);
        $this->deleteJson($path, ['version' => 2])->assertNoContent();
        $this->postJson(self::ROOT, $this->payload())->assertCreated()->assertExactJson($response);
        $this->assertDatabaseCount('itineraries', 0);
        $this->assertDatabaseCount('itinerary_creations', 1);
    }

    public function test_other_owners_cannot_read_write_delete_or_replay_another_owner(): void
    {
        $this->actingAs(User::factory()->create());
        $key = (string) Str::uuid();
        $id = $this->withHeader('Idempotency-Key', $key)->postJson(self::ROOT, $this->payload())->assertCreated()->json('data.id');
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $this->getJson(self::ROOT)->assertJsonCount(0, 'data');
        $this->getJson(self::ROOT.'/'.$id)->assertNotFound();
        $this->putJson(self::ROOT.'/'.$id, $this->payload(['version' => 1]))->assertNotFound();
        $this->deleteJson(self::ROOT.'/'.$id, ['version' => 1])->assertNotFound();
        $second = $this->postJson(self::ROOT, $this->payload())->assertCreated()->json('data.id');
        $this->assertNotSame($id, $second);
    }

    public function test_validation_rejects_unknown_ownership_destinations_duplicates_and_bad_nights(): void
    {
        $this->actingAs(User::factory()->create())->withHeader('Idempotency-Key', (string) Str::uuid());
        foreach ([['user_id' => 99], ['version' => 1], ['name' => ' '], ['stops' => [['slug' => 'not-a-destination', 'nights' => 2]]], ['stops' => [['slug' => 'ella', 'nights' => 0]]], ['stops' => [['slug' => 'ella', 'nights' => 15]]], ['stops' => [['slug' => 'ella', 'nights' => 1.5]]], ['stops' => [['slug' => 'ella', 'nights' => 2, 'price' => 0]]], ['stops' => [['slug' => 'ella', 'nights' => 2], ['slug' => 'ella', 'nights' => 1]]]] as $change) {
            $this->postJson(self::ROOT, $this->payload($change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('itineraries', 0);
        $this->assertDatabaseCount('itinerary_creations', 0);
        $this->withHeader('Idempotency-Key', 'invalid')->postJson(self::ROOT, $this->payload())->assertUnprocessable();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(self::ROOT, $this->payload(['stops' => []]))->assertCreated()->assertJsonCount(0, 'data.stops');
    }

    public function test_collection_limit_does_not_block_existing_key_replay_and_deletion_frees_capacity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid());
        $created = $this->postJson(self::ROOT, $this->payload())->assertCreated()->json();
        DB::table('itineraries')->insert(array_fill(0, 99, ['user_id' => $user->id, 'name' => 'Existing', 'stops' => '[]', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]));
        $this->postJson(self::ROOT, $this->payload())->assertCreated()->assertExactJson($created);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(self::ROOT, $this->payload())->assertUnprocessable();
        $this->getJson(self::ROOT)->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 5);
        $this->getJson(self::ROOT.'?page=0')->assertUnprocessable();
        $this->deleteJson(self::ROOT.'/'.$created['data']['id'], ['version' => 1])->assertNoContent();
        $this->postJson(self::ROOT, $this->payload())->assertCreated();
        $user->delete();
        $this->assertDatabaseCount('itineraries', 0);
        $this->assertDatabaseCount('itinerary_creations', 0);
    }

    public function test_failed_creation_ledger_write_rolls_back_the_itinerary(): void
    {
        $this->actingAs(User::factory()->create())->withHeader('Idempotency-Key', (string) Str::uuid());
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'itinerary_creations')) {
                throw new \RuntimeException('Injected creation ledger failure');
            }
        });
        $this->postJson(self::ROOT, $this->payload())->assertServerError();
        $this->assertDatabaseCount('itineraries', 0);
        $this->assertDatabaseCount('itinerary_creations', 0);
    }

    public function test_stop_limit_and_normalized_key_replay(): void
    {
        $this->actingAs(User::factory()->create());
        $key = (string) Str::uuid();
        $stops = array_map(fn (string $slug): array => ['slug' => $slug, 'nights' => 14], array_slice(config('itineraries.destinations'), 0, 21));
        $this->withHeader('Idempotency-Key', $key)->postJson(self::ROOT, $this->payload(['stops' => $stops]))->assertUnprocessable();
        array_pop($stops);
        $created = $this->postJson(self::ROOT, $this->payload(['name' => '  My trip  ', 'stops' => $stops]))->assertCreated()->json();
        $this->assertCount(20, $created['data']['stops']);
        $this->withHeader('Idempotency-Key', strtoupper($key))->postJson(self::ROOT, $this->payload(['stops' => $stops]))->assertCreated()->assertExactJson($created);
    }

    public function test_mutations_are_throttled(): void
    {
        $this->actingAs(User::factory()->create());
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson(self::ROOT, $this->payload())->assertUnprocessable();
        }
        $this->postJson(self::ROOT, $this->payload())->assertTooManyRequests();
        $this->assertDatabaseCount('itineraries', 0);
    }

    public function test_guest_and_csrf_protection(): void
    {
        $this->getJson(self::ROOT)->assertUnauthorized();
        $this->postJson(self::ROOT, $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->app->instance('env', 'local');
        $this->postJson(self::ROOT, $this->payload())->assertStatus(419);
    }
}
