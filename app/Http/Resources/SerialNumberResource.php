<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SerialNumberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product->name,
            'product_description' => $this->product->description,
            'serial_number' => $this->serial_number,
            'is_sold' => $this->is_sold,
            'created_at' => $this->created_at
                ? $this->created_at->locale('fr')->translatedFormat('d F Y à H:i')
                : null,
            'supplier_full_name' => $this->supplyLineItem?->supply?->supplier?->name ?? 'N/A',
            'supplier_contact' => trim(($this->supplyLineItem?->supply?->supplier?->phone_one ?? '') . ' ' . ($this->supplyLineItem?->supply?->supplier?->phone_two ?? '')),
            'supply_line_item_id'  => $this->supply_line_item_id,
            'supply_status' => $this->supplyLineItem?->supply?->status ?? 'N/A',
            'is_available' => $this->is_sold === false && $this->sale_line_item_id === null && ($this->supplyLineItem === null || $this->supplyLineItem->supply->status === 'received'),
        ];
    }
}
