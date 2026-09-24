<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RatePlan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['policy' => 'array', 'version' => 'integer', 'hotel_id' => 'integer', 'room_type_id' => 'integer'];
    }
}
