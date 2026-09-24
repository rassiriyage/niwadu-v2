<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordBytes implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > 72) {
            $fail('The password must be at most 72 UTF-8 bytes. Some characters use more than one byte.');
        }
        if (is_string($value) && str_contains($value, "\0")) {
            $fail('The password must not contain null characters.');
        }
    }
}
