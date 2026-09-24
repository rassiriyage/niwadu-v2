<?php

namespace App\Http\Requests;

use App\Models\Hotel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SearchPublicHotelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = (string) $this->server('QUERY_STRING', '');
        if (strlen($raw) > 4096) {
            throw ValidationException::withMessages(['query' => 'The search query is too long.']);
        }
        $seen = [];
        $arrays = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = urldecode(explode('=', $pair, 2)[0]);
            if (in_array($key, ['property_types[]', 'themes[]', 'amenities[]'], true)) {
                $field = substr($key, 0, -2);
                $arrays[$field] = ($arrays[$field] ?? 0) + 1;
                if ($arrays[$field] > count(config('catalog.'.$field))) {
                    throw ValidationException::withMessages([$field => 'Too many selected values.']);
                }

                continue;
            }
            if (! in_array($key, ['q', 'sort', 'page', 'destination', 'district', 'region'], true) || isset($seen[$key])) {
                throw ValidationException::withMessages(['query' => 'Unsupported or repeated search parameter.']);
            }
            $seen[$key] = true;
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'required', 'string', 'max:100'],
            'sort' => ['required', Rule::in(Hotel::whereNotNull('discovery_snapshot->public->editorial_rank')->exists() ? ['name', 'editorial'] : ['name'])],
            'destination' => ['sometimes', 'required', 'string', 'max:120', 'exists:catalog_destinations,slug'],
            'district' => ['sometimes', 'required', Rule::in(array_keys(config('catalog.districts')))],
            'region' => ['sometimes', 'required', Rule::in(['north-east'])],
            'themes' => ['sometimes', 'array', 'max:6'],
            'themes.*' => ['required', 'string', Rule::in(array_keys(config('catalog.themes')))],
            'amenities' => ['sometimes', 'array', 'max:8'],
            'amenities.*' => ['required', 'string', Rule::in(array_keys(config('catalog.amenities')))],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
            'property_types' => ['sometimes', 'array', 'max:6'],
            'property_types.*' => ['required', 'string', Rule::in(array_keys(config('catalog.property_types')))],
        ];
    }
}
