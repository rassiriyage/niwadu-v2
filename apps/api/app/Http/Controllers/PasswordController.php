<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\PasswordBytes;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12), new PasswordBytes],
        ]);
        $data['email'] = Str::lower($data['email']);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->password = $password;
            $user->remember_token = Str::random(60);
            $user->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => 'This password link is invalid or expired.']);
        }

        return response()->json(['message' => 'Password saved. You can now sign in.']);
    }
}
