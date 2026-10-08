<?php

namespace App\Models;

use Database\Factories\PropertyUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'property_id',
    'unit_number',
    'unit_type',
    'tower',
    'floor',
    'area_m2',
    'built_area_m2',
    'bedrooms',
    'bathrooms',
    'parking_spaces',
    'price',
    'currency',
    'status',
    'attributes_json',
])]
class PropertyUnit extends Model
{
    /** @use HasFactory<PropertyUnitFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'area_m2' => 'decimal:2',
            'built_area_m2' => 'decimal:2',
            'price' => 'decimal:2',
            'attributes_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
