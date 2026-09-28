<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductAvailableResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'alert_threshold' => $this->alert_threshold,
            'image' => $this->getFirstMediaUrl('image'),
            'require_serial_number' => $this->require_serial_number,
            'base_unit' => $this->base_unit,
            'base_unit_quantity' => $this->base_unit_quantity,
            'unit_of_measures' => $this->unitOfMeasures,
            'serial_numbers' => $this->serialNumbers
                ->values()
                ->map(function ($serial) {
                return [
                    'id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                ];
            }),
        ];
    }
}

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
