<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'slogan' => $this->slogan,
            'address' => $this->address,
            'phone_one' => $this->phone_one,
            'phone_two' => $this->phone_two,
            'phone_three' => $this->phone_three,
            'email' => $this->email,
            'active' => $this->active,
            'uses_measurements' => $this->uses_measurements,
            'use_company_logo' => $this->use_company_logo,
            'use_company_colors' => $this->use_company_colors,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'logo_url' => $this->logo_url,
            'effective_primary_color' => $this->effective_primary_color,
            'effective_secondary_color' => $this->effective_secondary_color,
            'nb_products' => count($this->products),
            'nb_sales' => count($this->sales),
            'nb_managers' => count($this->managers),
            'managers' => $this->managers,
            'nb_suppliers' => count($this->supplierproducts)
        ];
    }
}
