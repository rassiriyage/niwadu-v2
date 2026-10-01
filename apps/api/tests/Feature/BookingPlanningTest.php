<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_routes_are_disabled_by_default(): void
    {
        [$owner, $selection] = $this->offering();
        $this->assertFalse(config('bookings.planning_enabled'));
        $this->actingAs($owner)->postJson('/api/v1/me/booking-quotes', $selection)->assertNotFound();
        $this->postJson('/api/v1/me/booking-intents', ['quote_id' => (string) Str::uuid()])->assertNotFound();
        $this->getJson('/api/v1/me/booking-intents/'.Str::uuid())->assertNotFound();
        $this->assertDatabaseCount('booking_quotes', 0);
        $this->assertDatabaseCount('booking_intents', 0);
    }

    public function test_enabled_routes_require_authentication_and_csrf(): void
    {
        config(['bookings.planning_enabled' => true]);
        [$owner, $selection] = $this->offering();
        $this->postJson('/api/v1/me/booking-quotes', $selection)->assertUnauthorized();
        $this->postJson('/api/v1/me/booking-intents', [])->assertUnauthorized();
        $this->getJson('/api/v1/me/booking-intents/'.Str::uuid())->assertUnauthorized();
        $this->app->instance('env', 'local');
        $this->actingAs($owner)->postJson('/api/v1/me/booking-quotes', $selection)->assertStatus(419);
        $this->postJson('/api/v1/me/booking-intents', [])->assertStatus(419);
        $this->assertDatabaseCount('booking_quotes', 0);
    }

    public function test_traveller_session_creates_private_exact_currency_quote_and_unreserved_intent(): void
    {
        config(['bookings.planning_enabled' => true]);
        [$owner, $selection] = $this->offering();
        $this->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $quote = $this->postJson('/api/v1/me/booking-quotes', $selection)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonPath('data.state', 'available')->assertJsonPath('data.quote.snapshot.currency', 'USD')
            ->assertJsonPath('data.quote.snapshot.meal_plan', 'BB')->assertJsonPath('data.quote.snapshot.total_minor', 11000)
            ->assertJsonPath('meta.checkout_enabled', false)->json('data.quote');
        $intent = $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $quote['id']], ['Idempotency-Key' => 'planning-intent-key'])->assertOk()
            ->assertJsonPath('data.state', 'awaiting_hold')->assertJsonPath('data.payment.state', 'unavailable')
            ->assertJsonPath('data.payment.reason', 'payment_setup_incomplete')->assertJsonPath('meta.checkout_enabled', false)->json('data');
        $this->getJson('/api/v1/me/booking-intents/'.$intent['id'])->assertOk()->assertJsonPath('data', $intent)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('hotel_user', 0);
        $this->assertDatabaseCount('manual_inventory_holds', 0);
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('held'));
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('sold'));
    }

    public function test_foreign_accounts_and_privilege_fields_cannot_mutate_planning_records(): void
    {
        config(['bookings.planning_enabled' => true]);
        [$owner, $selection] = $this->offering();
        $quote = $this->actingAs($owner)->postJson('/api/v1/me/booking-quotes', $selection)->assertOk()->json('data.quote');
        $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $quote['id']])->assertUnprocessable();
        $intent = $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $quote['id']], ['Idempotency-Key' => 'owner-intent-key'])->assertOk()->json('data');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->getJson('/api/v1/me/booking-intents/'.$intent['id'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $this->getJson('/api/v1/me/booking-intents/'.$intent['id'])->assertNotFound();
        $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $quote['id']], ['Idempotency-Key' => 'foreign-intent-key'])->assertNotFound();
        foreach (['user_id' => $owner->id, 'currency' => 'LKR', 'total_minor' => 1, 'payment' => ['state' => 'paid'], 'expires_at' => '2030-01-01'] as $field => $value) {
            $this->postJson('/api/v1/me/booking-quotes', array_replace($selection, [$field => $value]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $quote['id'], 'state' => 'confirmed'], ['Idempotency-Key' => 'forged-intent-key'])->assertUnprocessable();
        $this->assertDatabaseCount('booking_quotes', 1);
        $this->assertDatabaseCount('booking_intents', 1);
        $this->assertDatabaseCount('manual_inventory_holds', 0);
    }

    public function test_replay_after_quote_expiry_is_stable_but_new_intent_requires_fresh_quote(): void
    {
        config(['bookings.planning_enabled' => true]);
        [$owner, $selection] = $this->offering();
        $quote = $this->actingAs($owner)->postJson('/api/v1/me/booking-quotes', $selection)->assertOk()->json('data.quote');
        $otherQuote = $this->postJson('/api/v1/me/booking-quotes', $selection)->assertOk()->json('data.quote');
        $headers = ['Idempotency-Key' => 'stable-intent-key'];
        $intent = $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $quote['id']], $headers)->assertOk()->json();
        $this->travel(60)->seconds();
        $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $quote['id']], $headers)->assertOk()->assertExactJson($intent);
        $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $otherQuote['id']], ['Idempotency-Key' => 'new-intent-key'])->assertConflict()->assertJsonPath('code', 'quote_expired');
        $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $otherQuote['id']], $headers)->assertConflict()->assertJsonPath('code', 'idempotency_payload_mismatch');
        $this->assertDatabaseCount('booking_intents', 1);
    }

    public function test_catalog_change_and_unavailable_offer_have_explicit_honest_responses(): void
    {
        config(['bookings.planning_enabled' => true]);
        [$owner, $selection] = $this->offering();
        $quote = $this->actingAs($owner)->postJson('/api/v1/me/booking-quotes', $selection)->assertOk()->json('data.quote');
        DB::table('rate_plan_nights')->update(['base_minor' => 20000, 'version' => 2]);
        $this->postJson('/api/v1/me/booking-intents', ['quote_id' => $quote['id']], ['Idempotency-Key' => 'changed-intent-key'])->assertConflict()->assertJsonPath('code', 'quote_changed');
        DB::table('inventory_pools')->update(['owner' => 'pms']);
        $this->postJson('/api/v1/me/booking-quotes', $selection)->assertOk()->assertJsonPath('data.state', 'unavailable')
            ->assertJsonPath('data.reason', 'quote_unavailable')->assertJsonPath('data.quote', null);
        $this->assertDatabaseCount('booking_quotes', 1);
        $this->assertDatabaseCount('booking_intents', 0);
    }

    public function test_valid_numeric_form_values_are_normalized_before_source_identity_checks(): void
    {
        config(['bookings.planning_enabled' => true]);
        [$owner, $selection] = $this->offering();
        foreach (['hotel_id', 'rate_plan_id', 'adults'] as $field) {
            $selection[$field] = (string) $selection[$field];
        }
        $this->actingAs($owner)->postJson('/api/v1/me/booking-quotes', $selection)->assertOk()
            ->assertJsonPath('data.state', 'available')->assertJsonPath('data.quote.snapshot.adults', 2);
    }

    public function test_quote_writes_are_rate_limited_and_no_hold_or_payment_route_exists(): void
    {
        config(['bookings.planning_enabled' => true]);
        [$owner] = $this->offering();
        $this->actingAs($owner);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/v1/me/booking-quotes', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/me/booking-quotes', [])->assertTooManyRequests();
        $this->postJson('/api/v1/me/booking-holds', [])->assertNotFound();
        $this->postJson('/api/v1/me/booking-payments', [])->assertNotFound();
        $this->assertDatabaseCount('booking_quotes', 0);
        $this->assertDatabaseCount('manual_inventory_holds', 0);
    }

    private function offering(): array
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-01T00:00:00Z'));
        $hotel = Hotel::factory()->create(['discovery_snapshot' => ['public' => ['name' => 'Synthetic']]]);
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id, 'status' => 'active', 'max_occupancy' => 2]);
        $pool = InventoryPool::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo']);
        $plan = RatePlan::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'status' => 'active', 'meal_plan' => 'BB', 'currency' => 'USD']);
        DB::table('inventory_nights')->insert(['inventory_pool_id' => $pool->id, 'stay_date' => '2026-10-10', 'capacity' => 2, 'held' => 0, 'sold' => 0, 'version' => 1]);
        foreach (['2026-10-10', '2026-10-11'] as $date) {
            DB::table('rate_plan_nights')->insert(['rate_plan_id' => $plan->id, 'stay_date' => $date, 'base_minor' => 10000, 'tax_minor' => 1000, 'fee_minor' => 0, 'mandatory_charges_complete' => true, 'stop_sell' => false, 'min_stay' => 1, 'max_stay' => 30, 'closed_to_arrival' => false, 'closed_to_departure' => false, 'version' => 1]);
        }

        return [User::factory()->create(), ['hotel_id' => $hotel->id, 'rate_plan_id' => $plan->id, 'arrival' => '2026-10-10', 'departure' => '2026-10-11', 'adults' => 2], $plan];
    }
}
