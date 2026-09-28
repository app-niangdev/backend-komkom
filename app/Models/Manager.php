<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Manager extends Model
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

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

}
