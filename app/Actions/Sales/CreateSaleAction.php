<?php

namespace App\Actions\Sales;

use App\Enums\CommissionStatus;
use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Enums\SaleStatus;
use App\Models\Commission;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateSaleAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): Sale
    {
        return DB::transaction(function () use ($data, $actor): Sale {
            /** @var Lead $lead */
            $lead = Lead::query()->with('source')->findOrFail((int) $data['lead_id']);
            $property = $this->lockedProperty($data['property_id'] ?? null);
            $advisorUserId = (int) ($data['advisor_user_id'] ?? $lead->assigned_user_id ?? $actor->getKey());
            $price = (float) ($data['price'] ?? $property->price ?? 0);

            if ($price <= 0) {
                throw new \RuntimeException('La venta requiere un precio mayor a cero.');
            }

            if ($property instanceof Property && $property->status === PropertyStatus::Sold->value) {
                throw new \RuntimeException('La propiedad ya fue vendida.');
            }

            if ($property instanceof Property && Sale::query()
                ->where('property_id', $property->getKey())
                ->whereNot('status', SaleStatus::Cancelled->value)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->exists()) {
                throw new \RuntimeException('La propiedad ya tiene una venta activa.');
            }

            /** @var Sale $sale */
            $sale = Sale::query()->create([
                'lead_id' => $lead->getKey(),
                'property_id' => $property?->getKey(),
                'advisor_user_id' => $advisorUserId > 0 ? $advisorUserId : null,
                'source_id' => $lead->source_id,
                'price' => $price,
                'currency' => (string) ($data['currency'] ?? $property->currency ?? 'PEN'),
                'sold_at' => $data['sold_at'] ?? now(),
                'origin' => (string) ($data['origin'] ?? 'manual'),
                'status' => SaleStatus::Registered->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            $percentage = (float) ($data['commission_percentage'] ?? 3);

            Commission::query()->create([
                'sale_id' => $sale->getKey(),
                'advisor_user_id' => $sale->advisor_user_id,
                'percentage' => $percentage,
                'amount' => round($price * ($percentage / 100), 2),
                'currency' => $sale->currency,
                'status' => CommissionStatus::Pending->value,
            ]);

            if ($property instanceof Property) {
                $property->forceFill(['status' => PropertyStatus::Sold->value])->save();

                Reservation::query()
                    ->where('property_id', $property->getKey())
                    ->where('lead_id', $lead->getKey())
                    ->where('status', ReservationStatus::Active->value)
                    ->update(['status' => ReservationStatus::Completed->value]);
            }

            LeadActivity::query()->create([
                'lead_id' => $lead->getKey(),
                'user_id' => $actor->getKey(),
                'type' => 'SALE_REGISTERED',
                'title' => 'Venta registrada',
                'description' => 'Venta registrada por '.$sale->priceDisplay().'.',
                'metadata' => ['sale_id' => $sale->getKey(), 'property_id' => $sale->property_id],
            ]);

            return $sale->refresh();
        });
    }

    private function lockedProperty(mixed $propertyId): ?Property
    {
        $propertyId = (int) ($propertyId ?? 0);

        if ($propertyId <= 0) {
            return null;
        }

        /** @var Property|null $property */
        $property = Property::query()->whereKey($propertyId)->lockForUpdate()->first();

        return $property;
    }
}
