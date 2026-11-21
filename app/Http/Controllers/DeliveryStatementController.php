<?php

namespace App\Http\Controllers;

use App\Services\DeliveryStatement;
use App\Services\TenantState;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryStatementController extends Controller
{
    private function data(Request $request): array
    {
        $user = $request->user();
        abort_unless(($user->access_enabled ?? true) && in_array($user->role, ['owner', 'courier'], true), 403);
        $owner = $user->role === 'owner';
        if ($owner) {
            abort_unless($user->tenant_id && app(TenantState::class)->active($user->tenant_id), 403);
        }
        $filters = $request->validate(['start' => 'nullable|date_format:Y-m-d', 'end' => 'nullable|date_format:Y-m-d', 'timezone' => ['nullable', Rule::in(timezone_identifiers_list())], 'store_id' => 'nullable|integer|min:1', 'driver_id' => 'nullable|integer|min:1', 'settlement' => 'nullable|in:all,pending,settled', 'page' => 'nullable|integer|min:1|max:100000']);
        $tenantId = $owner ? $user->tenant_id : null;
        $driverId = $owner ? (isset($filters['driver_id']) ? (int) $filters['driver_id'] : null) : DB::table('drivers')->where('user_id', $user->id)->firstOrFail()->id;
        $stores = DB::table('stores')->when($owner, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when(! $owner, fn ($q) => $q->where(fn ($activity) => $activity->whereExists(fn ($r) => $r->selectRaw('1')->from('rides')->whereColumn('rides.store_id', 'stores.id')->where('rides.driver_id', $driverId))->orWhereExists(fn ($a) => $a->selectRaw('1')->from('delivery_attempts')->join('rides', 'rides.id', '=', 'delivery_attempts.ride_id')->whereColumn('rides.store_id', 'stores.id')->where('delivery_attempts.driver_id', $driverId))))
            ->orderBy('name')->get(['id', 'name', 'timezone']);
        $storeId = isset($filters['store_id']) ? (int) $filters['store_id'] : null;
        $store = $storeId ? $stores->firstWhere('id', $storeId) : null;
        abort_if($storeId && ! $store, 404);
        $drivers = $owner ? DB::table('drivers')->join('users', 'users.id', '=', 'drivers.user_id')
            ->where(fn ($q) => $q->whereExists(fn ($r) => $r->selectRaw('1')->from('rides')->whereColumn('rides.driver_id', 'drivers.id')->where('rides.tenant_id', $tenantId))->orWhereExists(fn ($a) => $a->selectRaw('1')->from('delivery_attempts')->whereColumn('delivery_attempts.driver_id', 'drivers.id')->where('delivery_attempts.tenant_id', $tenantId)))
            ->orderBy('users.name')->get(['drivers.id', 'users.name']) : collect();
        abort_if($owner && $driverId && ! $drivers->contains('id', $driverId), 404);
        $timezone = $filters['timezone'] ?? $store?->timezone ?? 'America/Sao_Paulo';
        $today = now()->timezone($timezone)->startOfDay();
        $start = isset($filters['start']) ? Carbon::createFromFormat('!Y-m-d', $filters['start'], $timezone) : $today->copy()->subDays(29);
        $end = isset($filters['end']) ? Carbon::createFromFormat('!Y-m-d', $filters['end'], $timezone) : $today->copy();
        if ($start->gt($end) || $end->gt($today) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['start' => 'Escolha até 366 dias, com término até hoje.']);
        }
        $filters = ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'timezone' => $timezone, 'store_id' => $storeId, 'driver_id' => $owner ? $driverId : null, 'settlement' => $filters['settlement'] ?? 'all'];
        $service = app(DeliveryStatement::class);
        $query = $service->query($tenantId, $driverId, $storeId, $start, $end->copy()->addDay(), $filters['settlement']);

        return compact('query', 'filters', 'stores', 'drivers', 'owner');
    }

    public function index(Request $request, DeliveryStatement $service): Response
    {
        $data = $this->data($request);
        $data['totals'] = $service->summary($data['query']);
        $data['entries'] = $service->ordered($data['query'])->paginate(30)->withQueryString();
        unset($data['query']);

        return response()->view('deliveries.statement', $data)->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, DeliveryStatement $service): StreamedResponse
    {
        $data = $this->data($request);
        $query = $service->ordered($data['query']);

        return response()->streamDownload(function () use ($data, $query): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Fuso', $data['filters']['timezone'], 'Período por encerramento', $data['filters']['start'], $data['filters']['end']], ';', '"', '');
            fputcsv($output, ['Data', 'Entrega', 'Pedido', 'Registro', 'Tipo', 'Loja', 'Motoboy', 'Proposta original R$', 'Remuneração R$', 'Dinheiro do cliente R$', 'Referência do dinheiro', 'Acerto atual'], ';', '"', '');
            foreach ($query->lazy(500) as $entry) {
                $safe = fn (string $text): string => preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $text) ? "'".$text : $text;
                fputcsv($output, [Carbon::parse($entry->occurred_at, 'UTC')->timezone($data['filters']['timezone'])->format('d/m/Y H:i:s'), $entry->ride_id, $entry->order_id, $entry->entry_id, DeliveryStatement::KINDS[$entry->kind] ?? $entry->kind, $safe($entry->store_name), $safe($entry->driver_name), number_format($entry->quoted_cents / 100, 2, ',', ''), number_format($entry->compensation_cents / 100, 2, ',', ''), number_format($entry->customer_cash_cents / 100, 2, ',', ''), $entry->kind === 'completed' ? 'Numerário previsto da corrida concluída' : 'Dinheiro declarado na tentativa; conferir repasse', $entry->settled ? 'Confirmado' : 'Pendente'], ';', '"', '');
            }
            fclose($output);
        }, 'extrato-entregas-'.$data['filters']['start'].'-'.$data['filters']['end'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
