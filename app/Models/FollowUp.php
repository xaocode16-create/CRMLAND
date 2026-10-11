<?php

namespace App\Models;

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use Database\Factories\FollowUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $lead_id
 * @property int|null $assigned_user_id
 * @property string $status
 * @property string $priority
 * @property Carbon $scheduled_at
 */
#[Fillable([
    'lead_id',
    'assigned_user_id',
    'type',
    'scheduled_at',
    'note',
    'status',
    'priority',
    'completed_at',
    'completed_by_user_id',
])]
class FollowUp extends Model
{
    /** @use HasFactory<FollowUpFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'PENDING',
        'priority' => 'NORMAL',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * @param  Builder<FollowUp>  $query
     * @return Builder<FollowUp>
     */
    public function scopeVisibleFor(Builder $query, User $user): Builder
    {
        if ($user->isAdministrador()) {
            return $query;
        }

        if ($user->isSupervisor()) {
            $teamIds = User::query()
                ->where('supervisor_user_id', $user->getKey())
                ->pluck('id')
                ->push($user->getKey())
                ->all();

            return $query->whereIn('assigned_user_id', $teamIds);
        }

        return $query->where('assigned_user_id', $user->getKey());
    }

    public function statusLabel(): string
    {
        return FollowUpStatus::tryFrom($this->status)?->label() ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return FollowUpPriority::tryFrom($this->priority)?->label() ?? $this->priority;
    }

    public function typeLabel(): string
    {
        return FollowUpType::tryFrom($this->type)?->label() ?? $this->type;
    }
}
