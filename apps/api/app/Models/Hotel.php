<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'city', 'country', 'address', 'description', 'contact_email', 'phone'])]
class Hotel extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['onboarding_data' => 'array', 'onboarding_version' => 'integer', 'onboarding_step' => 'integer', 'created_by' => 'integer',
            'discovery_snapshot' => 'array', 'discovery_version' => 'integer', 'discovery_approved_at' => 'datetime'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->platform_role === 'administrator') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->whereHas('users', fn (Builder $members) => $members->where('users.id', $user->id));
            if ($user->platform_role === 'onboarding') {
                $query->orWhere(fn (Builder $drafts) => $drafts->where('created_by', $user->id)->where('status', 'draft'));
            }
        });
    }

    public function isOwnedDraft(User $user): bool
    {
        return $user->platform_role === 'onboarding' && $this->created_by === $user->id && $this->status === 'draft';
    }

    public function recordAccessEvent(User $actor, string $action, ?User $subject = null, ?string $role = null): void
    {
        DB::table('hotel_access_events')->insert([
            'hotel_id' => $this->id, 'actor_id' => $actor->id, 'subject_id' => $subject?->id,
            'action' => $action, 'role' => $role, 'created_at' => now(),
        ]);
    }
}
