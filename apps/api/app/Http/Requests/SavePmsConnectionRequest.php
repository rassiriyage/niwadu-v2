<?php

namespace App\Http\Requests;

use App\Services\PmsProviderCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePmsConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->platform_role === 'administrator';
    }

    public function rules(): array
    {
        $providers = array_column(app(PmsProviderCatalog::class)->all(), 'provider');
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $rules = [
            'provider' => [$required, 'string', Rule::in($providers)],
            'environment' => [$required, 'string', Rule::in(['sandbox', 'production'])],
            'endpoint_origin' => [$required, 'string', 'url:http,https', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                $parts = parse_url((string) $value);
                if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    $fail('The endpoint must be an HTTPS origin without credentials, path, query, or fragment.');

                    return;
                }
                if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
                    $fail('The endpoint must be an origin; provider paths are fixed by the adapter.');
                }
                $host = strtolower((string) ($parts['host'] ?? ''));
                $blockedSuffixes = ['.internal', '.local', '.localhost', '.lan', '.localdomain'];
                $isBlockedHostname = $host === 'localhost' || $host === 'metadata.google.internal';
                foreach ($blockedSuffixes as $suffix) {
                    $isBlockedHostname = $isBlockedHostname || str_ends_with($host, $suffix);
                }
                if ($host === '' || $isBlockedHostname || filter_var($host, FILTER_VALIDATE_IP)) {
                    $fail('The endpoint host is not allowed.');
                }
            }],
            'external_property_id' => [$required, 'string', 'max:255'],
            'credentials' => [$required, 'array', 'min:1'],
            'credentials.*' => ['required', 'string', 'max:4096'],
            'inventory_mode' => [$required, Rule::in(['pms'])],
            'enabled' => ['sometimes', 'boolean'],
        ];
        if ($this->isMethod('PATCH')) {
            $rules['provider'] = ['prohibited'];
            $rules['external_property_id'] = ['prohibited'];
            $rules['inventory_mode'] = ['prohibited'];
            $rules['credentials'] = ['sometimes', 'array', 'min:1'];
            $rules['credentials.*'] = ['required', 'string', 'max:4096'];
        }
        foreach (array_diff(array_keys($this->all()), array_keys($rules), ['_token']) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
