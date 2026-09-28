<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentReceipt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'date',
        'amount',
        'invoice_id',
        'payment_type',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
    /**
     * Get the user who processed the payment.
     * Add this relationship to fix the error.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
