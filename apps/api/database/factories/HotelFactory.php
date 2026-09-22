<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HotelFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company().' Hotel', 'city' => 'Galle', 'country' => 'LK', 'status' => 'draft', 'created_by' => User::factory()];
    }
}
