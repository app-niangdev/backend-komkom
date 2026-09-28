<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplyResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'store' => $this->store ? $this->store->name : null,
            'supplier' => $this->supplier,
            'status' => $this->status,
            'total_amount' => (float) $this->total_amount,
            'created_at' => $this->created_at,
            'user' => $this->user ? $this->user->full_name : null,
            'line_items' => SupplyLineItemResource::collection($this->supplyLineItems)
        ];
    }
}

class SupplyLineItemResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'product' => $this->product,
            'quantity' => $this->formatNumber($this->quantity),
            'purchase_price' => (float) $this->purchase_price,
            'total' => (float) $this->total,
            'serial_numbers' => $this->serialNumbers
                ? $this->serialNumbers->pluck('serial_number')
                : [],
        ];
    }

    private function formatNumber($value)
    {
        return (floor($value) == $value)
            ? (int) $value      // ex : 2.000 → 2
            : (float) $value;   // ex : 2.5   → 2.5
    }
}

