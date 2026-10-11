<?php

namespace App\Enums;

enum ReportType: string
{
    case Summary = 'resumen';
    case Pipeline = 'etapas';
    case Sources = 'canales';
    case Sales = 'ventas';
    case Commissions = 'comisiones';
    case Properties = 'propiedades';
    case SoldProperties = 'propiedades-vendidas';
    case Leads = 'leads';
    case FollowUps = 'seguimientos';

    public function label(): string
    {
        return match ($this) {
            self::Summary => 'Resumen comercial',
            self::Pipeline => 'Leads por etapa',
            self::Sources => 'Leads por canal',
            self::Sales => 'Ventas',
            self::Commissions => 'Comisiones',
            self::Properties => 'Propiedades registradas',
            self::SoldProperties => 'Propiedades vendidas',
            self::Leads => 'Leads registrados',
            self::FollowUps => 'Seguimientos',
        };
    }

    /** @return list<string> */
    public function headings(): array
    {
        return match ($this) {
            self::Summary => ['Indicador', 'Valor', 'Unidad'],
            self::Pipeline => ['Etapa', 'Leads'],
            self::Sources => ['Canal', 'Leads'],
            self::Sales => ['Fecha', 'Lead', 'Codigo', 'Propiedad', 'Asesor', 'Monto', 'Moneda', 'Estado'],
            self::Commissions => ['Fecha venta', 'Venta', 'Propiedad', 'Asesor', 'Porcentaje', 'Monto', 'Moneda', 'Estado'],
            self::Properties => ['Codigo', 'Propiedad', 'Tipo', 'Distrito', 'Operacion', 'Precio', 'Moneda', 'Estado', 'Asesor'],
            self::SoldProperties => ['Fecha venta', 'Codigo', 'Propiedad', 'Tipo', 'Distrito', 'Precio', 'Moneda', 'Asesor'],
            self::Leads => ['Fecha', 'Lead', 'Origen', 'Etapa', 'Distrito', 'Asesor'],
            self::FollowUps => ['Fecha', 'Lead', 'Tipo', 'Prioridad', 'Estado', 'Asesor'],
        };
    }

    public function note(): ?string
    {
        return $this === self::Summary
            ? 'Leads y ventas corresponden al periodo. Propiedades y pendientes reflejan el estado actual.'
            : null;
    }
}
