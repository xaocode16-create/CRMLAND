<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrador = 'admin';
    case Supervisor = 'supervisor';
    case Asesor = 'asesor';
}
