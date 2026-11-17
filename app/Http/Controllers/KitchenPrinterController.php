<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\TenantState;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KitchenPrinterController extends Controller
{
    public function index(Request $request, int $storeId, Inventory $access): Response
    {
        $user = $access->member($request->user());
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $storeId)->firstOrFail();
        $jobs = DB::table('kitchen_print_jobs')->where('store_id', $storeId)
            ->select('id', 'order_id', 'station', 'status', 'created_at', 'claimed_at')->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return response()->view('kitchen.printer', compact('store', 'jobs'))->header('Cache-Control', 'private, no-store');
    }

    public function configure(Request $request, int $storeId, Inventory $access): RedirectResponse
    {
        $data = $request->validate(['action' => 'required|in:activate,disable']);
        $token = $data['action'] === 'activate' ? Str::random(64) : null;
        DB::transaction(function () use ($request, $storeId, $access, $token): void {
            $user = $access->member($request->user(), true);
            $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $storeId)->lockForUpdate()->firstOrFail();
            DB::table('stores')->where('id', $storeId)->update(['kitchen_printer_token_hash' => $token ? hash('sha256', $token) : null]);
            if (! $token) {
                DB::table('kitchen_print_jobs')->where('store_id', $storeId)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            Audit::record($token ? 'kitchen.printer_activated' : 'kitchen.printer_disabled', ['store_id' => $store->id], $user->tenant_id);
        }, 3);

        return redirect()->route('kitchen.printer', $storeId)->with('printer_token', $token)
            ->with('success', $token ? 'Chave gerada. Configure o agente local.' : 'Impressão automática desativada.');
    }

    private function authenticatedStore(Request $request): object
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/^[a-zA-Z0-9]{64}$/D', $token), 401);
        $hash = hash('sha256', $token);
        $store = DB::table('stores')->where('kitchen_printer_token_hash', $hash)->first();
        abort_unless($store, 401);
        DB::table('tenants')->where('id', $store->tenant_id)->lockForUpdate()->firstOrFail();
        abort_unless(app(TenantState::class)->active($store->tenant_id), 403);
        $current = DB::table('stores')->where('id', $store->id)->lockForUpdate()->firstOrFail();
        abort_unless(hash_equals($hash, $current->kitchen_printer_token_hash ?? ''), 401);

        return $current;
    }

    public function claim(Request $request): JsonResponse
    {
        $job = DB::transaction(function () use ($request): ?object {
            $store = $this->authenticatedStore($request);
            $query = DB::table('kitchen_print_jobs')->where('store_id', $store->id)->where('status', 'pending');
            (clone $query)->whereIn('order_id', DB::table('orders')->where('store_id', $store->id)->where('status', 'cancelled')->select('id'))
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
            $job = $query->orderBy('created_at')->orderBy('order_id')->orderBy('sequence')->first(['id', 'station', 'ticket']);
            if ($job) {
                DB::table('kitchen_print_jobs')->where('id', $job->id)->update(['status' => 'claimed', 'claimed_at' => now(), 'updated_at' => now()]);
            }

            return $job;
        }, 3);

        return response()->json(['job' => $job])->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:submitted,failed']);
        DB::transaction(function () use ($request, $id, $data): void {
            $store = $this->authenticatedStore($request);
            $job = DB::table('kitchen_print_jobs')->where('store_id', $store->id)->where('id', $id)->firstOrFail();
            abort_unless(in_array($job->status, ['claimed', $data['status']], true), 409);
            if ($job->status === 'claimed') {
                DB::table('kitchen_print_jobs')->where('id', $id)->update($data + ['updated_at' => now()]);
            }
        }, 3);

        return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
    }
}
