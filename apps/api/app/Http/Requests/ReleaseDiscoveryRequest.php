<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReleaseDiscoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('hotel'));

        return Gate::allows('releaseDiscovery', $this->route('hotel'));
    }

    public function rules(): array
    {
        $rules = [
            'onboarding_version' => ['required', 'integer', 'min:0'],
            'discovery_version' => ['required', 'integer', 'min:0'],
            'slug' => ['required', 'string', 'max:120', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
        ];
        foreach (array_diff(array_keys($this->all()), array_merge(array_keys($rules), ['_token'])) as $key) {
            $rules[$key] = ['prohibited'];
        }

        return $rules;
    }
}
