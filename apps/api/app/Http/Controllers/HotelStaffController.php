<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveHotelStaffRequest;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HotelStaffController extends Controller
{
    public function index(Hotel $hotel): JsonResponse
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('viewStaff', $hotel);

        return response()->json(['data' => $hotel->users()->orderBy('name')->get()->map(fn (User $user) => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->pivot->role,
        ])]);
    }

    public function store(SaveHotelStaffRequest $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validated();
        $user = DB::transaction(function () use ($request, $hotel, $data) {
            $user = User::firstOrCreate(['email' => Str::lower($data['email'])], ['name' => $data['name'], 'password' => Str::random(64)]);
            if ($user->platform_role !== null) {
                throw ValidationException::withMessages(['email' => 'Platform employees cannot be assigned a hotel staff role.']);
            }
            $hotel->users()->syncWithoutDetaching([$user->id => ['role' => $data['role']]]);
            $hotel->recordAccessEvent($request->user(), 'staff.granted', $user, $data['role']);

            return $user;
        });
        $delivery = null;
        if ($user->wasRecentlyCreated) {
            $delivery = Password::sendResetLink(['email' => $user->email]);
        }

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $data['role']], 'password_setup_sent' => $delivery === Password::ResetLinkSent], 201);
    }

    public function passwordLink(Request $request, Hotel $hotel, int $user): JsonResponse
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('manageStaff', $hotel);
        $member = $hotel->users()->where('users.id', $user)->firstOrFail();
        $status = Password::sendResetLink(['email' => $member->email]);
        if ($status !== Password::ResetLinkSent) {
            return response()->json(['message' => __($status)], 429);
        }
        $hotel->recordAccessEvent($request->user(), 'staff.password_link_sent', $member);

        return response()->json(['password_setup_sent' => true]);
    }

    public function destroy(Request $request, Hotel $hotel, int $user): Response
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('manageStaff', $hotel);
        DB::transaction(function () use ($request, $hotel, $user) {
            $member = $hotel->users()->where('users.id', $user)->firstOrFail();
            $hotel->users()->detach($member->id);
            $hotel->recordAccessEvent($request->user(), 'staff.revoked', $member, $member->pivot->role);
        });

        return response()->noContent();
    }
}
