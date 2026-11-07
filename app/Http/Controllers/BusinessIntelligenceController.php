<?php

namespace App\Http\Controllers;

use App\Services\BusinessIntelligence;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessIntelligenceController extends Controller
{
    private function data(Request $request): array
    {
        $platform = $request->user()->role === 'admin';
        abort_unless($platform || $request->user()->role === 'owner', 403);
        $filters = $request->validate(['start' => 'nullable|date_format:Y-m-d', 'end' => 'nullable|date_format:Y-m-d', 'tenant_id' => 'nullable|integer|min:1', 'store_id' => 'nullable|integer|min:1', 'timezone' => ['nullable', Rule::in(timezone_identifiers_list())]]);
        $tenantId = $platform ? ($filters['tenant_id'] ?? null) : $request->user()->tenant_id;
        if ($tenantId !== null) {
            DB::table('tenants')->where('id', $tenantId)->firstOrFail();
        }
        $stores = DB::table('stores')->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->orderBy('name')->get(['id', 'tenant_id', 'name', 'timezone']);
        $storeId = isset($filters['store_id']) ? (int) $filters['store_id'] : null;
        $store = $storeId ? $stores->firstWhere('id', $storeId) : null;
        abort_if($storeId && ! $store, 404);
        $timezone = $filters['timezone'] ?? $store?->timezone ?? 'America/Sao_Paulo';
        $today = now()->timezone($timezone)->startOfDay();
        $start = isset($filters['start']) ? Carbon::createFromFormat('!Y-m-d', $filters['start'], $timezone) : $today->copy()->subDays(29);
        $end = isset($filters['end']) ? Carbon::createFromFormat('!Y-m-d', $filters['end'], $timezone) : $today->copy();
        if ($start->gt($end) || $end->gt($today) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['start' => 'Escolha um período de até 366 dias, encerrado até hoje.']);
        }
        $filters = ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'timezone' => $timezone, 'tenant_id' => $tenantId, 'store_id' => $storeId];

        return ['report' => app(BusinessIntelligence::class)->report($tenantId, $storeId, $start, $end, $timezone, $platform), 'platform' => $platform, 'filters' => $filters, 'stores' => $stores, 'companies' => $platform ? DB::table('tenants')->orderBy('name')->get(['id', 'name', 'deleted_at']) : collect(), 'biUrl' => $platform ? '/admin/bi' : '/bi'];
    }

    public function index(Request $request): Response
    {
        return response()->view('bi.index', $this->data($request))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->data($request);

        return response()->streamDownload(function () use ($data): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Fuso', $data['filters']['timezone']], ';', '"', '');
            fputcsv($output, ['Data do pedido', 'Pedidos', 'Pedidos pagos', 'Vendas pagas R$', 'Vendas período anterior alinhado R$'], ';', '"', '');
            foreach ($data['report']['daily'] as $day) {
                fputcsv($output, [$day['date'], $day['orders'], $day['paid_orders'], number_format($day['paid_cents'] / 100, 2, ',', ''), number_format($day['previous_cents'] / 100, 2, ',', '')], ';', '"', '');
            }
            fclose($output);
        }, 'bi-'.$data['filters']['start'].'-'.$data['filters']['end'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
