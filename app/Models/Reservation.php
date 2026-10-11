<?php

namespace App\Models;

use App\Enums\ReservationApprovalStatus;
use App\Enums\ReservationStatus;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code',
    'lead_id',
    'property_id',
    'operation_type',
    'channel',
    'separation_amount',
    'currency',
    'user_id',
    'supervisor_user_id',
    'branch',
    'priority',
    'requires_approval',
    'approval_status',
    'approved_by_user_id',
    'approved_at',
    'reservation_reason',
    'payment_method',
    'payment_bank',
    'payment_operation',
    'payment_date',
    'amount_paid',
    'payment_status',
    'payment_observation',
    'documents_json',
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
        'currency' => 'PEN',
        'priority' => 'NORMAL',
        'requires_approval' => true,
        'approval_status' => 'PENDING',
        'payment_status' => 'PENDING',
    ];

    protected function casts(): array
    {
        return [
            'separation_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'requires_approval' => 'boolean',
            'reserved_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'payment_date' => 'datetime',
            'documents_json' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
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

    public function approvalStatusLabel(): string
    {
        return ReservationApprovalStatus::tryFrom($this->approval_status)?->label() ?? $this->approval_status;
    }

    public function codeLabel(): string
    {
        return $this->code ?: 'RES-'.str_pad((string) $this->getKey(), 5, '0', STR_PAD_LEFT);
    }
}
