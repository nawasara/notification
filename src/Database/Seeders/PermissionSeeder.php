<?php

namespace Nawasara\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'notification.template.view',
            'notification.template.create',
            'notification.template.update',
            'notification.template.delete',
            'notification.log.view',
            'notification.log.retry',
            'notification.test.send',
            'notification.broadcast.send',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'developer')->first();
        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
}
