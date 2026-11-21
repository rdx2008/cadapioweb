<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderCostReportController extends Controller
{
    private function data(Request $request, Inventory $access): array
    {
        $user = $access->member($request->user());
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1', 'start' => 'nullable|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'end' => 'nullable|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'source' => 'nullable|in:menu,pos', 'coverage' => 'nullable|in:complete,pending', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : $stores->first();
        abort_if(isset($filters['store_id']) && ! $store, 404);
        $timezone = $store?->timezone ?? 'America/Sao_Paulo';
        $today = Carbon::today($timezone);
        $start = isset($filters['start']) ? Carbon::parse($filters['start'], $timezone)->startOfDay() : $today->copy()->subDays(29);
        $end = isset($filters['end']) ? Carbon::parse($filters['end'], $timezone)->startOfDay() : $today->copy();
        if ($start->gt($end) || $end->gt($today) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['start' => 'Escolha até 366 dias, encerrados até hoje.']);
        }
        $movements = DB::table('inventory_movements')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)->where('kind', 'out')->whereNotNull('order_id')
            ->groupBy('order_id')->selectRaw('order_id, COUNT(*) AS movement_count, COUNT(value_cents) AS valued_count, SUM(value_cents) AS cost_cents');
        $orders = DB::table('orders')->leftJoinSub($movements, 'costs', 'costs.order_id', '=', 'orders.id')
            ->where('orders.tenant_id', $user->tenant_id)->where('orders.store_id', $store?->id ?? 0)->whereIn('orders.source', ['menu', 'pos'])
            ->where('orders.status', 'completed')->where('orders.payment_status', 'paid')
            ->where('orders.created_at', '>=', $start->copy()->utc()->format('Y-m-d H:i:s'))->where('orders.created_at', '<', $end->copy()->addDay()->utc()->format('Y-m-d H:i:s'))
            ->when($filters['source'] ?? null, fn (Builder $query, string $source) => $query->where('orders.source', $source))
            ->select('orders.id', 'orders.source', 'orders.created_at', 'costs.cost_cents')
            ->selectRaw('orders.subtotal_cents - orders.discount_cents AS sales_cents')
            ->selectRaw('CASE WHEN orders.inventory_consumed_at IS NOT NULL AND orders.inventory_plan IS NOT NULL AND costs.movement_count > 0 AND costs.movement_count = costs.valued_count THEN 1 ELSE 0 END AS complete');
        $rows = DB::query()->fromSub($orders, 'report')->when($filters['coverage'] ?? null, fn (Builder $query, string $coverage) => $query->where('complete', $coverage === 'complete' ? 1 : 0));
        $metrics = (clone $rows)->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM(complete), 0) AS covered_count, COALESCE(SUM(CASE WHEN complete = 1 THEN sales_cents ELSE 0 END), 0) AS covered_sales_cents, COALESCE(SUM(CASE WHEN complete = 1 THEN cost_cents ELSE 0 END), 0) AS covered_cost_cents, COALESCE(SUM(CASE WHEN complete = 0 THEN sales_cents ELSE 0 END), 0) AS pending_sales_cents')->first();

        return compact('stores', 'store', 'timezone', 'start', 'end', 'filters', 'rows', 'metrics');
    }

    public function index(Request $request, Inventory $access): Response
    {
        $data = $this->data($request, $access);
        $data['orders'] = $data['rows']->orderByDesc('created_at')->orderByDesc('id')->paginate(50)->withQueryString();

        return response()->view('bi.order-costs', $data)->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, Inventory $access): StreamedResponse
    {
        $data = $this->data($request, $access);

        return response()->streamDownload(function () use ($data): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Pedido', 'Criado em '.$data['timezone'], 'Canal', 'Produtos após cupons R$', 'Custo dos insumos R$', 'Produtos menos insumos R$', 'Cobertura'], ';', '"', '');
            foreach ($data['rows']->orderBy('id')->lazy(250) as $order) {
                $complete = (int) $order->complete === 1;
                fputcsv($output, [$order->id, Carbon::parse($order->created_at, 'UTC')->timezone($data['timezone'])->format('d/m/Y H:i'), $order->source === 'pos' ? 'PDV' : 'Cardápio', number_format($order->sales_cents / 100, 2, ',', ''), $complete ? number_format($order->cost_cents / 100, 2, ',', '') : '', $complete ? number_format(($order->sales_cents - $order->cost_cents) / 100, 2, ',', '') : '', $complete ? 'Completo' : 'Pendente'], ';', '"', '');
            }
            fclose($output);
        }, 'custos-pedidos-'.$data['start']->format('Ymd').'-'.$data['end']->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
