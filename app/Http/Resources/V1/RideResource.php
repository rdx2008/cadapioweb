<?php

namespace App\Http\Resources\V1;

use App\Services\CheckoutPayment;
use App\Services\DeliveryRecords;
use App\Services\OnlinePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $next = match ($this->status) {
            'assigned' => 'collected',
            'collected' => 'completed',
            default => null,
        };
        $paymentPending = $this->payment_method !== 'cash' && $this->payment_status !== 'paid';

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'store_id' => $this->store_id,
            'store_name' => $this->store_name,
            'status' => $this->status,
            'status_label' => DeliveryRecords::STATUSES[$this->status] ?? $this->status,
            'dispatch_round' => (int) $this->dispatch_round,
            'next_step' => $next,
            'payment_confirmation_pending' => $next === 'completed' && $paymentPending,
            'customer_name' => $this->customer_name,
            'address' => $this->address,
            'phone' => $this->phone,
            'payment_method' => $this->payment_method,
            'payment_label' => CheckoutPayment::LABELS[$this->payment_method] ?? OnlinePayment::LABELS[$this->payment_method] ?? $this->payment_method,
            'payment_status' => $this->payment_status,
            'total_cents' => (int) $this->total_cents,
            'change_for_cents' => $this->change_for_cents !== null ? (int) $this->change_for_cents : null,
            'fare_cents' => (int) $this->fare_cents,
            'cash_cents' => (int) $this->cash_cents,
            'route_meters' => $this->route_meters !== null ? (int) $this->route_meters : null,
            'settled' => (bool) $this->settled,
            'confirmation_required' => (bool) $this->confirmation_required,
            'confirmation_verified_at' => $this->confirmation_verified_at,
            'received_by' => $this->received_by,
            'proof_recorded_at' => $this->proof_recorded_at,
            'return_reason' => $this->return_reason,
            'return_requested_at' => $this->return_requested_at,
            'completed_at' => $this->completed_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
