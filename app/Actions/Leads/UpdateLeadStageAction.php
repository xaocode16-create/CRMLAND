<?php

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\PipelineHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateLeadStageAction
{
    public function handle(Lead $lead, int $statusId, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $statusId, $actor): Lead {
            $previousStatusId = $lead->status_id;

            if ($previousStatusId !== $statusId) {
                PipelineHistory::query()->create([
                    'lead_id' => $lead->getKey(),
                    'from_stage_id' => $previousStatusId,
                    'to_stage_id' => $statusId,
                    'changed_by_user_id' => $actor->getKey(),
                    'metadata' => ['trigger' => 'pipeline'],
                ]);
            }

            $lead->forceFill(['status_id' => $statusId])->save();

            return $lead->refresh();
        });
    }
}
