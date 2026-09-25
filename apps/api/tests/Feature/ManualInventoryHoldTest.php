<?php

namespace Tests\Feature;

use App\BookingWorkflow;
use App\ManualInventoryHoldService;
use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ManualInventoryHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_nights_are_held_and_replay_then_release_changes_counters_once(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $service = app(ManualInventoryHoldService::class);
        $expiry = now()->addMinutes(5)->toDateTimeImmutable();
        $hold = $service->acquire($owner, $intent['id'], $expiry);
        $this->assertSame('active', $hold['state']);
        $this->assertSame([1, 1], DB::table('inventory_nights')->orderBy('stay_date')->pluck('held')->all());
        $this->assertSame($hold, $service->acquire($owner, $intent['id'], $expiry));
        $this->assertDatabaseCount('manual_inventory_holds', 1);
        $this->assertDatabaseCount('manual_inventory_hold_nights', 2);
        $this->assertSame('held', app(BookingWorkflow::class)->getIntent($owner, $intent['id'])['state']);
        $released = $service->release($owner, $hold['id']);
        $this->assertSame('released', $released['state']);
        $this->assertSame($released, $service->release($owner, $hold['id']));
        $this->assertSame($released, $service->acquire($owner, $intent['id'], $expiry));
        $this->assertSame([0, 0], DB::table('inventory_nights')->orderBy('stay_date')->pluck('held')->all());
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('sold'));
    }

    public function test_expiry_recovers_capacity_once_at_exact_deadline_and_never_revives(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $service = app(ManualInventoryHoldService::class);
        $expiry = now()->addMinutes(5)->toDateTimeImmutable();
        $hold = $service->acquire($owner, $intent['id'], $expiry);
        $this->assertSame(0, $service->expireDue());
        $this->travel(5)->minutes();
        $this->assertSame(1, $service->expireDue());
        $this->assertSame(0, $service->expireDue());
        $this->assertSame('expired', $service->release($owner, $hold['id'])['state']);
        $this->assertSame('expired', $service->acquire($owner, $intent['id'], $expiry)['state']);
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('held'));
    }

    public function test_late_night_change_rejects_entire_hold_without_partial_accounting(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        DB::table('inventory_nights')->where('stay_date', '2026-10-11')->update(['capacity' => 0, 'version' => 2]);
        try {
            app(ManualInventoryHoldService::class)->acquire($owner, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable());
            $this->fail('Unavailable second night must reject the whole hold.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('manual_inventory_holds', 0);
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('held'));
    }

    public function test_another_traveller_cannot_acquire_or_release_the_hold(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $other = User::factory()->create(['platform_role' => 'administrator']);
        $service = app(ManualInventoryHoldService::class);
        $expiry = now()->addMinutes(5)->toDateTimeImmutable();
        try {
            $service->acquire($other, $intent['id'], $expiry);
            $this->fail('Foreign acquire must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $hold = $service->acquire($owner, $intent['id'], $expiry);
        $this->expectException(HttpException::class);
        $service->release($other, $hold['id']);
    }

    public function test_failed_second_ledger_write_rolls_back_every_night_and_retry_succeeds(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts): void {
            if (str_starts_with($query->sql, 'insert into "manual_inventory_hold_nights"') && ++$inserts === 2) {
                throw new \RuntimeException('Injected second-night failure');
            }
        });
        $service = app(ManualInventoryHoldService::class);
        $expiry = now()->addMinutes(5)->toDateTimeImmutable();
        try {
            $service->acquire($owner, $intent['id'], $expiry);
            $this->fail('Injected failure must escape the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected second-night failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('manual_inventory_holds', 0);
        $this->assertDatabaseCount('manual_inventory_hold_nights', 0);
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('held'));
        $this->assertSame('active', $service->acquire($owner, $intent['id'], $expiry)['state']);
        $this->assertSame(2, (int) DB::table('inventory_nights')->sum('held'));
    }

    public function test_retry_with_different_expiry_cannot_extend_hold(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $service = app(ManualInventoryHoldService::class);
        $service->acquire($owner, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable());
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('hold_expiry_mismatch');
        $service->acquire($owner, $intent['id'], now()->addMinutes(10)->toDateTimeImmutable());
    }

    public function test_expired_quote_cannot_acquire_even_with_future_hold_expiry(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $this->travel(60)->seconds();
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('quote_expired');
        app(ManualInventoryHoldService::class)->acquire($owner, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable());
    }

    public function test_corrupt_release_accounting_rolls_back_and_can_be_recovered_after_repair(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $service = app(ManualInventoryHoldService::class);
        $hold = $service->acquire($owner, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable());
        DB::table('inventory_nights')->where('stay_date', '2026-10-11')->update(['held' => 0]);
        try {
            $service->release($owner, $hold['id']);
            $this->fail('Corrupt held count must fail closed.');
        } catch (\LogicException $exception) {
            $this->assertSame('Hold inventory accounting is inconsistent.', $exception->getMessage());
        }
        $this->assertDatabaseHas('manual_inventory_holds', ['id' => $hold['id'], 'state' => 'active']);
        $this->assertDatabaseHas('inventory_nights', ['stay_date' => '2026-10-10', 'held' => 1]);
        DB::table('inventory_nights')->where('stay_date', '2026-10-11')->update(['held' => 1]);
        $this->assertSame('released', $service->release($owner, $hold['id'])['state']);
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('held'));
    }

    public function test_rollback_cannot_orphan_active_held_inventory(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        app(ManualInventoryHoldService::class)->acquire($owner, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable());
        $migration = require database_path('migrations/2026_09_25_052256_create_manual_inventory_holds_tables.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Release or expire active manual holds');
        $migration->down();
    }

    public function test_pms_ownership_after_intent_creation_cannot_fall_back_to_manual_stock(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        DB::table('inventory_pools')->update(['owner' => 'pms', 'ownership_version' => 2]);
        try {
            app(ManualInventoryHoldService::class)->acquire($owner, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable());
            $this->fail('PMS stock must not acquire a manual hold.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('manual_inventory_holds', 0);
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('held'));
    }

    public function test_withdrawal_and_ownership_change_do_not_block_original_hold_cleanup(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $service = app(ManualInventoryHoldService::class);
        $hold = $service->acquire($owner, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable());
        Hotel::whereKey($plan->hotel_id)->update(['discovery_snapshot' => null]);
        DB::table('inventory_pools')->update(['owner' => 'pms', 'sales_state' => 'closed', 'ownership_version' => 2]);
        $this->assertSame('released', $service->release($owner, $hold['id'])['state']);
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('held'));
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('sold'));
    }

    public function test_elapsed_hold_is_not_reported_as_held_before_recovery_runs(): void
    {
        [$owner, $selection] = $this->offering();
        $intent = $this->intent($owner, $selection);
        app(ManualInventoryHoldService::class)->acquire($owner, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable());
        $this->travel(5)->minutes();
        $this->assertSame('hold_expired', app(BookingWorkflow::class)->getIntent($owner, $intent['id'])['state']);
        $this->assertSame(2, (int) DB::table('inventory_nights')->sum('held'), 'Capacity stays conservatively occupied until durable cleanup.');
    }

    public function test_independent_meal_currency_quotes_compete_for_the_same_room_stock(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        $plan->forceFill(['meal_plan' => 'BB'])->save();
        DB::table('inventory_nights')->update(['capacity' => 1]);
        $usd = RatePlan::factory()->create(['hotel_id' => $plan->hotel_id, 'room_type_id' => $plan->room_type_id, 'status' => 'active', 'meal_plan' => 'HB', 'currency' => 'USD']);
        foreach (DB::table('rate_plan_nights')->where('rate_plan_id', $plan->id)->get() as $night) {
            $row = (array) $night;
            unset($row['id']);
            DB::table('rate_plan_nights')->insert(array_replace($row, ['rate_plan_id' => $usd->id, 'base_minor' => 750, 'tax_minor' => 0, 'fee_minor' => 0]));
        }
        $workflow = app(BookingWorkflow::class);
        $other = User::factory()->create();
        $lkrQuote = $workflow->requestQuote($owner, $selection)['quote'];
        $usdQuote = $workflow->requestQuote($other, array_replace($selection, ['rate_plan_id' => $usd->id]))['quote'];
        $this->assertSame(['LKR', 'BB', 22000], [$lkrQuote['snapshot']['currency'], $lkrQuote['snapshot']['meal_plan'], $lkrQuote['snapshot']['total_minor']]);
        $this->assertSame(['USD', 'HB', 1500], [$usdQuote['snapshot']['currency'], $usdQuote['snapshot']['meal_plan'], $usdQuote['snapshot']['total_minor']]);
        $first = $workflow->createIntent($owner, ['quote_id' => $lkrQuote['id']], 'lkr-meal-intent');
        $second = $workflow->createIntent($other, ['quote_id' => $usdQuote['id']], 'usd-meal-intent');
        $service = app(ManualInventoryHoldService::class);
        $hold = $service->acquire($owner, $first['id'], now()->addMinutes(5)->toDateTimeImmutable());
        try {
            $service->acquire($other, $second['id'], now()->addMinutes(5)->toDateTimeImmutable());
            $this->fail('USD plan must not have a separate room allotment.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_pools', 1);
        $this->assertDatabaseCount('manual_inventory_holds', 1);
        $service->release($owner, $hold['id']);
        $fresh = $workflow->requestQuote($other, array_replace($selection, ['rate_plan_id' => $usd->id]))['quote'];
        $intent = $workflow->createIntent($other, ['quote_id' => $fresh['id']], 'usd-fresh-intent');
        $this->assertSame('active', $service->acquire($other, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable())['state']);
        $this->assertSame([1, 1], DB::table('inventory_nights')->orderBy('stay_date')->pluck('held')->all());
    }

    public function test_missing_usd_rates_never_fall_back_to_ready_lkr_offer(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        $usd = RatePlan::factory()->create(['hotel_id' => $plan->hotel_id, 'room_type_id' => $plan->room_type_id, 'status' => 'active', 'meal_plan' => 'BB', 'currency' => 'USD']);
        $workflow = app(BookingWorkflow::class);
        $this->assertSame('available', $workflow->requestQuote($owner, $selection)['state']);
        $this->assertSame('unavailable', $workflow->requestQuote($owner, array_replace($selection, ['rate_plan_id' => $usd->id]))['state']);
        $this->assertDatabaseCount('booking_quotes', 1);
    }

    private function intent(User $owner, array $selection): array
    {
        $workflow = app(BookingWorkflow::class);
        $quote = $workflow->requestQuote($owner, $selection)['quote'];

        return $workflow->createIntent($owner, ['quote_id' => $quote['id']], 'hold-test-intent');
    }

    private function offering(): array
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-01T00:00:00Z'));
        $hotel = Hotel::factory()->create(['discovery_snapshot' => ['public' => ['name' => 'Synthetic']]]);
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id, 'status' => 'active', 'max_occupancy' => 2]);
        $pool = InventoryPool::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo']);
        $plan = RatePlan::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'status' => 'active']);
        DB::table('inventory_nights')->insert(['inventory_pool_id' => $pool->id, 'stay_date' => '2026-10-10', 'capacity' => 2, 'held' => 0, 'sold' => 0, 'version' => 1]);
        DB::table('inventory_nights')->insert(['inventory_pool_id' => $pool->id, 'stay_date' => '2026-10-11', 'capacity' => 2, 'held' => 0, 'sold' => 0, 'version' => 1]);
        foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $date) {
            DB::table('rate_plan_nights')->insert(['rate_plan_id' => $plan->id, 'stay_date' => $date, 'base_minor' => 10000, 'tax_minor' => 1000, 'fee_minor' => 0, 'mandatory_charges_complete' => true, 'stop_sell' => false, 'min_stay' => 1, 'max_stay' => 30, 'closed_to_arrival' => false, 'closed_to_departure' => false, 'version' => 1]);
        }

        return [User::factory()->create(), ['hotel_id' => $hotel->id, 'rate_plan_id' => $plan->id, 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2], $plan];
    }
}
