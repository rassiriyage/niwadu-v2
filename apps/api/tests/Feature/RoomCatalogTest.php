<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\HotelPhoto;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoomCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_reads_require_authentication(): void
    {
        $hotel = Hotel::factory()->create();
        $this->getJson("/api/v1/hotels/{$hotel->id}/room-types")->assertUnauthorized();
        $this->getJson("/api/v1/hotels/{$hotel->id}/room-types/1")->assertUnauthorized();
    }

    public function test_list_is_paginated_scoped_and_returns_ordered_private_photo_links(): void
    {
        $hotel = Hotel::factory()->create();
        $rooms = RoomType::factory()->count(2)->create(['hotel_id' => $hotel->id]);
        RoomType::factory()->create();
        $photos = HotelPhoto::factory()->count(2)->create(['hotel_id' => $hotel->id]);
        foreach ($photos as $index => $photo) {
            $rooms[0]->photos()->attach($photo, ['hotel_id' => $hotel->id, 'position' => 1 - $index]);
        }
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $response = $this->getJson("/api/v1/hotels/{$hotel->id}/room-types?per_page=1");
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $rooms[0]->id)->assertJsonPath('data.0.hotel_id', $hotel->id)
            ->assertJsonPath('data.0.status', 'draft')->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.0.photos.0.id', $photos[1]->id)->assertJsonPath('data.0.photos.0.position', 0)
            ->assertJsonPath('data.0.photos.0.url', "/api/v1/hotels/{$hotel->id}/photos/{$photos[1]->id}")
            ->assertJsonMissingPath('data.0.photos.0.path');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->getJson("/api/v1/hotels/{$hotel->id}/room-types?per_page=1&page=2")->assertJsonPath('data.0.id', $rooms[1]->id);
        $this->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$rooms[0]->id}")->assertOk()->assertJsonPath('data', $response->json('data.0'));
        foreach (['per_page=0', 'per_page=51', 'page=0', 'per_page=abc'] as $query) {
            $this->getJson("/api/v1/hotels/{$hotel->id}/room-types?{$query}")->assertUnprocessable();
        }
    }

    public function test_all_hotel_roles_can_read_but_foreign_and_revoked_memberships_cannot(): void
    {
        $room = RoomType::factory()->create();
        $hotel = Hotel::findOrFail($room->hotel_id);
        $foreignRoom = RoomType::factory()->create();
        foreach (['hotel_manager', 'reservations', 'inventory_manager', 'viewer'] as $role) {
            $user = User::factory()->create();
            $hotel->users()->attach($user, ['role' => $role]);
            $this->actingAs($user)->getJson("/api/v1/hotels/{$hotel->id}/room-types")->assertOk()->assertJsonCount(1, 'data');
            $this->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$room->id}")->assertOk();
            $this->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$foreignRoom->id}")->assertNotFound();
            $this->getJson("/api/v1/hotels/{$foreignRoom->hotel_id}/room-types")->assertNotFound();
            $this->getJson("/api/v1/hotels/{$foreignRoom->hotel_id}/room-types/{$foreignRoom->id}")->assertNotFound();
            $hotel->users()->detach($user);
            $this->getJson("/api/v1/hotels/{$hotel->id}/room-types")->assertNotFound();
            $this->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$room->id}")->assertNotFound();
        }
    }

    public function test_employee_reads_only_owned_drafts_and_admin_cannot_mix_nested_hotel_ids(): void
    {
        $employee = User::factory()->create(['platform_role' => 'onboarding']);
        $hotel = Hotel::factory()->create(['created_by' => $employee->id]);
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $other = RoomType::factory()->create();
        $this->actingAs($employee)->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$room->id}")->assertOk();
        $this->getJson("/api/v1/hotels/{$other->hotel_id}/room-types")->assertNotFound();
        $hotel->status = 'published';
        $hotel->save();
        $this->getJson("/api/v1/hotels/{$hotel->id}/room-types")->assertNotFound();
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']))->getJson("/api/v1/hotels/{$hotel->id}/room-types/{$other->id}")->assertNotFound();
    }

    public function test_photo_association_rejects_foreign_photo_at_database_boundary(): void
    {
        $room = RoomType::factory()->create();
        $photo = HotelPhoto::factory()->create();
        $this->expectException(QueryException::class);
        $room->photos()->attach($photo, ['hotel_id' => $room->hotel_id, 'position' => 0]);
    }

    public function test_photo_association_rejects_foreign_room_at_database_boundary(): void
    {
        $room = RoomType::factory()->create();
        $photo = HotelPhoto::factory()->create();
        $this->expectException(QueryException::class);
        $room->photos()->attach($photo, ['hotel_id' => $photo->hotel_id, 'position' => 0]);
    }

    public function test_positions_are_unique_and_a_photo_can_serve_two_room_types(): void
    {
        $room = RoomType::factory()->create();
        $other = RoomType::factory()->create(['hotel_id' => $room->hotel_id]);
        $photos = HotelPhoto::factory()->count(2)->create(['hotel_id' => $room->hotel_id]);
        $room->photos()->attach($photos[0], ['hotel_id' => $room->hotel_id, 'position' => 0]);
        $other->photos()->attach($photos[0], ['hotel_id' => $room->hotel_id, 'position' => 0]);
        $this->assertSame(2, DB::table('room_type_photos')->count());
        $this->expectException(QueryException::class);
        $room->photos()->attach($photos[1], ['hotel_id' => $room->hotel_id, 'position' => 0]);
    }

    public function test_photo_deletion_removes_associations_without_deleting_room(): void
    {
        $room = RoomType::factory()->create();
        $photo = HotelPhoto::factory()->create(['hotel_id' => $room->hotel_id]);
        $room->photos()->attach($photo, ['hotel_id' => $room->hotel_id, 'position' => 0]);
        $photo->delete();
        $this->assertDatabaseCount('room_type_photos', 0);
        $this->assertDatabaseHas('room_types', ['id' => $room->id]);
    }

    public function test_drafts_remain_editable_and_reads_do_not_convert_or_publish_them(): void
    {
        $hotel = Hotel::factory()->create();
        $url = "/api/v1/hotels/{$hotel->id}";
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $this->patchJson($url.'/onboarding', ['version' => 0, 'fields' => ['rooms' => [
            ['name' => 'Draft room', 'occupancy' => 2, 'quantity' => 5, 'rate' => 10000],
        ]]])->assertOk()->assertJsonPath('can_publish', false);
        $this->getJson($url.'/room-types')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($url.'/room-types', ['name' => 'Room'])->assertUnprocessable();
        $this->assertDatabaseCount('room_types', 0);
        $this->postJson($url.'/catalog-conversion')->assertNotFound();
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $this->patchJson($url.'/room-types/'.$room->id, ['name' => 'Changed'])->assertMethodNotAllowed();
        $this->deleteJson($url.'/room-types/'.$room->id)->assertMethodNotAllowed();
        $this->patchJson($url.'/onboarding', ['version' => 1, 'fields' => ['rooms' => []]])->assertOk();
        $this->assertSame('draft', $hotel->fresh()->status);
    }
}
