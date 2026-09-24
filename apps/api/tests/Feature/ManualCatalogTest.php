<?php

namespace Tests\Feature;

use App\ManualQuoteSource;
use App\Models\Hotel;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_authoring_produces_complete_quote_and_changes_fence_old_revision(): void
    {
        [$hotel, $room, $plan] = $this->offering();
        $selection = ['hotel_id' => $hotel->id, 'rate_plan_id' => $plan, 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2];
        $source = app(ManualQuoteSource::class);
        $now = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $this->assertNull($source->resolve($selection, $now));
        $this->getJson('/api/v1/public/hotels/synthetic/rate-plans?arrival=2026-10-10&departure=2026-10-12&adults=2&adults=1')->assertUnprocessable();
        foreach (['2026-10-10', '2026-10-11'] as $date) {
            $this->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$room}/inventory-nights/{$date}", ['version' => 0, 'capacity' => 3])->assertOk();
            $this->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$room}/rate-plans/{$plan}/nights/{$date}", $this->night())->assertOk();
        }
        $this->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$room}/rate-plans/{$plan}/nights/2026-10-12", $this->night())->assertOk();
        $this->travelTo(new \DateTime('2026-10-01T00:00:00Z'));
        $this->getJson('/api/v1/public/hotels/synthetic/rate-plans?arrival=2026-10-10&departure=2026-10-12&adults=2')
            ->assertOk()->assertJsonPath('data.0.rate_plan_id', $plan)->assertJsonPath('data.0.total_minor', 22000)
            ->assertJsonPath('meta.inventory_reserved', false)->assertHeader('Cache-Control', 'no-store, private');
        $quote = $source->resolve($selection, $now);
        $this->assertSame('manual', $quote['source']);
        $this->assertSame($room, $quote['input']['room_type_id']);
        $this->assertSame(3, $quote['input']['nightly_conditions'][0]['available']);
        $this->assertSame(10000, $quote['input']['nightly'][0]['base_minor']);
        $this->assertSame(60, $quote['expires_at']->getTimestamp() - $now->getTimestamp());
        $this->assertNull($source->resolve(array_replace($selection, ['hotel_id' => Hotel::factory()->create()->id]), $now));
        $this->assertNull($source->resolve(array_replace($selection, ['adults' => 3]), $now));
        $uri = "/api/v1/hotels/{$hotel->id}/room-types/{$room}/rate-plans/{$plan}/nights/2026-10-10";
        $this->putJson($uri, $this->night())->assertConflict();
        $this->putJson($uri, array_replace($this->night(), ['version' => 1, 'base_minor' => 20000]))->assertOk();
        $this->assertNotSame($quote['source_revision']['fingerprint'], $source->resolve($selection, $now)['source_revision']['fingerprint']);
        $this->putJson($uri, array_replace($this->night(), ['version' => 2, 'stop_sell' => true]))->assertOk();
        $this->assertNull($source->resolve($selection, $now));
        $this->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$room}/inventory-nights?from=2026-10-10&to=2026-10-13")
            ->assertOk()->assertJsonPath('data.2.configured', false);
    }

    public function test_staff_permissions_scope_and_ownership_are_enforced(): void
    {
        [$hotel, $room, $plan] = $this->offering();
        $user = User::factory()->create();
        $hotel->users()->attach($user, ['role' => 'inventory_manager']);
        $this->actingAs($user);
        $this->getJson("/api/v1/hotels/{$hotel->id}")->assertJsonPath('data.permissions.manage_inventory', true)->assertJsonPath('data.permissions.manage_pms', false)->assertJsonPath('data.permissions.edit_profile', false);
        $uri = "/api/v1/hotels/{$hotel->id}/room-types/{$room}";
        $this->putJson($uri.'/inventory-nights/2026-10-10', ['version' => 0, 'capacity' => 2])->assertOk();
        $this->putJson($uri.'/inventory-pool', ['version' => 1, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo'])->assertForbidden();
        $this->putJson($uri.'/inventory-nights/2026-10-10', ['version' => 1, 'capacity' => 2, 'sold' => 0])->assertUnprocessable();
        $other = Hotel::factory()->create();
        $this->getJson("/api/v1/hotels/{$other->id}/room-types/{$room}/rate-plans")->assertNotFound();
        DB::table('inventory_pools')->where('room_type_id', $room)->update(['owner' => 'pms']);
        $this->putJson($uri.'/inventory-nights/2026-10-10', ['version' => 1, 'capacity' => 3])->assertConflict();
        $hotel->users()->detach($user);
        $this->getJson($uri.'/rate-plans')->assertNotFound();
    }

    public function test_missing_restrictions_incomplete_charges_closed_sales_and_withdrawal_fail_closed(): void
    {
        [$hotel, $room, $plan] = $this->offering();
        $selection = ['hotel_id' => $hotel->id, 'rate_plan_id' => $plan, 'arrival' => '2026-10-10', 'departure' => '2026-10-11', 'adults' => 1];
        $source = app(ManualQuoteSource::class);
        $now = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $root = "/api/v1/hotels/{$hotel->id}/room-types/{$room}";
        $this->putJson($root.'/inventory-nights/2026-10-10', ['version' => 0, 'capacity' => 2])->assertOk();
        $this->putJson($root."/rate-plans/{$plan}/nights/2026-10-10", $this->night())->assertOk();
        $this->assertNull($source->resolve($selection, $now), 'Checkout restriction missing must fail closed.');
        $this->putJson($root."/rate-plans/{$plan}/nights/2026-10-11", $this->night())->assertOk();
        $this->assertNotNull($source->resolve($selection, $now));
        foreach ([['mandatory_charges_complete' => false], ['stop_sell' => true], ['closed_to_arrival' => true], ['min_stay' => 2]] as $change) {
            DB::table('rate_plan_nights')->where('rate_plan_id', $plan)->where('stay_date', '2026-10-10')->update($change);
            $this->assertNull($source->resolve($selection, $now));
            DB::table('rate_plan_nights')->where('rate_plan_id', $plan)->where('stay_date', '2026-10-10')->update(array_intersect_key($this->night(), $change));
        }
        DB::table('rate_plan_nights')->where('rate_plan_id', $plan)->where('stay_date', '2026-10-11')->update(['closed_to_departure' => true]);
        $this->assertNull($source->resolve($selection, $now));
        DB::table('rate_plan_nights')->where('rate_plan_id', $plan)->update(['closed_to_departure' => false]);
        DB::table('inventory_nights')->update(['held' => 1, 'sold' => 1]);
        $this->assertNull($source->resolve($selection, $now));
        $this->putJson($root.'/inventory-nights/2026-10-10', ['version' => 1, 'capacity' => 1])->assertConflict();
        DB::table('inventory_nights')->update(['held' => 0, 'sold' => 0]);
        $this->assertNull($source->resolve(array_replace($selection, ['departure' => '2026-11-11']), $now));
        $this->assertNull($source->resolve($selection, new DateTimeImmutable('2026-10-12T00:00:00Z')));
        $this->putJson($root.'/inventory-pool', ['version' => 1, 'owner' => 'manual', 'sales_state' => 'closed', 'timezone' => 'Asia/Colombo'])->assertOk();
        $this->assertNull($source->resolve($selection, $now));
        $this->putJson($root.'/inventory-pool', ['version' => 2, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo'])->assertOk();
        $hotel->forceFill(['discovery_snapshot' => null])->save();
        $this->assertNull($source->resolve($selection, $now));
    }

    public function test_roles_payloads_and_shared_pool_identity(): void
    {
        [$hotel, $room, $plan] = $this->offering();
        $root = "/api/v1/hotels/{$hotel->id}/room-types/{$room}";
        $second = $this->postJson($root.'/rate-plans', ['name' => 'Second plan', 'status' => 'active', 'currency' => 'LKR', 'policy' => ['version' => '1', 'text' => 'No refund.']])->assertCreated()->json('data.id');
        $this->assertNotSame($plan, $second);
        $this->assertSame(1, DB::table('inventory_pools')->where('room_type_id', $room)->count());
        foreach (['viewer', 'reservations'] as $role) {
            $user = User::factory()->create();
            $hotel->users()->attach($user, ['role' => $role]);
            $this->actingAs($user)->getJson($root.'/rate-plans')->assertOk();
            $this->putJson($root.'/inventory-nights/2026-10-10', ['version' => 0, 'capacity' => 2])->assertForbidden();
        }
        $manager = User::factory()->create();
        $hotel->users()->attach($manager, ['role' => 'hotel_manager']);
        $this->actingAs($manager);
        foreach ([['base_minor' => null], ['tax_minor' => -1], ['fee_minor' => 1.5], ['mandatory_charges_complete' => null], ['currency' => 'USD'], ['policy' => null]] as $change) {
            $this->putJson($root."/rate-plans/{$plan}/nights/2026-10-10", array_replace($this->night(), $change))->assertUnprocessable();
        }
        $this->putJson($root.'/inventory-nights/2026-02-30', ['version' => 0, 'capacity' => 2])->assertUnprocessable();
        $this->getJson("/api/v1/hotels/{$hotel->id}/room-types/not-a-number/rate-plans")->assertNotFound();
        $this->getJson($root.'/inventory-nights?from=2026-01-01&to=2028-01-01')->assertUnprocessable();
        $this->putJson($root, ['version' => 1, 'name' => 'Updated', 'max_occupancy' => 2, 'status' => 'active'])->assertOk();
        $this->putJson($root, ['version' => 1, 'name' => 'Stale', 'max_occupancy' => 2, 'status' => 'active'])->assertConflict();
        $this->putJson($root."/rate-plans/{$plan}", ['version' => 1, 'name' => 'Changed', 'status' => 'active', 'currency' => 'LKR', 'policy' => ['version' => '2', 'text' => 'New explicit policy.']])->assertOk();
    }

    private function offering(): array
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create(['discovery_slug' => 'synthetic', 'discovery_snapshot' => ['public' => ['name' => 'Synthetic']]]);
        $this->actingAs($admin);
        $room = $this->postJson("/api/v1/hotels/{$hotel->id}/room-types", ['name' => 'Double', 'max_occupancy' => 2, 'status' => 'active'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$room}/inventory-pool", ['version' => 0, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo'])->assertOk();
        $plan = $this->postJson("/api/v1/hotels/{$hotel->id}/room-types/{$room}/rate-plans", ['name' => 'Room only', 'status' => 'active', 'currency' => 'LKR', 'policy' => ['version' => '1', 'text' => 'No refunds.']])->assertCreated()->json('data.id');

        return [$hotel, $room, $plan];
    }

    private function night(): array
    {
        return ['version' => 0, 'base_minor' => 10000, 'tax_minor' => 1000, 'fee_minor' => 0, 'mandatory_charges_complete' => true, 'stop_sell' => false, 'min_stay' => 1, 'max_stay' => 30, 'closed_to_arrival' => false, 'closed_to_departure' => false];
    }
}
