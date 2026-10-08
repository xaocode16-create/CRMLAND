<?php

namespace App\Models;

use App\Enums\PropertyOperation;
use App\Enums\PropertyStatus;
use App\Models\Concerns\HasAssignedUserScope;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'title',
    'description',
    'property_type_id',
    'operation_type',
    'region',
    'province',
    'district',
    'address',
    'location_reference',
    'building_name',
    'latitude',
    'longitude',
    'price',
    'currency',
    'area_m2',
    'built_area_m2',
    'bedrooms',
    'bathrooms',
    'parking_spaces',
    'floors',
    'floor_number',
    'year_built',
    'developer',
    'project_status',
    'project_type',
    'total_units',
    'minimum_area',
    'maximum_area',
    'price_from',
    'price_to',
    'project_currency',
    'construction_start',
    'delivery_date',
    'financing',
    'attributes_json',
    'status',
    'owner_name',
    'owner_phone',
    'assigned_user_id',
    'created_by',
])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasAssignedUserScope, HasFactory, SoftDeletes;

    protected $attributes = [
        'operation_type' => 'SALE',
        'currency' => 'PEN',
        'status' => 'AVAILABLE',
    ];

    protected function casts(): array
    {
        return [
            'attributes_json' => 'array',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'price' => 'decimal:2',
            'area_m2' => 'decimal:2',
            'built_area_m2' => 'decimal:2',
            'minimum_area' => 'decimal:2',
            'maximum_area' => 'decimal:2',
            'price_from' => 'decimal:2',
            'price_to' => 'decimal:2',
            'construction_start' => 'date',
            'delivery_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PropertyType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(PropertyType::class, 'property_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PropertyImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    /**
     * @return HasMany<PropertyUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(PropertyUnit::class);
    }

    /**
     * @return BelongsToMany<Lead, $this>
     */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'lead_properties')
            ->withPivot(['relation_type', 'created_by', 'created_at']);
    }

    public function priceDisplay(): string
    {
        $symbol = strtoupper((string) $this->currency) === 'USD' ? 'US$' : 'S/';

        return $symbol.' '.number_format((float) $this->price, 0);
    }

    public function statusLabel(): string
    {
        return PropertyStatus::tryFrom($this->status)?->label() ?? $this->status;
    }

    public function operationLabel(): string
    {
        return PropertyOperation::tryFrom($this->operation_type)?->label() ?? $this->operation_type;
    }
}
