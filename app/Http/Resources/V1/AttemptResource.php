<?php

namespace App\Http\Resources\V1;

use App\Services\DeliveryStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'store_name' => $this->store_name,
            'outcome' => $this->outcome,
            'outcome_label' => DeliveryStatement::KINDS[$this->outcome] ?? $this->outcome,
            'reason' => $this->reason,
            'quoted_fare_cents' => (int) $this->quoted_fare_cents,
            'compensation_cents' => (int) $this->compensation_cents,
            'customer_cash_cents' => (int) $this->customer_cash_cents,
            'settled' => (bool) $this->settled,
            'closed_at' => $this->closed_at,
        ];
    }
}
