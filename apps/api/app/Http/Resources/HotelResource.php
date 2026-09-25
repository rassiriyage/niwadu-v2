<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class HotelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'version' => $this->onboarding_version, 'name' => $this->name, 'city' => $this->city, 'country' => $this->country,
            'address' => $this->address, 'description' => $this->description,
            'contact_email' => $this->contact_email, 'phone' => $this->phone, 'status' => $this->status,
            'permissions' => [
                'manage_inventory' => Gate::allows('manageInventory', $this->resource),
                'manage_pms' => $request->user()?->platform_role === 'administrator',
                'author_rates' => Gate::allows('authorRates', $this->resource),
                'edit_profile' => Gate::allows('update', $this->resource),
                'view_staff' => Gate::allows('viewStaff', $this->resource),
                'manage_staff' => Gate::allows('manageStaff', $this->resource),
            ],
        ];
    }
}
