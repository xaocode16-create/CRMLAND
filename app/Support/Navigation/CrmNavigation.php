<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

final class CrmNavigation
{
    /**
     * @return array<string, array<int, array{label: string, icon: string, href: string, active: string}>>
     */
    public function groupsFor(User $user): array
    {
        $groups = [
            'Comercial' => $this->filterItems([
                $this->item($user, 'Panel', 'bi-speedometer2', 'dashboard', 'dashboard.view'),
                $this->item($user, $user->isAsesor() ? 'Mis Leads' : 'Leads', 'bi-people', 'leads.index', 'leads.view', 'leads.*'),
                $this->item($user, 'Pipeline', 'bi-kanban', 'pipeline.index', 'pipeline.view'),
                $this->item($user, 'Propiedades', 'bi-buildings', 'properties.index', 'properties.view', 'properties.*'),
                $this->item($user, 'Seguimientos', 'bi-clipboard-check', 'follow-ups.index', 'followups.view', 'follow-ups.*'),
                $this->item($user, 'Calendario', 'bi-calendar3', 'calendar.index', 'appointments.view'),
            ]),
            'Operaciones' => $this->filterItems([
                $this->item($user, 'Reservas', 'bi-bookmark-star', 'reservations.index', 'appointments.view'),
                $this->item($user, 'Ventas', 'bi-cash-stack', 'sales.index', 'sales.view', 'sales.*'),
                $this->item($user, $user->isAsesor() ? 'Mis Comisiones' : 'Comisiones', 'bi-wallet2', 'commissions.index', 'commissions.view'),
                $this->item($user, $user->isAsesor() ? 'Mis Reportes' : 'Reportes', 'bi-bar-chart', 'reports.index', 'reports.view'),
            ]),
        ];

        if ($user->isAdministrador()) {
            $groups['Administración'] = $this->filterItems([
                $this->item($user, 'Usuarios', 'bi-person-gear', 'users.index', 'users.view'),
                $this->item($user, 'Roles', 'bi-key', 'roles.index', 'roles.view'),
                $this->item($user, 'Permisos', 'bi-shield-check', 'permissions.index', 'permissions.view'),
                $this->item($user, 'Configuración global', 'bi-gear', 'settings.index', 'settings.view'),
            ]);
        } elseif ($user->isSupervisor()) {
            $groups['Gestion de equipo'] = $this->filterItems([
                $this->item($user, 'Usuarios', 'bi-person-gear', 'users.index', 'users.view'),
            ]);
        }

        $groups['Mi cuenta'] = $this->accountItemsFor($user);

        return array_filter($groups);
    }

    /**
     * @return array<int, array{label: string, icon: string, href: string, active: string}>
     */
    public function accountItemsFor(User $user): array
    {
        return $this->filterItems([
            $this->item($user, 'Notificaciones', 'bi-bell', 'notifications.index', 'notifications.view'),
            $this->item($user, 'Perfil', 'bi-person-circle', 'profile.edit'),
            $this->item($user, 'Asistente IA', 'bi-stars', 'assistant', 'dashboard.view'),
            $this->item($user, 'Preferencias', 'bi-sliders', 'appearance.edit'),
            $this->item($user, 'Seguridad', 'bi-shield-lock', 'security.edit'),
        ]);
    }

    /**
     * @param  array<int, array{label: string, icon: string, href: string, active: string}|null>  $items
     * @return array<int, array{label: string, icon: string, href: string, active: string}>
     */
    private function filterItems(array $items): array
    {
        return array_values(array_filter($items));
    }

    /**
     * @return array{label: string, icon: string, href: string, active: string}|null
     */
    private function item(
        User $user,
        string $label,
        string $icon,
        string $routeName,
        ?string $permission = null,
        ?string $active = null,
    ): ?array {
        if (! Route::has($routeName) || ($permission !== null && ! Gate::forUser($user)->allows($permission))) {
            return null;
        }

        return [
            'label' => $label,
            'icon' => $icon,
            'href' => route($routeName),
            'active' => $active ?? $routeName,
        ];
    }
}
