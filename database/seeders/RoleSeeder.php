<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Admin', 'Owner', 'Manager', 'Seller'] as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
    }
}
