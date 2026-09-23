<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('hotel'));

        return Gate::allows('onboard', $this->route('hotel'));
    }

    public function rules(): array
    {
        $rules = [
            'version' => ['required', 'integer', 'min:0'], 'step' => ['sometimes', 'integer', 'between:1,7'],
            'fields' => ['sometimes', 'array:name,city,country,address,contact_email,phone,description,property_type,amenities,rooms,inventory_request,check_in,check_out,cancellation_policy,guest_rules'],
            'fields.name' => ['sometimes', 'required', 'string', 'max:255'],
            'fields.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fields.country' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{2}$/'],
            'fields.address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'fields.contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'fields.phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'fields.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'fields.property_type' => ['sometimes', 'nullable', Rule::in(['hotel', 'villa', 'guest_house', 'resort', 'apartment', 'hostel'])],
            'fields.amenities' => ['sometimes', 'array', 'max:12'],
            'fields.amenities.*' => ['string', 'distinct', Rule::in(['wifi', 'parking', 'pool', 'restaurant', 'air_conditioning', 'beach_access', 'airport_transfer', 'accessible_rooms'])],
            'fields.rooms' => ['sometimes', 'array', 'max:50'],
            'fields.rooms.*' => ['array:name,occupancy,quantity,rate'],
            'fields.rooms.*.name' => ['nullable', 'string', 'max:255'],
            'fields.rooms.*.occupancy' => ['nullable', 'integer', 'between:1,30'],
            'fields.rooms.*.quantity' => ['nullable', 'integer', 'between:1,10000'],
            'fields.rooms.*.rate' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'fields.inventory_request' => ['sometimes', 'nullable', Rule::in(['manual', 'pms', 'undecided'])],
            'fields.check_in' => ['sometimes', 'nullable', 'date_format:H:i'],
            'fields.check_out' => ['sometimes', 'nullable', 'date_format:H:i'],
            'fields.cancellation_policy' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'fields.guest_rules' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
        foreach (array_diff(array_keys($this->all()), ['version', 'step', 'fields', '_token']) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
