<?php

namespace App\Models\Concerns;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasAssignedUserScope
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleFor(Builder $query, User $user): Builder
    {
        if ($user->isAdministrador()) {
            return $query;
        }

        if ($user->isSupervisor()) {
            return $query->where(function (Builder $query) use ($user): void {
                $query
                    ->where('assigned_user_id', $user->getKey())
                    ->orWhereIn(
                        'assigned_user_id',
                        User::query()
                            ->select('id')
                            ->where('supervisor_user_id', $user->getKey())
                            ->where('status', UserStatus::Active->value),
                    );
            });
        }

        return $query->where('assigned_user_id', $user->getKey());
    }
}
