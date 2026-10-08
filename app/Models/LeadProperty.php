<?php

namespace App\Models;

use Database\Factories\LeadPropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['lead_id', 'property_id', 'relation_type', 'created_by'])]
class LeadProperty extends Model
{
    /** @use HasFactory<LeadPropertyFactory> */
    use HasFactory;

    public const UPDATED_AT = null;
}
