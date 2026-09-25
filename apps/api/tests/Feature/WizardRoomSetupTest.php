<?php

namespace Tests\Feature;

use App\ManualQuoteSource;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WizardRoomSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_resume_room_photos_and_separate_currency_drafts_without_activating_sales(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create(['platform_role' => 'onboarding']);
        $hotel = Hotel::factory()->create(['created_by' => $employee->id]);
        $this->actingAs($employee);
        $root = '/api/v1/hotels/'.$hotel->id;
        $this->getJson($root)->assertOk()->assertJsonPath('data.permissions.author_rates', true);
        $room = $this->postJson($root.'/room-types', ['name' => 'Double', 'max_occupancy' => 2, 'status' => 'draft'])->assertCreated()->json('data.id');
        $image = UploadedFile::fake()->createWithContent('room.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOZkAAAAASUVORK5CYII='));
        $photo = $this->postJson($root.'/room-types/'.$room.'/photos', ['version' => 1, 'caption' => 'Synthetic room', 'photo' => $image])->assertCreated()->json('data.photos.0.id');
        $this->getJson($root.'/photos')->assertJsonCount(0, 'data');
        $this->getJson($root.'/room-types/'.$room)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.photos.0.id', $photo);
        $this->getJson($root.'/photos/'.$photo)->assertNotFound();
        $this->get($root.'/room-types/'.$room.'/photos/'.$photo)->assertOk();
        $draftInput = $this->plan('LKR');
        unset($draftInput['policy']);
        $draftInput['meal_plan'] = 'RO';
        $draftPlan = $this->postJson($root.'/room-types/'.$room.'/rate-plans', $draftInput)->assertCreated()->json('data.id');
        $partialRate = array_replace($this->night(1000000), ['tax_minor' => null, 'fee_minor' => null, 'mandatory_charges_complete' => false]);
        $this->putJson($root.'/room-types/'.$room.'/rate-plans/'.$draftPlan.'/nights/2026-10-10', $partialRate)->assertOk()->assertJsonPath('data.tax_minor', null);
        $this->putJson($root.'/room-types/'.$room.'/rate-plans/'.$draftPlan.'/nights/2026-10-10', array_replace($partialRate, ['version' => 1, 'mandatory_charges_complete' => true]))->assertUnprocessable();
        $ids = [];
        foreach (['LKR', 'USD'] as $currency) {
            $ids[] = $this->postJson($root.'/room-types/'.$room.'/rate-plans', $this->plan($currency))->assertCreated()->json('data.id');
        }
        $this->assertNotSame($ids[0], $ids[1]);
        $this->putJson($root.'/room-types/'.$room.'/rate-plans/'.$ids[1].'/nights/2026-10-10', $this->night(12500))->assertOk();
        $this->getJson($root.'/room-types/'.$room.'/rate-plans')->assertJsonCount(3, 'data')->assertJsonPath('data.2.currency', 'USD');
        $review = $this->getJson($root.'/onboarding')->assertOk()->assertJsonPath('catalog_room_count', 1)->json();
        $this->assertNotContains('Room names, occupancy and quantities', $review['missing']);
        $this->assertContains('Hotel photographs', $review['missing']);
        $this->assertDatabaseCount('inventory_pools', 0);
        $this->putJson($root.'/room-types/'.$room.'/inventory-pool', ['version' => 0, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo'])->assertForbidden();
        $this->postJson($root.'/room-types/'.$room.'/rate-plans', array_replace($this->plan('USD'), ['meal_plan' => 'FB', 'status' => 'active']))->assertForbidden();
        $this->assertNull(app(ManualQuoteSource::class)->resolve(['hotel_id' => $hotel->id, 'rate_plan_id' => $ids[1], 'arrival' => '2026-10-10', 'departure' => '2026-10-11', 'adults' => 2], new DateTimeImmutable('2026-10-01')));
    }

    public function test_dual_currency_offers_use_explicit_money_and_one_shared_pool(): void
    {
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $hotel = Hotel::factory()->create(['discovery_slug' => 'currency-fixture', 'discovery_snapshot' => ['public' => ['name' => 'Synthetic']]]);
        $root = '/api/v1/hotels/'.$hotel->id;
        $room = $this->postJson($root.'/room-types', ['name' => 'Double', 'max_occupancy' => 2, 'status' => 'active'])->assertCreated()->json('data.id');
        $uri = $root.'/room-types/'.$room;
        $this->putJson($uri.'/inventory-pool', ['version' => 0, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo'])->assertOk();
        $this->putJson($uri.'/inventory-nights/2026-10-10', ['version' => 0, 'capacity' => 2])->assertOk();
        $usd = null;
        foreach (['RO', 'BB', 'HB', 'FB'] as $meal) {
            foreach (['LKR', 'USD'] as $currency) {
                $input = array_replace($this->plan($currency), ['meal_plan' => $meal, 'status' => 'active']);
                $plan = $this->postJson($uri.'/rate-plans', $input)->assertCreated()->json('data.id');
                if ($meal === 'BB') {
                    foreach (['2026-10-10', '2026-10-11'] as $date) {
                        $this->putJson($uri.'/rate-plans/'.$plan.'/nights/'.$date, $this->night($currency === 'USD' ? 12500 : 1000000))->assertOk();
                    }
                    if ($currency === 'USD') {
                        $usd = $plan;
                    }
                }
            }
        }
        $this->assertDatabaseCount('inventory_pools', 1);
        $this->assertDatabaseCount('inventory_nights', 1);
        $this->postJson($uri.'/rate-plans', array_replace($this->plan('USD'), ['status' => 'active']))->assertConflict();
        $this->putJson($uri.'/rate-plans/'.$usd, array_replace($this->plan('LKR'), ['version' => 1, 'status' => 'active']))->assertConflict();
        $this->travelTo(new \DateTime('2026-10-01T00:00:00Z'));
        $search = '/api/v1/public/hotels/currency-fixture/rate-plans?arrival=2026-10-10&departure=2026-10-11&adults=2';
        $this->getJson($search.'&currency=USD')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_minor', 12500)->assertJsonPath('data.0.currency', 'USD')->assertJsonPath('data.0.meal_plan', 'BB');
        $this->getJson($search)->assertOk()->assertJsonPath('data.0.currency', 'LKR')->assertJsonPath('data.0.total_minor', 1000000);
        $this->getJson($search.'&currency=EUR')->assertUnprocessable();
        $source = app(ManualQuoteSource::class)->resolve(['hotel_id' => $hotel->id, 'rate_plan_id' => $usd, 'arrival' => '2026-10-10', 'departure' => '2026-10-11', 'adults' => 2], new DateTimeImmutable('2026-10-01'));
        $this->assertSame('USD', $source['input']['currency']);
        $this->assertSame('BB', $source['input']['meal_plan']);
        DB::table('rate_plan_nights')->where('rate_plan_id', $usd)->delete();
        $this->getJson($search.'&currency=USD')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_room_creation_retries_and_explicit_conversion_preserve_identity_and_indicative_evidence(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $this->actingAs($admin);
        $hotel = Hotel::factory()->create();
        $uri = '/api/v1/hotels/'.$hotel->id;
        $input = ['client_key' => '965a1df4-496d-4de5-a11b-1d6d05908f55', 'name' => 'Double', 'max_occupancy' => 2, 'status' => 'draft'];
        $room = $this->postJson($uri.'/room-types', $input)->assertCreated()->json('data.id');
        $this->postJson($uri.'/room-types', $input)->assertOk()->assertJsonPath('data.id', $room);
        $this->postJson($uri.'/room-types', array_replace($input, ['name' => 'Different']))->assertConflict();
        $this->assertDatabaseCount('room_types', 1);
        $this->postJson($uri.'/catalog-conversion', ['onboarding_version' => 0])->assertConflict();
        $legacy = Hotel::factory()->create(['onboarding_data' => ['rooms' => [['name' => 'Legacy twin', 'occupancy' => 2, 'quantity' => 8, 'rate' => 12345]]]]);
        $legacyUri = '/api/v1/hotels/'.$legacy->id;
        $conversion = $this->postJson($legacyUri.'/catalog-conversion', ['onboarding_version' => 0])->assertCreated()->json('data');
        $this->postJson($legacyUri.'/catalog-conversion', ['onboarding_version' => 0])->assertOk()->assertJsonPath('data', $conversion);
        $this->getJson($legacyUri.'/onboarding')->assertOk()->assertJsonPath('version', 1)->assertJsonPath('catalog_conversion.rooms.0.room_type_id', $conversion['rooms'][0]['room_type_id']);
        $this->assertDatabaseCount('rate_plans', 0);
        $this->assertDatabaseCount('inventory_nights', 0);
        $source = json_decode(DB::table('catalog_conversions')->where('hotel_id', $legacy->id)->value('source_snapshot'), true);
        $this->assertSame(12345, $source[0]['rate']);
        $this->patchJson($legacyUri.'/onboarding', ['version' => 1, 'fields' => ['rooms' => []]])->assertConflict();
        $this->patchJson($legacyUri.'/onboarding', ['version' => 1, 'fields' => ['guest_rules' => 'Quiet hours']])->assertOk();
        $this->assertDatabaseCount('room_types', 2);
    }

    public function test_room_photo_ownership_reordering_stale_upload_and_removal(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $hotel = Hotel::factory()->create();
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $otherRoom = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $uri = '/api/v1/hotels/'.$hotel->id.'/room-types/'.$room->id.'/photos';
        $image = fn () => UploadedFile::fake()->createWithContent('room.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOZkAAAAASUVORK5CYII='));
        $first = $this->postJson($uri, ['version' => 1, 'caption' => 'Bed', 'photo' => $image()])->assertCreated()->json('data.photos.0.id');
        $second = $this->postJson($uri, ['version' => 2, 'caption' => 'Window', 'photo' => $image()])->assertCreated()->json('data.photos.1.id');
        $this->postJson($uri, ['version' => 2, 'caption' => 'Stale', 'photo' => $image()])->assertConflict();
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->putJson($uri, ['version' => 3, 'photo_ids' => [$second, $first]])->assertOk()->assertJsonPath('data.photos.0.id', $second)->assertJsonPath('data.version', 4);
        $this->getJson('/api/v1/hotels/'.$hotel->id.'/room-types/'.$otherRoom->id.'/photos/'.$first)->assertNotFound();
        $otherHotel = Hotel::factory()->create();
        $this->getJson('/api/v1/hotels/'.$otherHotel->id.'/room-types/'.$room->id.'/photos/'.$first)->assertNotFound();
        $this->deleteJson($uri.'/'.$first, ['version' => 4])->assertOk()->assertJsonCount(1, 'data.photos')->assertJsonPath('data.version', 5);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->getJson($uri.'/'.$first)->assertNotFound();
        $viewer = User::factory()->create();
        $hotel->users()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->deleteJson($uri.'/'.$second, ['version' => 5])->assertForbidden();
    }

    private function plan(string $currency): array
    {
        return ['name' => 'Breakfast', 'meal_plan' => 'BB', 'currency' => $currency, 'status' => 'draft', 'policy' => ['version' => '1', 'text' => 'Synthetic policy.']];
    }

    private function night(int $amount): array
    {
        return ['version' => 0, 'base_minor' => $amount, 'tax_minor' => 0, 'fee_minor' => 0, 'mandatory_charges_complete' => true, 'stop_sell' => false, 'min_stay' => 1, 'max_stay' => 30, 'closed_to_arrival' => false, 'closed_to_departure' => false];
    }
}
