<?php

namespace App\Actions\Reservations;

use App\Enums\PropertyStatus;
use App\Enums\ReservationApprovalStatus;
use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateReservationAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): Reservation
    {
        return DB::transaction(function () use ($data, $actor): Reservation {
            /** @var Property $property */
            $property = Property::query()
                ->whereKey($data['property_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($property->status !== PropertyStatus::Available->value) {
                throw new \RuntimeException('La propiedad no esta disponible para reserva.');
            }

            $activeReservationExists = Reservation::query()
                ->where('property_id', $property->getKey())
                ->where('status', ReservationStatus::Active->value)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($activeReservationExists) {
                throw new \RuntimeException('La propiedad ya tiene una reserva activa.');
            }

            $advisorUserId = (int) ($data['advisor_user_id'] ?? $actor->getKey());
            abort_unless($actor->canAssignTo($advisorUserId), 403);
            $requiresApproval = ! $actor->isAdministrador() && ! $actor->isSupervisor()
                ? true
                : (bool) ($data['requires_approval'] ?? true);

            /** @var Reservation $reservation */
            $reservation = Reservation::query()->create([
                'lead_id' => $data['lead_id'],
                'property_id' => $property->getKey(),
                'operation_type' => $data['operation_type'] ?? $property->operation_type,
                'channel' => $data['channel'] ?? null,
                'separation_amount' => $data['separation_amount'] ?? 0,
                'currency' => $data['currency'] ?? $property->currency ?? 'PEN',
                'user_id' => $advisorUserId,
                'supervisor_user_id' => $data['supervisor_user_id'] ?? null,
                'branch' => $data['branch'] ?? null,
                'priority' => $data['priority'] ?? 'NORMAL',
                'requires_approval' => $requiresApproval,
                'approval_status' => $requiresApproval
                    ? ReservationApprovalStatus::Pending->value
                    : ReservationApprovalStatus::Approved->value,
                'approved_by_user_id' => $requiresApproval ? null : $actor->getKey(),
                'approved_at' => $requiresApproval ? null : now(),
                'reservation_reason' => $data['reservation_reason'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_bank' => $data['payment_bank'] ?? null,
                'payment_operation' => $data['payment_operation'] ?? null,
                'payment_date' => $data['payment_date'] ?? null,
                'amount_paid' => $data['amount_paid'] ?? 0,
                'payment_status' => $data['payment_status'] ?? 'PENDING',
                'payment_observation' => $data['payment_observation'] ?? null,
                'documents_json' => $data['documents_json'] ?? null,
                'reserved_at' => $data['reserved_at'] ?? now(),
                'expires_at' => $data['expires_at'],
                'status' => ReservationStatus::Active->value,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $reservation->update(['code' => 'RES-'.str_pad((string) $reservation->getKey(), 5, '0', STR_PAD_LEFT)]);

            $property->forceFill(['status' => PropertyStatus::Reserved->value])->save();

            return $reservation;
        });
    }
}
