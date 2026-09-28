<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'slogan' => $this->slogan,
            'head_office_address' => $this->head_office_address,
            'email' => $this->email,
            'phone_one' => $this->phone_one,
            'phone_two' => $this->phone_two,
            'owner_id' => $this->owner_id,
            // 'logo_url' => "https://images.seeklogo.com/logo-png/40/1/islamic-logo-png_seeklogo-400899.png"
            'logo_url' => $this->logo_url ?? null,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,

        ];
    }
}
