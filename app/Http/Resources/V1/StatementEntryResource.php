<?php

namespace App\Http\Resources\V1;

use App\Services\DeliveryStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class StatementEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'entry_id' => $this->entry_id,
            'ride_id' => $this->ride_id,
            'order_id' => $this->order_id,
            'store_id' => $this->store_id,
            'store_name' => $this->store_name,
            'kind' => $this->kind,
            'kind_label' => DeliveryStatement::KINDS[$this->kind] ?? $this->kind,
            'occurred_at' => Carbon::parse($this->occurred_at, 'UTC')->toIso8601String(),
            'quoted_cents' => (int) $this->quoted_cents,
            'compensation_cents' => (int) $this->compensation_cents,
            'customer_cash_cents' => (int) $this->customer_cash_cents,
            'settled' => (bool) $this->settled,
        ];
    }
}
