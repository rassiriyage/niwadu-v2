<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveHotelStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('hotel'));

        return Gate::allows('manageStaff', $this->route('hotel'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(User::HOTEL_ROLES)],
            'platform_role' => ['prohibited'], 'password' => ['prohibited'],
        ];
    }
}
