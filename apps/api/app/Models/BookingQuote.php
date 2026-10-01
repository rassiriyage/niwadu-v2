<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class BookingQuote extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_revision' => 'array', 'room_type_id' => 'integer', 'rate_plan_id' => 'integer', 'snapshot' => 'array', 'expires_at' => 'immutable_datetime', 'user_id' => 'integer', 'hotel_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A booking quote is immutable; request a new quote.');
        });
    }
}
