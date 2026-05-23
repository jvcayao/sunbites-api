<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private array $rolePermissions = [
        'admin' => [
            'access.any_branch',
            'branch.switch',
            'dashboard.view',
            'pos.process_orders',
            'pos.apply_discounts',
            'pos.bypass_restrictions',
            'pos.void_transaction',
            'students.enroll',
            'students.wallet_topup',
            'students.approve_link_requests',
            'menu.manage',
            'menu_category.manage',
            'users.manage',
            'branch_assignment.manage',
            'reports.view_sales',
            'reports.export',
            'reports.view_wallet',
            'inventory.manage',
            'references.manage',
        ],
        'manager' => [
            'branch.switch',
            'dashboard.view',
            'pos.process_orders',
            'pos.apply_discounts',
            'pos.void_transaction',
            'students.enroll',
            'students.wallet_topup',
            'students.approve_link_requests',
            'menu.manage',
            'menu_category.manage',
            'reports.view_sales',
            'reports.export',
            'inventory.manage',
            'references.manage',
        ],
        'supervisor' => [
            'branch.switch',
            'dashboard.view',
            'pos.process_orders',
            'pos.void_transaction',
            'students.enroll',
            'students.wallet_topup',
            'students.approve_link_requests',
            'reports.view_sales',
            'inventory.manage',
        ],
        'cashier' => [
            'pos.process_orders',
        ],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        collect($this->rolePermissions)
            ->flatten()
            ->unique()
            ->each(fn (string $permission) => Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));

        foreach ($this->rolePermissions as $roleName => $permissions) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])
                ->syncPermissions($permissions);
        }
    }
}
