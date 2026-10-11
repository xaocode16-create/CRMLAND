<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Permission;
use App\Models\PropertyType;
use App\Models\Role;
use Illuminate\Database\Seeder;

class CrmCoreSeeder extends Seeder
{
    public function run(): void
    {
        $roles = $this->seedRoles();
        $permissions = $this->seedPermissions();

        $this->syncRolePermissions($roles, $permissions);
        $this->seedLeadCatalogs();
        $this->seedPropertyCatalogs();
    }

    /**
     * @return array<string, Role>
     */
    private function seedRoles(): array
    {
        $roles = [
            UserRole::Administrador->value => [
                'name' => 'Administrador',
                'description' => 'Acceso total a la administracion y configuracion del CRM.',
            ],
            UserRole::Supervisor->value => [
                'name' => 'Supervisor',
                'description' => 'Puede visualizar y gestionar informacion de su equipo comercial.',
            ],
            UserRole::Asesor->value => [
                'name' => 'Asesor',
                'description' => 'Gestiona unicamente la informacion comercial permitida y los clientes asignados.',
            ],
        ];

        return collect($roles)
            ->mapWithKeys(fn (array $data, string $slug): array => [
                $slug => Role::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $data['name'],
                        'description' => $data['description'],
                        'status' => UserStatus::Active->value,
                    ],
                ),
            ])
            ->all();
    }

    /**
     * @return array<string, Permission>
     */
    private function seedPermissions(): array
    {
        $permissions = [
            ['dashboard.view', 'Ver dashboard', 'Dashboard'],
            ['users.view', 'Ver usuarios', 'Usuarios'],
            ['users.create', 'Crear usuarios', 'Usuarios'],
            ['users.edit', 'Editar usuarios', 'Usuarios'],
            ['users.disable', 'Desactivar usuarios', 'Usuarios'],
            ['users.reactivate', 'Reactivar usuarios', 'Usuarios'],
            ['users.reset_password', 'Cambiar contrasena de usuarios', 'Usuarios'],
            ['users.assign_role', 'Asignar rol de usuario', 'Usuarios'],
            ['roles.view', 'Ver roles', 'Roles'],
            ['roles.create', 'Crear roles', 'Roles'],
            ['roles.edit', 'Editar roles', 'Roles'],
            ['roles.delete', 'Eliminar roles', 'Roles'],
            ['permissions.view', 'Ver permisos', 'Permisos'],
            ['leads.view', 'Ver leads', 'Leads'],
            ['leads.create', 'Crear leads', 'Leads'],
            ['leads.edit', 'Editar leads', 'Leads'],
            ['leads.delete', 'Eliminar leads', 'Leads'],
            ['leads.assign', 'Asignar leads', 'Leads'],
            ['properties.view', 'Ver propiedades', 'Propiedades'],
            ['properties.create', 'Crear propiedades', 'Propiedades'],
            ['properties.edit', 'Editar propiedades', 'Propiedades'],
            ['properties.disable', 'Desactivar propiedades', 'Propiedades'],
            ['properties.upload_images', 'Subir fotos de propiedades', 'Propiedades'],
            ['properties.delete_images', 'Eliminar fotos de propiedades', 'Propiedades'],
            ['properties.assign_lead', 'Asociar propiedades con leads', 'Propiedades'],
            ['properties.delete', 'Eliminar propiedades', 'Propiedades'],
            ['pipeline.view', 'Ver pipeline', 'Pipeline'],
            ['pipeline.edit', 'Editar pipeline', 'Pipeline'],
            ['followups.view', 'Ver seguimientos', 'Seguimientos'],
            ['followups.create', 'Crear seguimientos', 'Seguimientos'],
            ['followups.edit', 'Editar seguimientos', 'Seguimientos'],
            ['notes.view', 'Ver notas de leads', 'Notas'],
            ['notes.create', 'Crear notas de leads', 'Notas'],
            ['notes.edit', 'Editar notas de leads', 'Notas'],
            ['notes.delete', 'Eliminar notas de leads', 'Notas'],
            ['appointments.view', 'Ver citas', 'Citas'],
            ['appointments.create', 'Crear citas', 'Citas'],
            ['appointments.edit', 'Editar citas', 'Citas'],
            ['sales.view', 'Ver ventas', 'Ventas'],
            ['sales.create', 'Crear ventas', 'Ventas'],
            ['reports.view', 'Ver reportes', 'Reportes'],
            ['automations.view', 'Ver automatizaciones', 'Automatizaciones'],
            ['automations.edit', 'Editar automatizaciones', 'Automatizaciones'],
            ['settings.view', 'Ver configuracion', 'Configuracion'],
            ['settings.edit', 'Editar configuracion', 'Configuracion'],
            ['notifications.view', 'Ver notificaciones', 'Notificaciones'],
            ['commissions.view', 'Ver comisiones', 'Comisiones'],
            ['commissions.manage', 'Gestionar estados de comisiones', 'Comisiones'],
        ];

        return collect($permissions)
            ->mapWithKeys(fn (array $permission): array => [
                $permission[0] => Permission::query()->updateOrCreate(
                    ['slug' => $permission[0]],
                    [
                        'name' => $permission[1],
                        'description' => $permission[1],
                        'module' => $permission[2],
                    ],
                ),
            ])
            ->all();
    }

    /**
     * @param  array<string, Role>  $roles
     * @param  array<string, Permission>  $permissions
     */
    private function syncRolePermissions(array $roles, array $permissions): void
    {
        $supervisorPermissions = [
            'dashboard.view', 'users.view', 'leads.view', 'leads.edit', 'leads.assign',
            'properties.view', 'properties.create', 'properties.edit', 'properties.disable',
            'properties.upload_images', 'properties.delete_images', 'properties.assign_lead',
            'pipeline.view', 'pipeline.edit', 'followups.view', 'followups.create',
            'followups.edit', 'notes.view', 'notes.create', 'notes.edit', 'notes.delete',
            'appointments.view', 'appointments.create', 'appointments.edit', 'sales.view',
            'sales.create', 'commissions.view', 'commissions.manage', 'reports.view',
            'notifications.view', 'automations.view',
        ];

        $asesorPermissions = [
            'dashboard.view', 'leads.view', 'leads.create', 'leads.edit', 'properties.view',
            'properties.assign_lead', 'pipeline.view', 'pipeline.edit', 'followups.view',
            'followups.create', 'followups.edit', 'notes.view', 'notes.create', 'notes.edit',
            'appointments.view', 'appointments.create', 'appointments.edit', 'sales.view',
            'sales.create', 'commissions.view', 'reports.view', 'notifications.view',
        ];

        $roles[UserRole::Administrador->value]->permissions()->sync(
            collect($permissions)->mapWithKeys(fn (Permission $permission): array => [
                $permission->getKey() => ['created_at' => now()],
            ])->all(),
        );

        $this->syncPermissionSlugs($roles[UserRole::Supervisor->value], $permissions, $supervisorPermissions);
        $this->syncPermissionSlugs($roles[UserRole::Asesor->value], $permissions, $asesorPermissions);
    }

    /**
     * @param  array<string, Permission>  $permissions
     * @param  string[]  $slugs
     */
    private function syncPermissionSlugs(Role $role, array $permissions, array $slugs): void
    {
        $role->permissions()->sync(
            collect($slugs)
                ->filter(fn (string $slug): bool => isset($permissions[$slug]))
                ->mapWithKeys(fn (string $slug): array => [
                    $permissions[$slug]->getKey() => ['created_at' => now()],
                ])
                ->all(),
        );
    }

    private function seedLeadCatalogs(): void
    {
        foreach ([
            ['facebook', 'Facebook'],
            ['instagram', 'Instagram'],
            ['tiktok', 'TikTok'],
            ['whatsapp', 'WhatsApp'],
            ['web', 'Web'],
            ['referral', 'Referido'],
            ['manual', 'Manual'],
        ] as [$slug, $name]) {
            LeadSource::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'status' => UserStatus::Active->value],
            );
        }

        foreach ([
            ['new', 'Nuevo', '#38bdf8', 1],
            ['contacted', 'Contactado', '#34d399', 2],
            ['follow_up', 'En seguimiento', '#fbbf24', 3],
            ['appointment', 'Cita programada', '#a78bfa', 4],
            ['negotiation', 'Negociacion', '#fb923c', 5],
            ['closed_won', 'Cerrado / Ganado', '#22c55e', 6],
            ['lost', 'Perdido', '#f87171', 7],
        ] as [$slug, $name, $color, $sortOrder]) {
            LeadStatus::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'color' => $color,
                    'sort_order' => $sortOrder,
                    'status' => UserStatus::Active->value,
                ],
            );
        }
    }

    private function seedPropertyCatalogs(): void
    {
        foreach ([
            ['casa', 'Casa'],
            ['casa-de-playa', 'Casa de Playa'],
            ['departamento', 'Departamento'],
            ['duplex', 'Duplex'],
            ['terreno', 'Terreno'],
            ['oficina', 'Oficina'],
            ['local-comercial', 'Local comercial'],
            ['otro', 'Otro'],
            ['proyecto-inmobiliario', 'Proyecto inmobiliario'],
        ] as [$slug, $name]) {
            PropertyType::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'status' => UserStatus::Active->value],
            );
        }
    }
}
