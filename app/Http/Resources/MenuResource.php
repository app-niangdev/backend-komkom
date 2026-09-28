<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'type' => $this->type,
            'classes' => $this->classes,
            'url' => $this->url,
            'icon' => $this->icon,
            'breadcrumbs' => $this->breadcrumbs,
            'position' => $this->position,
        ];
    }
}
