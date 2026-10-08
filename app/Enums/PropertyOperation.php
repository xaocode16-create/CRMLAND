<?php

namespace App\Enums;

enum PropertyOperation: string
{
    case Sale = 'SALE';
    case Rent = 'RENT';
    case Both = 'BOTH';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venta',
            self::Rent => 'Alquiler',
            self::Both => 'Venta y alquiler',
        };
    }
}
