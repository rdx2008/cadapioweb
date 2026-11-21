<?php

namespace App\Http\Controllers;

use App\Services\DeliveryRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryAlertController extends Controller
{
    public function live(Request $request): JsonResponse
    {
        $query = DB::table('delivery_incidents')->where('tenant_id', $request->user()->tenant_id);
        $latest = (clone $query)->max('id') ?? 0;
        $open = (clone $query)->where('status', 'open');
        $items = (clone $open)->orderByDesc('id')->limit(5)->get(['id', 'ride_id', 'kind'])->map(fn ($item) => ['id' => $item->id, 'ride_id' => $item->ride_id, 'label' => DeliveryRecords::KINDS[$item->kind] ?? 'Ocorrência', 'url' => route('deliveries.show', $item->ride_id)]);

        return response()->json(['latest_id' => (int) $latest, 'open_count' => $open->count(), 'items' => $items])->header('Cache-Control', 'private, no-store');
    }
}
