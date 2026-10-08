<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Registered = 'REGISTERED';
    case Approved = 'APPROVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registrada',
            self::Approved => 'Aprobada',
            self::Cancelled => 'Cancelada',
        };
    }
}
