<?php

namespace Database\Seeders;

use App\Models\ApplicationSetting;
use Illuminate\Database\Seeder;

class ApplicationSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ApplicationSetting::firstOrCreate(
            ['email' => 'jamonodev@gmail.com'],
            [
                'logo' => null,
                'name' => 'JamonoDév',
                'address' => 'Dakar',
                'short_name' => 'Jamonodev',
                'phone_one' => '771234567',
                'phone_two' => '771234567',
                'slogan'=>'Jamonodev',
                'image_size'=>2048
            ]
        );
    }
}
