<?php

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateLeadAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): Lead
    {
        return DB::transaction(function () use ($data, $actor): Lead {
            if ($actor->isAsesor() && empty($data['assigned_user_id'])) {
                $data['assigned_user_id'] = $actor->getKey();
            }

            $data['full_name'] = trim((string) $data['first_name'].' '.(string) ($data['last_name'] ?? ''));
            $data['entered_at'] = $data['entered_at'] ?? now();

            /** @var Lead $lead */
            $lead = Lead::query()->create($data);

            LeadActivity::query()->create([
                'lead_id' => $lead->getKey(),
                'user_id' => $actor->getKey(),
                'type' => 'LEAD_CREATED',
                'title' => 'Lead creado',
                'description' => 'Lead registrado en el sistema.',
                'metadata' => [
                    'source_id' => $lead->source_id,
                    'status_id' => $lead->status_id,
                ],
            ]);

            return $lead;
        });
    }
}
