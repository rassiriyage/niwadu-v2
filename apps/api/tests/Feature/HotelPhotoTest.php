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

    public function test_active_content_uploads_are_rejected(): void
    {
        Storage::fake('local');
        $hotel = Hotel::factory()->create();
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']))->postJson('/api/v1/hotels/'.$hotel->id.'/photos', ['photo' => UploadedFile::fake()->createWithContent('hotel.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'caption' => 'Hotel'])->assertUnprocessable();
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}
