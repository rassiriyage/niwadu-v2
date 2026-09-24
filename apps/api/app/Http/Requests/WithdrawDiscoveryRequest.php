<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class WithdrawDiscoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('hotel'));

        return Gate::allows('releaseDiscovery', $this->route('hotel'));
    }

    public function rules(): array
    {
        $rules = ['discovery_version' => ['required', 'integer', 'min:0']];
        foreach (array_diff(array_keys($this->all()), ['discovery_version', '_token']) as $key) {
            $rules[$key] = ['prohibited'];
        }

        return $rules;
    }
}
