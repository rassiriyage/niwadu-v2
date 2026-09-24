<?php

namespace App\Http\Resources;

use App\Models\PmsConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PmsConnection */
class PmsConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'provider' => $this->provider,
            'environment' => $this->environment,
            'endpoint_origin' => $this->endpoint_origin,
            'external_property_id' => $this->external_property_id,
            'inventory_mode' => $this->inventory_mode,
            'enabled' => $this->enabled,
            'credentials_configured' => is_array($this->credentials) && $this->credentials !== [],
            'capabilities' => $this->capabilities,
            'freshness' => $this->freshness,
            'last_verified_at' => $this->last_verified_at?->toISOString(),
            'last_sync_at' => $this->last_sync_at?->toISOString(),
            'last_error' => $this->last_error,
        ];
    }
}
