<?php

namespace Database\Factories;

use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomTypeFactory extends Factory
{
    public function definition(): array
    {
        return ['hotel_id' => Hotel::factory(), 'name' => 'Double room', 'max_occupancy' => 2, 'status' => 'draft', 'version' => 1];
    }
}
