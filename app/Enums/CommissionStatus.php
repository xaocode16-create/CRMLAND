<?php

namespace App\Enums;

enum CommissionStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Paid = 'PAID';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Approved => 'Aprobada',
            self::Paid => 'Pagada',
            self::Cancelled => 'Cancelada',
        };
    }
}
