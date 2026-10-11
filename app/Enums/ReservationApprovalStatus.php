<?php

namespace App\Enums;

enum ReservationApprovalStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de aprobacion',
            self::Approved => 'Aprobada',
            self::Rejected => 'Rechazada',
        };
    }
}
