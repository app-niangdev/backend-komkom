<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $customer = $this->customer_id ? Customer::find($this->customer_id) : null;
        $user_seller_data = User::findOrFail($this->seller_id);
        $user_seller = $user_seller_data->first_name. " " .$user_seller_data->last_name;

        $data = [
            'id' => $this->id,
            'sale_date' => $this->created_at,
            'total_amount' => $this->total_amount,
            'status' => $this->status,
            'customer' => $customer,
            'user_seller' => $user_seller,
            'discount' => $this->discount,
            'gross_amount'=> $this->gross_amount,
        ];

        return $data;
    }
}
