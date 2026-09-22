<?php

namespace App\Http\Requests;

use App\Models\Hotel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveHotelRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($hotel = $this->route('hotel')) {
            Gate::authorize('view', $hotel);

            return Gate::allows('update', $hotel);
        }

        return Gate::allows('create', Hotel::class);
    }

    public function rules(): array
    {
        $rules = [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
        foreach (array_diff(array_keys($this->all()), array_keys($rules), ['_token']) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
