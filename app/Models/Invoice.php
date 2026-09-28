<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'invoice_status',
        'sale_id',
        'store_id',
        'customer_id',
        'customer_name',
        'balance',
        'amount_paid',
        'amount_total',
        'cancelled_at',
        'cancelled_by',
        'is_cancelled',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'cancelled_at' => 'datetime',
        'is_cancelled' => 'boolean',
    ];

    // Méthode pour annuler une facture
    public function markAsCancelled($userId = null)
    {
        $this->invoice_status = 'cancelled';
        $this->is_cancelled = true;
        $this->cancelled_at = now();
        $this->cancelled_by = $userId;
        $this->save();
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentReceipts()
    {
        return $this->hasMany(PaymentReceipt::class, 'invoice_id');
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            $invoice->invoice_number = self::generateInvoiceNumber();
        });
    }

    public static function generateInvoiceNumber()
    {
        $fac = 'FAC';

        // Tri par longueur puis valeur : « FAC10000 » passe après « FAC9999 » ; les factures supprimées comptent aussi
        $lastInvoice = self::withTrashed()
                            ->where('invoice_number', 'LIKE', "$fac%")
                            ->orderByRaw('LENGTH(invoice_number) DESC, invoice_number DESC')
                            ->first();

        // Extraire le dernier numéro et l'incrémenter
        if ($lastInvoice) {
            preg_match('/FAC(\d+)$/', $lastInvoice->invoice_number, $matches);
            $num = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
        } else {
            $num = 1;
        }

        // Formatage du numéro avec trois chiffres (ex: FAC001, FAC002...)
        $numFormatted = str_pad($num, 4, '0', STR_PAD_LEFT);

        return "$fac$numFormatted";
    }

    protected $hidden = [
        // 'created_at',
        'updated_at',
        'deleted_at'
    ];
}
