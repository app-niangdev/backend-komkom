<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
    protected $fillable = [
        'logo',
        'name',
        'short_name',
        'slogan',
        'address',
        'email',
        'phone_one',
        'phone_two',
        'in_maintenance',
        'image_size',
    ];

        protected $hidden = [
        'deleted_at',
        'created_at',
        'updated_at'
    ];
}
