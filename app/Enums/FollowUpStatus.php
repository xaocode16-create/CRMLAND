<?php

namespace App\Enums;

enum FollowUpStatus: string
{
    case Pending = 'PENDING';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Completed => 'Completado',
            self::Cancelled => 'Cancelado',
        };
    }
}
