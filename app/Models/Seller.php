<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Seller extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'store_id',
    ];

    /**
     * Relation avec le modèle User
     * Un manager appartient à un utilisateur.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function company()
    {
        return $this->hasOneThrough(Company::class, Store::class, 'id', 'id', 'store_id', 'company_id');
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

}
