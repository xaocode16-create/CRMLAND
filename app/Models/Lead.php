<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\HasAssignedUserScope;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $first_name
 * @property string|null $last_name
 * @property string $full_name
 * @property string $phone
 * @property string|null $email
 * @property int $source_id
 * @property int $status_id
 * @property int|null $assigned_user_id
 * @property string|null $district
 * @property string|null $property_type
 * @property string|null $budget_currency
 * @property string $status
 * @property Carbon $entered_at
 */
#[Fillable([
    'first_name',
    'last_name',
    'full_name',
    'phone',
    'email',
    'source_id',
    'status_id',
    'assigned_user_id',
    'campaign_id',
    'district',
    'property_type',
    'budget_min',
    'budget_max',
    'budget_currency',
    'notes',
    'entered_at',
    'last_contact_at',
    'next_follow_up_at',
    'status',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasAssignedUserScope, HasFactory, SoftDeletes;

    protected $attributes = [
        'budget_currency' => 'PEN',
        'status' => RecordStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'entered_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<LeadStatus, $this>
     */
    public function pipelineStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    /**
     * @return HasMany<LeadActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    /**
     * @return HasMany<LeadNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }

    /**
     * @return HasMany<FollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /**
     * @return BelongsToMany<Property, $this>
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'lead_properties')
            ->withPivot(['relation_type', 'created_by', 'created_at']);
    }

    public function budgetDisplay(): string
    {
        $currency = strtoupper((string) ($this->budget_currency ?: 'PEN'));
        $symbol = $currency === 'USD' ? 'US$' : 'S/';
        $min = $this->budget_min !== null ? (float) $this->budget_min : null;
        $max = $this->budget_max !== null ? (float) $this->budget_max : null;

        if ($min === null && $max === null) {
            return 'Sin presupuesto';
        }

        if ($min !== null && $max !== null) {
            return $symbol.' '.number_format($min, 0).' - '.$symbol.' '.number_format($max, 0);
        }

        return $symbol.' '.number_format((float) ($min ?? $max), 0);
    }
}
