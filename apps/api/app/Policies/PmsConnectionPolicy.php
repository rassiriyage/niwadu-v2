<?php

namespace App\Policies;

use App\Models\PmsConnection;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PmsConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->platform_role === 'administrator';
    }

    public function view(User $user, PmsConnection $connection): Response
    {
        return $this->viewAny($user) && $connection->hotel !== null
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, PmsConnection $connection): Response
    {
        return $this->view($user, $connection);
    }
}
