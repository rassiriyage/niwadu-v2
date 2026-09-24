<?php

namespace App\Policies;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class HotelPolicy
{
    public function manageInventory(User $user, Hotel $hotel): bool
    {
        return $user->platform_role === 'administrator'
            || $hotel->users()->where('users.id', $user->id)->wherePivotIn('role', ['hotel_manager', 'inventory_manager'])->exists();
    }

    public function releaseDiscovery(User $user, Hotel $hotel): bool
    {
        return $user->platform_role === 'administrator';
    }

    public function onboard(User $user, Hotel $hotel): bool
    {
        return $hotel->status === 'draft' && $this->manageStaff($user, $hotel);
    }

    public function create(User $user): bool
    {
        return in_array($user->platform_role, ['administrator', 'onboarding'], true);
    }

    public function view(User $user, Hotel $hotel): Response
    {
        return Hotel::visibleTo($user)->whereKey($hotel->id)->exists()
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Hotel $hotel): bool
    {
        return $this->manageStaff($user, $hotel)
            || $hotel->users()->where('users.id', $user->id)->wherePivot('role', 'hotel_manager')->exists();
    }

    public function manageStaff(User $user, Hotel $hotel): bool
    {
        return $user->platform_role === 'administrator' || $hotel->isOwnedDraft($user);
    }

    public function viewStaff(User $user, Hotel $hotel): bool
    {
        return $this->update($user, $hotel);
    }
}
