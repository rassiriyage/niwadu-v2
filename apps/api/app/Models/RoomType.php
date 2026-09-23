<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'max_occupancy'])]
class RoomType extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['hotel_id' => 'integer', 'max_occupancy' => 'integer', 'version' => 'integer'];
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(HotelPhoto::class, 'room_type_photos')
            ->withPivot('hotel_id', 'position')->orderByPivot('position');
    }
}
