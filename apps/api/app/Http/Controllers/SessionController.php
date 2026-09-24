<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['csrf_token' => csrf_token(), 'user' => $user ? [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'platform_role' => $user->platform_role,
        ] : null]);
    }

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:1024']]);
        $credentials['email'] = Str::lower($credentials['email']);
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        $request->session()->regenerate();

        return $this->show($request);
    }

    public function register(Request $request): JsonResponse
    {
        abort_if($request->user() !== null, 409, 'Sign out before registering another account.');
        if (array_diff(array_keys($request->all()), ['name', 'email', 'password', 'password_confirmation', '_token'])) {
            throw ValidationException::withMessages(['account' => 'Only name, email and password fields are allowed.']);
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'not_regex:/\x00/', 'confirmed'],
        ]);
        try {
            $user = new User($data);
            $user->platform_role = null;
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return $this->show($request)->setStatusCode(201);
    }

    public function destroy(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
