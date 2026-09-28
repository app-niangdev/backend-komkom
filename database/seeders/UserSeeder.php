<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);

        User::firstOrCreate(
            ['email' => 'niangdev031299@gmail.com'],
            [
                'first_name' => 'Ibrahima',
                'last_name' => 'NIANG',
                'status' => true,
                'role_id' => $adminRole->id,
                'email_verified_at'=> Carbon::now(),
                'type'=>'admin',
                'password' => Hash::make('password'),
                'phone_number_one'=>'771234567',
                'phone_number_two'=>'771234568',
                'address'=>'Bargny',
                'gender'=>'male',
                'active'=>true,
            ]
        );
    }
}
