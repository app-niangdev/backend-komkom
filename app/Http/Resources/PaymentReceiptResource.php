<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'amount' => $this->amount,
            'payment_type' => $this->payment_type,
            'created_at' => $this->created_at,
            'invoice' => $this->invoice ? [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'invoice_status' => $this->invoice->invoice_status,
                'amount_total' => $this->invoice->amount_total,
                'amount_paid' => $this->invoice->amount_paid,
                'balance' => $this->invoice->balance,
            ] : null,
            'customer' => $this->resolveCustomerName(),
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => trim(($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? '')) ?: $this->user->email,
            ] : null,
        ];
    }

    private function resolveCustomerName(): string
    {
        $invoice = $this->invoice;

        if (!$invoice) {
            return 'Client Anonyme';
        }

        return $invoice->customer->name
            ?? $invoice->customer_name
            ?? $invoice->sale?->customer?->name
            ?? 'Client Anonyme';
    }
}
