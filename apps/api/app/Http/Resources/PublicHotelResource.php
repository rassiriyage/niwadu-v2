<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicHotelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $public = $this->discovery_snapshot['public'];

        return [
            'id' => $this->id, 'slug' => $this->discovery_slug,
            'name' => $public['name'], 'description' => $public['description'],
            'property_type' => $public['property_type'], 'city' => $public['city'], 'country' => $public['country'],
            'photo' => null,
        ];
    }
}
