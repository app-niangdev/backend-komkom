<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'role_id' => $this->role_id,
            'status' => $this->status,
            'current_team_id' => $this->current_team_id,
            'image_path' => $this->image_path,
            'type' => $this->type,
            'full_name' => $this->full_name,
            'image_url' => $this->image_url,
            'role' => [
                'id' => $this->role->id,
                'name' => $this->role->name,
            ],
            'phone_number_one' => $this->phone_number_one,
            'phone_number_two' => $this->phone_number_two,
            'address' => $this->address,
            'gender' => $this->gender,
            'active' => $this->active
        ];
    }
}
