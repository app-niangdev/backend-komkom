<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'date' => $this->created_at,
            'invoice_status' => $this->invoice_status,
            'sale_id' => $this->sale_id,
            'balance' => $this->balance,
            'amount_paid' => $this->amount_paid,
            'amount_total'=> $this->amount_total,
            'customer' => $this->customer ?? (object)[
                'name' => 'Client Anonyme'
            ],
            'payment_receipts'=> $this->paymentReceipts
        ];
    }
}
