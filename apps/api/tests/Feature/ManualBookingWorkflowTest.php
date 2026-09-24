<?php

namespace Tests\Feature;

use App\BookingWorkflow;
use App\Models\BookingQuote;
use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ManualBookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_catalog_quote_persists_identity_revision_and_unreserved_intent(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        $workflow = app(BookingWorkflow::class);
        $result = $workflow->requestQuote($owner, $selection);
        $this->assertSame('available', $result['state']);
        $quote = BookingQuote::findOrFail($result['quote']['id']);
        $this->assertSame('manual', $quote->source);
        $this->assertSame($plan->id, $quote->rate_plan_id);
        $this->assertSame($plan->room_type_id, $quote->room_type_id);
        $this->assertSame(11000, $quote->snapshot['total_minor']);
        $this->assertSame(64, strlen($quote->source_revision['fingerprint']));
        $this->assertSame(60, $quote->expires_at->getTimestamp() - now()->getTimestamp());
        $intent = $workflow->createIntent($owner, ['quote_id' => $quote->id], 'manual-key-1');
        $this->assertSame('awaiting_hold', $intent['state']);
        $this->assertSame('unavailable', $intent['payment']['state']);
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('held'));
        $this->travel(61)->seconds();
        $this->assertSame($intent, $workflow->createIntent($owner, ['quote_id' => $quote->id], 'manual-key-1'));
    }

    public function test_changed_catalog_requires_new_quote_without_mutating_snapshot(): void
    {
        [$owner, $selection] = $this->offering();
        $workflow = app(BookingWorkflow::class);
        $result = $workflow->requestQuote($owner, $selection);
        DB::table('rate_plan_nights')->update(['base_minor' => 20000, 'version' => 2]);
        try {
            $workflow->createIntent($owner, ['quote_id' => $result['quote']['id']], 'manual-key-2');
            $this->fail('Changed quote must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('quote_changed', $exception->getMessage());
        }
        $this->assertDatabaseCount('booking_intents', 0);
        $this->assertSame(11000, BookingQuote::findOrFail($result['quote']['id'])->snapshot['total_minor']);
    }

    public function test_withdrawn_or_pms_inventory_returns_no_quote(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        DB::table('inventory_pools')->update(['owner' => 'pms']);
        $this->assertSame('unavailable', app(BookingWorkflow::class)->requestQuote($owner, $selection)['state']);
        DB::table('inventory_pools')->update(['owner' => 'manual']);
        Hotel::whereKey($plan->hotel_id)->update(['discovery_snapshot' => null]);
        $this->assertSame('unavailable', app(BookingWorkflow::class)->requestQuote($owner, $selection)['state']);
        $this->assertDatabaseCount('booking_quotes', 0);
    }

    public function test_manual_quote_expires_at_exact_deadline(): void
    {
        [$owner, $selection] = $this->offering();
        $workflow = app(BookingWorkflow::class);
        $quote = $workflow->requestQuote($owner, $selection)['quote'];
        $this->travel(60)->seconds();
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('quote_expired');
        $workflow->createIntent($owner, ['quote_id' => $quote['id']], 'manual-expiry-key');
    }

    public function test_quote_catalog_foreign_key_rejects_another_room(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        $quote = app(BookingWorkflow::class)->requestQuote($owner, $selection)['quote'];
        $room = RoomType::factory()->create(['hotel_id' => $plan->hotel_id]);
        $this->expectException(QueryException::class);
        DB::table('booking_quotes')->where('id', $quote['id'])->update(['room_type_id' => $room->id]);
    }

    private function offering(): array
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-01T00:00:00Z'));
        $hotel = Hotel::factory()->create(['discovery_snapshot' => ['public' => ['name' => 'Synthetic']]]);
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id, 'status' => 'active', 'max_occupancy' => 2]);
        $pool = InventoryPool::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo']);
        $plan = RatePlan::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'status' => 'active']);
        DB::table('inventory_nights')->insert(['inventory_pool_id' => $pool->id, 'stay_date' => '2026-10-10', 'capacity' => 2, 'held' => 0, 'sold' => 0, 'version' => 1]);
        foreach (['2026-10-10', '2026-10-11'] as $date) {
            DB::table('rate_plan_nights')->insert(['rate_plan_id' => $plan->id, 'stay_date' => $date, 'base_minor' => 10000, 'tax_minor' => 1000, 'fee_minor' => 0, 'mandatory_charges_complete' => true, 'stop_sell' => false, 'min_stay' => 1, 'max_stay' => 30, 'closed_to_arrival' => false, 'closed_to_departure' => false, 'version' => 1]);
        }

        return [User::factory()->create(), ['hotel_id' => $hotel->id, 'rate_plan_id' => $plan->id, 'arrival' => '2026-10-10', 'departure' => '2026-10-11', 'adults' => 2], $plan];
    }
}
