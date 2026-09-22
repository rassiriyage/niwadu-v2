<?php

namespace App\Http\Controllers;

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

    public function destroy(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
