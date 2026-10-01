<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tentative de relance WhatsApp d'un client débiteur (envoyée ou échouée). */
class CustomerReminder extends Model
{
    protected $fillable = [
        'customer_id',
        'store_id',
        'user_id',
        'amount',
        'invoices_count',
        'phone',
        'status',
        'error',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
