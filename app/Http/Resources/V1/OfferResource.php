<?php

namespace App\Http\Resources\V1;

use App\Services\CheckoutPayment;
use App\Services\OnlinePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'kind' => $this->ride_status === 'offered' ? 'exclusive' : 'broadcast',
            'expires_at' => Carbon::parse($this->expires_at)->toIso8601String(),
            'store_name' => $this->name,
            'pickup_address' => $this->address,
            'fare_cents' => (int) $this->fare_cents,
            'route_meters' => $this->route_meters !== null ? (int) $this->route_meters : null,
            'cash_cents' => (int) $this->cash_cents,
            'payment_method' => $this->payment_method,
            'payment_label' => CheckoutPayment::LABELS[$this->payment_method] ?? OnlinePayment::LABELS[$this->payment_method] ?? $this->payment_method,
            'requires_card_machine' => $this->payment_method === 'card',
        ];
    }
}
