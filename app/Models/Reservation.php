<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lead_id',
    'property_id',
    'user_id',
    'reserved_at',
    'expires_at',
    'status',
    'idempotency_key',
])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
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

            return $query->whereIn('user_id', $teamIds);
        }

        return $query->where('user_id', $user->getKey());
    }

    public function statusLabel(): string
    {
        return ReservationStatus::tryFrom($this->status)?->label() ?? $this->status;
    }
}
