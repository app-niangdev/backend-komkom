<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'alert_threshold' => $this->alert_threshold,
            'image' => $this->getFirstMediaUrl('image'),
            // 'image' => 'https://i.pinimg.com/736x/31/51/3c/31513c813d803e41eaccc6e1a9861d59.jpg',
            "require_serial_number" => $this->require_serial_number,
            "base_unit" => $this->base_unit,
            "base_unit_quantity" => $this->base_unit_quantity,
            "unit_of_measures" => $this->unitOfMeasures,
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
