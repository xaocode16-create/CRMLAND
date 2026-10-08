<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Completed => 'Completada',
            self::Cancelled => 'Cancelada',
            self::Expired => 'Vencida',
        };
    }
}
