<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatePlan>
 */
class RatePlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'room_type_id' => fn (array $attributes) => RoomType::factory()->create(['hotel_id' => $attributes['hotel_id']])->id,
            'name' => 'Synthetic plan', 'status' => 'draft', 'currency' => 'LKR',
            'policy' => ['version' => '1', 'text' => 'Synthetic cancellation policy.'], 'version' => 1,
        ];
    }
}
