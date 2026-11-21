<?php

namespace App\Http\Resources\V1;

use App\Services\DeliveryRecords;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'kind' => $this->kind,
            'kind_label' => DeliveryRecords::KINDS[$this->kind] ?? $this->kind,
            'description' => $this->description,
            'status' => $this->status,
            'has_photo' => $this->photo_path !== null,
            'author_name' => $this->author_name,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
        ];
    }
}
