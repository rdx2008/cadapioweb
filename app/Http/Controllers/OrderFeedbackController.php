<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\OrderFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderFeedbackController extends Controller
{
    public function submit(Request $request, string $id, OrderFeedback $feedback): RedirectResponse
    {
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:1000']);
        $feedback->submit($id, $data);

        return redirect()->route('orders.tracking', $id)->with('success', 'Obrigado! Sua avaliação foi enviada à loja.');
    }

    public function index(Request $request, Inventory $access): Response
    {
        $user = $access->member($request->user());
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'rating' => 'nullable|integer|min:1|max:5', 'status' => 'nullable|in:pending,treated', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : $stores->first();
        abort_if(isset($filters['store_id']) && ! $store, 404);
        $today = Carbon::now($store?->timezone ?? 'America/Sao_Paulo');
        $from = $filters['from'] ?? $today->copy()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? $today->toDateString();
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'O fim do período deve ser posterior ao início.']);
        }
        $start = Carbon::parse($from, $today->timezone)->startOfDay()->utc();
        $end = Carbon::parse($to, $today->timezone)->addDay()->startOfDay()->utc();
        $base = DB::table('order_feedback')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)->where('created_at', '>=', $start)->where('created_at', '<', $end);
        $metrics = ['count' => (clone $base)->count(), 'average' => (clone $base)->avg('rating'), 'low' => (clone $base)->where('rating', '<=', 2)->count(), 'pending' => (clone $base)->whereNull('treated_at')->count()];
        $feedback = $base->select('order_feedback.*')->selectSub(DB::table('users')->whereColumn('users.id', 'order_feedback.treated_by')->select('name'), 'operator_name')->when(isset($filters['rating']), fn ($query) => $query->where('rating', $filters['rating']))
            ->when(($filters['status'] ?? '') === 'pending', fn ($query) => $query->whereNull('treated_at'))
            ->when(($filters['status'] ?? '') === 'treated', fn ($query) => $query->whereNotNull('treated_at'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return response()->view('customers.feedback', compact('store', 'stores', 'feedback', 'metrics', 'from', 'to'))->header('Cache-Control', 'private, no-store');
    }

    public function treat(Request $request, int $id, Inventory $access, OrderFeedback $feedback): RedirectResponse
    {
        $data = $request->validate(['note' => 'required|string|max:1000', 'confirmed' => 'accepted']);
        $storeId = DB::transaction(function () use ($request, $id, $access, $feedback, $data): int {
            $user = $access->member($request->user(), true);
            $feedback->treat($user, $id, $data['note']);

            return DB::table('order_feedback')->where('id', $id)->value('store_id');
        }, 3);

        return redirect()->route('feedback.index', ['store_id' => $storeId])->with('success', 'Tratamento registrado. A nota do cliente permanece preservada.');
    }
}
