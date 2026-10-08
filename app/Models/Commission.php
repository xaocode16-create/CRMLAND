<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use Database\Factories\CommissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id',
    'advisor_user_id',
    'percentage',
    'amount',
    'currency',
    'status',
    'approved_by_user_id',
    'approved_at',
    'paid_at',
])]
class Commission extends Model
{
    /** @use HasFactory<CommissionFactory> */
    use HasFactory;

    protected $attributes = [
        'currency' => 'PEN',
        'status' => 'PENDING',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:3',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_user_id');
    }

    /**
     * @param  Builder<Commission>  $query
     * @return Builder<Commission>
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

            return $query->whereIn('advisor_user_id', $teamIds);
        }

        return $query->where('advisor_user_id', $user->getKey());
    }

    public function statusLabel(): string
    {
        return CommissionStatus::tryFrom($this->status)?->label() ?? $this->status;
    }

    public function amountDisplay(): string
    {
        $symbol = strtoupper((string) $this->currency) === 'USD' ? 'US$' : 'S/';

        return $symbol.' '.number_format((float) $this->amount, 2);
    }
}
