<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public const STATUSES = ['pending' => 'Aguardando aprovação', 'approved' => 'Aprovado', 'blocked' => 'Bloqueado'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'vehicle' => $this->vehicle,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'available' => (bool) $this->available,
            'checkin_store_id' => $this->checkin_store_id,
            'heartbeat_at' => $this->heartbeat_at,
        ];
    }
}
