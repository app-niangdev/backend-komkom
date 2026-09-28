<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplierproduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'phone_one',
        'phone_two',
        'email',
        'store_id',
    ];

    public function supplies()
    {
        return $this->hasMany(Supply::class, 'supplier_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];
}
