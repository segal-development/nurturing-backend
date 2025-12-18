<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        DB::transaction(function () {
            $permissions = [
                'ver prospectos', 'crear prospectos', 'editar prospectos', 'eliminar prospectos',
                'ver usuarios', 'crear usuarios', 'editar usuarios', 'eliminar usuarios',
                'ver flujos', 'crear flujos', 'editar flujos', 'eliminar flujos',
                'ver ofertas', 'crear ofertas', 'editar ofertas', 'eliminar ofertas',
                'ver envios', 'crear envios', 'editar envios', 'eliminar envios',
                'gestionar roles', 'gestionar permisos',
            ];

            // Ensure permissions exist
            foreach ($permissions as $name) {
                Permission::firstOrCreate(['name' => $name]);
            }

            // Role: usuario
            $roleUsuario = Role::firstOrCreate(['name' => 'usuario']);
            $roleUsuario->syncPermissions(Permission::whereIn('name', [
                'ver prospectos', 'ver flujos', 'ver ofertas', 'ver envios',
            ])->get());

            // Role: super_admin (all permissions)
            $roleSuperAdmin = Role::firstOrCreate(['name' => 'super_admin']);
            $roleSuperAdmin->syncPermissions(Permission::all());
        });

        // Clear cache again after changes
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
