<?php

namespace Database\Factories;

use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

class HotelPhotoFactory extends Factory
{
    public function definition(): array
    {
        return ['hotel_id' => Hotel::factory(), 'path' => 'hotel-photos/'.fake()->uuid().'.jpg', 'mime_type' => 'image/jpeg', 'caption' => 'Room balcony'];
    }
}
