<?php

namespace App\Enums;

enum FollowUpType: string
{
    case Call = 'CALL';
    case Whatsapp = 'WHATSAPP';
    case Email = 'EMAIL';
    case Visit = 'VISIT';
    case Meeting = 'MEETING';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Llamada',
            self::Whatsapp => 'WhatsApp',
            self::Email => 'Email',
            self::Visit => 'Visita',
            self::Meeting => 'Reunion',
        };
    }
}
