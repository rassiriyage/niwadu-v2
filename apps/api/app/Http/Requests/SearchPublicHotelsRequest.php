<?php

namespace App\Http\Requests;

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
        $types = 0;
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = urldecode(explode('=', $pair, 2)[0]);
            if ($key === 'property_types[]') {
                if (++$types > 6) {
                    throw ValidationException::withMessages(['property_types' => 'Choose at most six property types.']);
                }

                continue;
            }
            if (! in_array($key, ['q', 'sort', 'page'], true) || isset($seen[$key])) {
                throw ValidationException::withMessages(['query' => 'Unsupported or repeated search parameter.']);
            }
            $seen[$key] = true;
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'required', 'string', 'max:100'],
            'sort' => ['required', Rule::in(['name'])],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
            'property_types' => ['sometimes', 'array', 'max:6'],
            'property_types.*' => ['required', 'string', Rule::in(array_keys(config('catalog.property_types')))],
        ];
    }
}
