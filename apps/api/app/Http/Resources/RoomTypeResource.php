<?php

namespace App\Http\Resources;

use App\Models\HotelPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'hotel_id' => $this->hotel_id, 'name' => $this->name,
            'max_occupancy' => $this->max_occupancy, 'status' => $this->status, 'version' => $this->version,
            'photos' => $this->photos->map(fn (HotelPhoto $photo) => [
                'id' => $photo->id, 'caption' => $photo->caption, 'position' => (int) $photo->pivot->position,
                'url' => route('hotel-photos.show', ['hotel' => $this->hotel_id, 'photo' => $photo->id], false),
            ])->all(),
        ];
    }
}
