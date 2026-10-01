<?php

namespace Database\Factories;

use App\Models\BookingQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingIntentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_quote_id' => BookingQuote::factory(),
            'user_id' => fn (array $attributes): int => BookingQuote::findOrFail($attributes['booking_quote_id'])->user_id,
            'hotel_id' => fn (array $attributes): int => BookingQuote::findOrFail($attributes['booking_quote_id'])->hotel_id,
            'idempotency_key' => fake()->uuid(),
            'request_hash' => fn (array $attributes): string => hash('sha256', $attributes['booking_quote_id']),
        ];
    }
}
