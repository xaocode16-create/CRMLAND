<?php

namespace App\Enums;

enum PropertyStatus: string
{
    case Available = 'AVAILABLE';
    case Reserved = 'RESERVED';
    case Sold = 'SOLD';
    case Rented = 'RENTED';
    case Inactive = 'INACTIVE';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Reserved => 'Reservado',
            self::Sold => 'Vendido',
            self::Rented => 'Alquilado',
            self::Inactive => 'Inactivo',
        };
    }
}
