<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AtomicCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_batch_rolls_back_all_dates_on_stale_or_obligated_night(): void
    {
        $hotel = Hotel::factory()->create();
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $pool = InventoryPool::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'manual']);
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $url = "/api/v1/hotels/{$hotel->id}/room-types/{$room->id}/inventory-nights";
        $nights = [['stay_date' => '2026-10-10', 'version' => 0, 'capacity' => 3], ['stay_date' => '2026-10-11', 'version' => 0, 'capacity' => 4]];
        $this->putJson($url, ['nights' => array_reverse($nights)])->assertOk()->assertJsonPath('data.0.stay_date', '2026-10-10')->assertJsonPath('data.0.version', 1)->assertJsonPath('data.1.capacity', 4);
        $nights[0]['version'] = 1;
        $nights[0]['capacity'] = 9;
        $this->putJson($url, ['nights' => $nights])->assertConflict();
        $this->assertDatabaseHas('inventory_nights', ['stay_date' => '2026-10-10', 'capacity' => 3, 'version' => 1]);
        DB::table('inventory_nights')->where('stay_date', '2026-10-11')->update(['held' => 2]);
        $nights[1]['version'] = 1;
        $nights[1]['capacity'] = 1;
        $this->putJson($url, ['nights' => $nights])->assertConflict();
        $this->assertDatabaseHas('inventory_nights', ['stay_date' => '2026-10-10', 'capacity' => 3, 'version' => 1]);
        $this->assertDatabaseHas('inventory_nights', ['inventory_pool_id' => $pool->id, 'stay_date' => '2026-10-11', 'held' => 2, 'capacity' => 4]);
        $this->putJson($url, ['nights' => [$nights[0], $nights[0]]])->assertUnprocessable();
        $this->putJson($url, ['nights' => []])->assertUnprocessable();
        $this->putJson($url, ['nights' => array_fill(0, 367, $nights[0])])->assertUnprocessable();
        $this->putJson($url, ['nights' => [$nights[0]], 'currency' => 'USD'])->assertUnprocessable();
        $nights[1]['capacity'] = 4;
        $nights[1]['held'] = 0;
        $this->putJson($url, ['nights' => $nights])->assertUnprocessable();
        $this->assertDatabaseHas('inventory_nights', ['stay_date' => '2026-10-10', 'capacity' => 3, 'version' => 1]);
    }

    public function test_rate_batch_is_scoped_to_one_currency_and_draft_authority(): void
    {
        $employee = User::factory()->create(['platform_role' => 'onboarding']);
        $hotel = Hotel::factory()->create(['created_by' => $employee->id, 'status' => 'draft']);
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $usd = RatePlan::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'currency' => 'USD', 'meal_plan' => 'BB', 'status' => 'draft']);
        $lkr = RatePlan::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'currency' => 'LKR', 'meal_plan' => 'BB', 'status' => 'draft']);
        $url = "/api/v1/hotels/{$hotel->id}/room-types/{$room->id}/rate-plans/{$usd->id}/nights";
        $night = ['stay_date' => '2026-10-10', 'version' => 0, 'base_minor' => 12500, 'tax_minor' => 0, 'fee_minor' => 0, 'mandatory_charges_complete' => true, 'stop_sell' => false, 'min_stay' => 1, 'max_stay' => 30, 'closed_to_arrival' => false, 'closed_to_departure' => false];
        $next = array_replace($night, ['stay_date' => '2026-10-11', 'base_minor' => null, 'mandatory_charges_complete' => false]);
        $this->actingAs($employee)->putJson($url, ['nights' => [$night, $next]])->assertOk()->assertJsonPath('data.0.base_minor', 12500)->assertJsonPath('data.1.base_minor', null);
        $this->assertDatabaseMissing('rate_plan_nights', ['rate_plan_id' => $lkr->id]);
        $this->assertDatabaseCount('inventory_pools', 0);
        $this->assertSame(0, $hotel->fresh()->onboarding_version);
        $night['version'] = 1;
        $next['version'] = 1;
        $next['currency'] = 'LKR';
        $this->putJson($url, ['nights' => [$night, $next]])->assertUnprocessable();
        $this->assertDatabaseHas('rate_plan_nights', ['rate_plan_id' => $usd->id, 'stay_date' => $night['stay_date'], 'version' => 1]);
        $otherRoom = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $this->putJson(str_replace('/room-types/'.$room->id.'/', '/room-types/'.$otherRoom->id.'/', $url), ['nights' => [$night]])->assertNotFound();
        $otherHotel = Hotel::factory()->create(['created_by' => $employee->id, 'status' => 'draft']);
        $this->putJson(str_replace('/hotels/'.$hotel->id.'/', '/hotels/'.$otherHotel->id.'/', $url), ['nights' => [$night]])->assertNotFound();
        $usd->forceFill(['status' => 'active'])->save();
        $this->putJson($url, ['nights' => [$night]])->assertForbidden();
        $usd->forceFill(['status' => 'draft'])->save();
        InventoryPool::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'pms']);
        $this->putJson($url, ['nights' => [$night]])->assertConflict();
        $this->actingAs(User::factory()->create())->putJson($url, ['nights' => [$night]])->assertNotFound();
    }
}
