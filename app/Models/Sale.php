<?php

namespace App\Models;

use App\Enums\SaleStatus;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'lead_id',
    'property_id',
    'advisor_user_id',
    'source_id',
    'pipeline_history_id',
    'price',
    'currency',
    'sold_at',
    'origin',
    'status',
    'notes',
    'created_by',
])]
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'currency' => 'PEN',
        'origin' => 'manual',
        'status' => 'REGISTERED',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sold_at' => 'datetime',
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
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_user_id');
    }

    /**
     * @return HasOne<Commission, $this>
     */
    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }

    /**
     * @param  Builder<Sale>  $query
     * @return Builder<Sale>
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
        return SaleStatus::tryFrom($this->status)?->label() ?? $this->status;
    }

    public function codeLabel(): string
    {
        return 'VNT-'.str_pad((string) $this->getKey(), 3, '0', STR_PAD_LEFT);
    }

    public function priceDisplay(): string
    {
        $symbol = strtoupper((string) $this->currency) === 'USD' ? 'US$' : 'S/';

        return $symbol.' '.number_format((float) $this->price, 2);
    }
}
