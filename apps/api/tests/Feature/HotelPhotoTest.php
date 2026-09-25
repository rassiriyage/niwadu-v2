<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HotelPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_photos_are_private_scoped_and_can_be_removed(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $image = UploadedFile::fake()->createWithContent('hotel.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOZkAAAAASUVORK5CYII='));
        $url = '/api/v1/hotels/'.$hotel->id.'/photos';
        $response = $this->actingAs($admin)->postJson($url, ['photo' => $image, 'caption' => 'Pool at sunset'])->assertCreated();
        $id = $response->json('data.id');
        $this->get('/api/v1/hotels/'.$other->id.'/photos/'.$id, ['Accept' => 'application/json'])->assertNotFound();
        $this->get($url.'/'.$id)->assertOk()->assertHeader('Content-Type', 'image/png');
        $manager = User::factory()->create();
        $hotel->users()->attach($manager, ['role' => 'hotel_manager']);
        $this->actingAs($manager)->deleteJson($url.'/'.$id)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url.'/'.$id, ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($admin)->deleteJson($url.'/'.$id)->assertNoContent();
        $this->getJson($url)->assertJsonCount(0, 'data');
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_property_and_two_room_galleries_keep_independent_versioned_orders(): void
    {
        Storage::fake('local');
        $hotel = Hotel::factory()->create();
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $root = '/api/v1/hotels/'.$hotel->id;
        $image = fn () => UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOZkAAAAASUVORK5CYII='));
        $this->getJson($root.'/photos')->assertJsonPath('meta.version', 0);
        $property = [];
        foreach ([0, 1] as $version) {
            $property[] = $this->postJson($root.'/photos', ['version' => $version, 'caption' => 'Property', 'photo' => $image()])->assertCreated()->assertJsonPath('meta.version', $version + 1)->json('data.id');
        }
        $rooms = [];
        foreach (['Double', 'Twin'] as $name) {
            $room = $this->postJson($root.'/room-types', ['name' => $name, 'max_occupancy' => 2, 'status' => 'draft'])->assertCreated()->json('data.id');
            $uri = $root.'/room-types/'.$room.'/photos';
            $this->postJson($uri, ['version' => 1, 'caption' => 'Bed', 'photo' => $image()])->assertCreated();
            $ids = array_column($this->postJson($uri, ['version' => 2, 'caption' => 'Window', 'photo' => $image()])->assertCreated()->json('data.photos'), 'id');
            $rooms[] = [$uri, array_reverse($ids)];
        }
        $this->putJson($root.'/photos', ['version' => 2, 'photo_ids' => array_reverse($property)])->assertOk()->assertJsonPath('meta.version', 3);
        foreach ($rooms as [$uri, $ids]) {
            $this->putJson($uri, ['version' => 3, 'photo_ids' => $ids])->assertOk();
            $this->assertSame($ids, array_column($this->getJson($uri)->assertOk()->json('data.photos'), 'id'));
        }
        $this->assertSame(array_reverse($property), array_column($this->getJson($root.'/photos')->assertOk()->json('data'), 'id'));
        foreach ([[$property[0], $property[0]], [$property[0]], [$property[0], $rooms[0][1][0]]] as $ids) {
            $this->putJson($root.'/photos', ['version' => 3, 'photo_ids' => $ids])->assertUnprocessable();
        }
        $this->putJson($rooms[0][0], ['version' => 4, 'photo_ids' => $rooms[1][1]])->assertUnprocessable();
        $this->putJson($rooms[0][0], ['version' => 4, 'photo_ids' => $property])->assertUnprocessable();
        $this->putJson($root.'/photos', ['version' => 3, 'photo_ids' => $property, 'gallery' => 'room'])->assertUnprocessable();
        $this->putJson($root.'/photos', ['version' => 2, 'photo_ids' => $property])->assertConflict();
        $this->postJson($root.'/photos', ['version' => 2, 'caption' => 'Stale', 'photo' => $image()])->assertConflict();
        $this->deleteJson($root.'/photos/'.$property[0], ['version' => 2])->assertConflict();
        $this->assertCount(6, Storage::disk('local')->allFiles());
        $this->deleteJson($root.'/photos/'.$property[0], ['version' => 3])->assertNoContent();
        $this->getJson($root.'/photos')->assertJsonPath('meta.version', 4)->assertJsonPath('data.0.id', $property[1])->assertJsonPath('data.0.position', 0);
        $this->assertSame(0, $hotel->fresh()->onboarding_version);
        $this->actingAs(User::factory()->create())->putJson($root.'/photos', ['version' => 4, 'photo_ids' => [$property[1]]])->assertNotFound();
    }

    public function test_active_content_uploads_are_rejected(): void
    {
        Storage::fake('local');
        $hotel = Hotel::factory()->create();
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']))->postJson('/api/v1/hotels/'.$hotel->id.'/photos', ['photo' => UploadedFile::fake()->createWithContent('hotel.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'caption' => 'Hotel'])->assertUnprocessable();
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}
