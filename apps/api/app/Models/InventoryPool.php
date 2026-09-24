<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryPool extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['version' => 'integer', 'ownership_version' => 'integer', 'hotel_id' => 'integer', 'room_type_id' => 'integer'];
    }
}
