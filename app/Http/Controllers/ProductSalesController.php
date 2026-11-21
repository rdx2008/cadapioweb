<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductSalesController extends Controller
{
    private function data(Request $request, Inventory $access): array
    {
        $user = $access->member($request->user());
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1', 'start' => 'nullable|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'end' => 'nullable|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : $stores->first();
        abort_if(isset($filters['store_id']) && ! $store, 404);
        $today = Carbon::now($store?->timezone ?? 'America/Sao_Paulo')->startOfDay();
        $start = isset($filters['start']) ? Carbon::parse($filters['start'], $today->timezone)->startOfDay() : $today->copy()->subDays(29);
        $end = isset($filters['end']) ? Carbon::parse($filters['end'], $today->timezone)->startOfDay() : $today->copy();
        if ($start->gt($end) || $end->gt($today) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['start' => 'Escolha até 366 dias, encerrados até hoje.']);
        }
        $orders = DB::table('orders')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)->whereIn('source', ['menu', 'pos'])->where('payment_status', 'paid')->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $start->copy()->utc()->format('Y-m-d H:i:s'))->where('created_at', '<', $end->copy()->addDay()->utc()->format('Y-m-d H:i:s'));
        $rows = DB::table('order_items')->whereIn('order_id', $orders->select('id'))->selectRaw('name, SUM(quantity) AS units, SUM(quantity * unit_cents) AS revenue_cents')->groupBy('name')->orderByDesc('revenue_cents')->orderBy('name')->limit(5001)->get();
        abort_if($rows->count() > 5000, 422, 'Mais de 5.000 nomes de produtos neste período. Reduza o período para gerar o relatório.');
        $total = (int) $rows->sum('revenue_cents');
        $units = (int) $rows->sum('units');
        $cumulative = 0;
        $classes = ['A' => 0, 'B' => 0, 'C' => 0];
        foreach ($rows as $row) {
            $row->revenue_cents = (int) $row->revenue_cents;
            $row->units = (int) $row->units;
            $row->class = $row->revenue_cents === 0 || $total === 0 ? 'C' : ($cumulative * 100 < $total * 80 ? 'A' : ($cumulative * 100 < $total * 95 ? 'B' : 'C'));
            $cumulative += $row->revenue_cents;
            $row->share = $total ? round(100 * $row->revenue_cents / $total, 2) : 0;
            $row->cumulative = $total ? round(100 * $cumulative / $total, 2) : 0;
            $classes[$row->class]++;
        }

        return compact('stores', 'store', 'start', 'end', 'rows', 'total', 'units', 'classes');
    }

    public function index(Request $request, Inventory $access): Response
    {
        $data = $this->data($request, $access);
        $page = (int) $request->query('page', 1);
        $data['products'] = (new LengthAwarePaginator($data['rows']->forPage($page, 50)->values(), $data['rows']->count(), 50, $page, ['path' => $request->url()]))->withQueryString();

        return response()->view('bi.products', $data)->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, Inventory $access): StreamedResponse
    {
        $data = $this->data($request, $access);

        return response()->streamDownload(function () use ($data): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Nome registrado no pedido', 'Unidades', 'Valor dos itens antes de cupons R$', 'Participação %', 'Acumulado %', 'Classe ABC'], ';', '"', '');
            foreach ($data['rows'] as $row) {
                $name = preg_match('/^\s*[=+@-]/u', $row->name) ? "'".$row->name : $row->name;
                fputcsv($output, [$name, $row->units, number_format($row->revenue_cents / 100, 2, ',', ''), number_format($row->share, 2, ',', ''), number_format($row->cumulative, 2, ',', ''), $row->class], ';', '"', '');
            }
            fclose($output);
        }, 'curva-abc-'.$data['start']->format('Ymd').'-'.$data['end']->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
