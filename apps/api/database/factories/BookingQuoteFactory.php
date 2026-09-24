<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingQuoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'hotel_id' => Hotel::factory()->state(['status' => 'published']),
            'source' => 'fixture', 'snapshot' => ['total_minor' => 11800, 'currency' => 'LKR'],
            'expires_at' => now()->addMinutes(5),
        ];
    }
}
