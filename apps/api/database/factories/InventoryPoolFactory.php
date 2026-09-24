<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryPool>
 */
class InventoryPoolFactory extends Factory
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
            'owner' => 'unconfigured', 'sales_state' => 'closed', 'version' => 1, 'ownership_version' => 1,
        ];
    }
}
