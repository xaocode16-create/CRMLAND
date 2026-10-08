<?php

namespace App\Models;

use Database\Factories\PipelineHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'from_stage_id', 'to_stage_id', 'changed_by_user_id', 'metadata'])]
class PipelineHistory extends Model
{
    /** @use HasFactory<PipelineHistoryFactory> */
    use HasFactory;

    protected $table = 'pipeline_history';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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
     * @return BelongsTo<LeadStatus, $this>
     */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'from_stage_id');
    }

    /**
     * @return BelongsTo<LeadStatus, $this>
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'to_stage_id');
    }
}
